<template>
  <section class="terminal-panel" :class="{ 'terminal-panel--embedded': embedded }">
    <p v-if="!embedded" class="muted-text terminal-hint">{{ t("editor.terminalHint") }}</p>

    <p v-if="error" class="error-banner">{{ error }}</p>
    <p v-if="notice" class="notice-banner">{{ notice }}</p>

    <div class="terminal-session-controls" :class="{ 'terminal-session-controls--embedded': embedded }">
      <label
        v-if="openSessions.length > 0"
        class="field-inline terminal-session-picker"
        :class="{ 'terminal-session-picker--embedded': embedded }"
      >
        <span v-if="!embedded">{{ t("editor.terminalSelect") }}</span>
        <select v-model.number="selectedSessionId">
          <option
            v-for="item in openSessions"
            :key="item.terminal_session_id"
            :value="item.terminal_session_id"
          >
            {{ formatSessionLabel(item) }}
          </option>
        </select>
      </label>

      <button class="btn btn-sm btn-secondary" type="button" :disabled="creatingSession" @click="createSession">
        {{ creatingSession ? t("common.saving") : t("editor.terminalCreate") }}
      </button>

      <button
        v-if="openSessions.length > 0"
        class="btn btn-sm btn-secondary"
        type="button"
        :disabled="!selectedSession || connecting"
        @click="toggleConnection"
      >
        {{ connectButtonLabel }}
      </button>

      <button
        v-if="openSessions.length > 0"
        class="btn btn-sm btn-ghost terminal-close-btn"
        type="button"
        :disabled="!selectedSession || closingSession || connecting"
        @click="closeSelected"
      >
        {{ closingSession ? t("common.saving") : t("editor.terminalClose") }}
      </button>
    </div>

    <p v-if="loadingSessions && !embedded" class="muted-text">{{ t("editor.terminalLoading") }}</p>

    <div v-if="selectedSession" class="terminal-session-meta">
      <small><strong>{{ t("editor.terminalCwd") }}:</strong> <code>{{ selectedSession.cwd || "/" }}</code></small>
      <small><strong>{{ t("editor.terminalShell") }}:</strong> <code>{{ selectedSession.shell || "-" }}</code></small>
      <small>{{ selectedSession.shared ? t("editor.terminalShared") : t("editor.terminalPrivate") }}</small>
      <small>{{ selectedSession.status === "open" ? t("editor.terminalStatusOpen") : t("editor.terminalStatusClosed") }}</small>
    </div>

    <div ref="terminalHost" class="terminal-host" />
  </section>
</template>

<script setup>
import { computed, defineProps, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Terminal } from "@xterm/xterm";
import { FitAddon } from "@xterm/addon-fit";
import "@xterm/xterm/css/xterm.css";
import {
  closeTerminalSession,
  createTerminalSession,
  issueTerminalTicket,
  listTerminalSessions,
} from "../services/terminal";

const props = defineProps({
  projectId: {
    type: [String, Number],
    default: "",
  },
  embedded: {
    type: Boolean,
    default: false,
  },
});

const { t } = useI18n();

const terminalHost = ref(null);
const sessions = ref([]);
const selectedSessionId = ref(0);
const loadingSessions = ref(false);
const creatingSession = ref(false);
const closingSession = ref(false);
const connecting = ref(false);
const connected = ref(false);
const activeSocketSessionId = ref(0);
const error = ref("");
const notice = ref("");

const projectIdValue = computed(() => Number(props.projectId || 0));
const openSessions = computed(() => {
  return sessions.value.filter((item) => String(item?.status || "") === "open");
});
const selectedSession = computed(() => {
  const selectedId = Number(selectedSessionId.value || 0);
  if (!selectedId) {
    return null;
  }

  return openSessions.value.find((item) => Number(item?.terminal_session_id || 0) === selectedId) || null;
});

const connectButtonLabel = computed(() => {
  if (connected.value && activeSocketSessionId.value === Number(selectedSessionId.value || 0)) {
    return t("editor.terminalDisconnect");
  }

  if (connecting.value) {
    return t("editor.terminalConnecting");
  }

  return t("editor.terminalConnect");
});

let term = null;
let fitAddon = null;
let resizeObserver = null;
let dataDisposable = null;
let socket = null;
let refreshTimerId = null;
let pingTimerId = null;
let fitFrameId = null;
let fitRetryTimerId = null;
const SESSION_REFRESH_INTERVAL_MS = 45000;

function cancelScheduledFit() {
  if (typeof window === "undefined") {
    return;
  }

  if (fitFrameId !== null) {
    window.cancelAnimationFrame(fitFrameId);
    fitFrameId = null;
  }

  if (fitRetryTimerId !== null) {
    window.clearTimeout(fitRetryTimerId);
    fitRetryTimerId = null;
  }
}

function readError(value) {
  if (value && typeof value === "object") {
    if (typeof value?.data?.message === "string" && value.data.message.trim() !== "") {
      return value.data.message;
    }

    if (typeof value?.message === "string" && value.message.trim() !== "") {
      return value.message;
    }
  }

  return t("common.requestFailed");
}

function writeSystemLine(message) {
  if (!term) {
    return;
  }

  term.write(`\r\n[terminal] ${message}\r\n`);
}

function parseSocketMessage(raw) {
  try {
    return JSON.parse(String(raw || ""));
  } catch (_error) {
    return null;
  }
}

function stopPing() {
  if (pingTimerId !== null) {
    window.clearInterval(pingTimerId);
    pingTimerId = null;
  }
}

function sendSocketMessage(payload) {
  if (!socket || socket.readyState !== WebSocket.OPEN) {
    return;
  }

  try {
    socket.send(JSON.stringify(payload));
  } catch (_error) {
    // Ignore transient transport errors.
  }
}

function disconnectSocket(reason = "manual") {
  stopPing();

  if (!socket) {
    connected.value = false;
    connecting.value = false;
    activeSocketSessionId.value = 0;
    return;
  }

  const current = socket;
  socket = null;
  connected.value = false;
  connecting.value = false;
  activeSocketSessionId.value = 0;

  try {
    current.close(1000, String(reason || "manual").slice(0, 80));
  } catch (_error) {
    // Ignore close errors.
  }
}

function applySessionSelection() {
  const selectedId = Number(selectedSessionId.value || 0);
  const availableIds = openSessions.value.map((item) => Number(item?.terminal_session_id || 0));

  if (selectedId && availableIds.includes(selectedId)) {
    return;
  }

  selectedSessionId.value = Number(openSessions.value[0]?.terminal_session_id || 0);
}

function formatSessionLabel(item) {
  const name = String(item?.name || "Terminal");
  const isShared = Boolean(item?.shared);

  const visibility = isShared ? t("editor.terminalSharedShort") : t("editor.terminalPrivateShort");
  return `${name} (${visibility})`;
}

function handleSocketPayload(payload) {
  if (!payload || typeof payload !== "object") {
    return;
  }

  const type = String(payload.type || "");

  if (type === "output") {
    if (term) {
      term.write(String(payload.data || ""));
    }
    return;
  }

  if (type === "ready") {
    writeSystemLine(t("editor.terminalConnected"));
    return;
  }

  if (type === "exit") {
    const exitCode = payload.exit_code;
    writeSystemLine(t("editor.terminalExit", { code: exitCode ?? "-" }));
    return;
  }

  if (type === "closed") {
    writeSystemLine(t("editor.terminalClosed"));
    disconnectSocket("remote-close");
    return;
  }

  if (type === "error") {
    const message = String(payload.message || t("common.requestFailed"));
    error.value = message;
    writeSystemLine(`${t("editor.terminalError")}: ${message}`);
  }
}

async function refreshSessions(options = {}) {
  const quiet = Boolean(options.quiet);
  const projectId = Number(projectIdValue.value || 0);

  if (!projectId) {
    sessions.value = [];
    selectedSessionId.value = 0;
    return;
  }

  if (!quiet) {
    loadingSessions.value = true;
  }

  try {
    const response = await listTerminalSessions(projectId);
    sessions.value = Array.isArray(response.data?.sessions) ? response.data.sessions : [];
    applySessionSelection();

    if (connected.value && activeSocketSessionId.value) {
      const activeIsVisible = openSessions.value.some((item) => {
        return Number(item?.terminal_session_id || 0) === Number(activeSocketSessionId.value || 0);
      });

      if (!activeIsVisible) {
        disconnectSocket("session-closed");
        writeSystemLine(t("editor.terminalClosed"));
      }
    }
  } catch (requestError) {
    error.value = readError(requestError);
  } finally {
    loadingSessions.value = false;
  }
}

async function createSession() {
  const projectId = Number(projectIdValue.value || 0);

  if (!projectId || creatingSession.value) {
    return;
  }

  creatingSession.value = true;
  error.value = "";
  notice.value = "";

  try {
    const defaultName = `${t("editor.terminalDefaultName")} ${sessions.value.length + 1}`;
    const response = await createTerminalSession(projectId, {
      name: defaultName,
      cwd: "/",
      shared: false,
    });

    const createdSessionId = Number(response.data?.session?.terminal_session_id || 0);
    await refreshSessions({ quiet: true });

    if (createdSessionId) {
      selectedSessionId.value = createdSessionId;
      await connectSelected();
    }

    notice.value = t("editor.terminalSessionCreated");
  } catch (requestError) {
    error.value = readError(requestError);
  } finally {
    creatingSession.value = false;
  }
}

async function closeSelected() {
  const projectId = Number(projectIdValue.value || 0);
  const session = selectedSession.value;

  if (!projectId || !session || closingSession.value) {
    return;
  }

  closingSession.value = true;
  error.value = "";
  notice.value = "";

  try {
    await closeTerminalSession(projectId, session.terminal_session_id);

    if (activeSocketSessionId.value === Number(session.terminal_session_id || 0)) {
      disconnectSocket("closed-by-user");
    }

    await refreshSessions({ quiet: true });
    notice.value = t("editor.terminalSessionClosed");
  } catch (requestError) {
    error.value = readError(requestError);
  } finally {
    closingSession.value = false;
  }
}

async function connectSelected() {
  const projectId = Number(projectIdValue.value || 0);
  const session = selectedSession.value;

  if (!projectId || !session) {
    return;
  }

  if (String(session.status || "") !== "open") {
    error.value = t("editor.terminalStatusClosed");
    return;
  }

  connecting.value = true;
  error.value = "";
  notice.value = "";

  try {
    fitTerminal();

    const response = await issueTerminalTicket(projectId, session.terminal_session_id);
    const ticket = String(response.data?.ticket || "").trim();
    const wsUrl = String(response.data?.ws_url || "").trim();

    if (!ticket || !wsUrl) {
      throw new Error(t("common.requestFailed"));
    }

    disconnectSocket("switch-session");

    const endpoint = new URL(wsUrl, window.location.origin);
    endpoint.searchParams.set("ticket", ticket);
    endpoint.searchParams.set("cols", String(term?.cols || 100));
    endpoint.searchParams.set("rows", String(term?.rows || 30));

    const ws = new WebSocket(endpoint.toString());
    socket = ws;
    activeSocketSessionId.value = Number(session.terminal_session_id || 0);

    ws.onopen = () => {
      if (socket !== ws) {
        return;
      }

      scheduleTerminalFit();
      connected.value = true;
      connecting.value = false;
      error.value = "";
      writeSystemLine(t("editor.terminalConnected"));

      stopPing();
      pingTimerId = window.setInterval(() => {
        sendSocketMessage({ type: "ping" });
      }, 15000);
    };

    ws.onmessage = (event) => {
      if (socket !== ws) {
        return;
      }

      handleSocketPayload(parseSocketMessage(event.data));
    };

    ws.onerror = () => {
      if (socket !== ws) {
        return;
      }

      error.value = t("editor.terminalConnectionError");
    };

    ws.onclose = () => {
      if (socket !== ws) {
        return;
      }

      connected.value = false;
      connecting.value = false;
      socket = null;
      activeSocketSessionId.value = 0;
      stopPing();
      writeSystemLine(t("editor.terminalDisconnected"));
    };
  } catch (requestError) {
    connecting.value = false;
    error.value = readError(requestError);
  }
}

async function toggleConnection() {
  const selectedId = Number(selectedSessionId.value || 0);

  if (connected.value && activeSocketSessionId.value === selectedId) {
    disconnectSocket("manual-disconnect");
    return;
  }

  await connectSelected();
}

function onTerminalInput(data) {
  if (!data) {
    return;
  }

  sendSocketMessage({
    type: "input",
    data,
  });
}

function fitTerminal() {
  if (!fitAddon) {
    return;
  }

  try {
    fitAddon.fit();
  } catch (_error) {
    return;
  }

  sendSocketMessage({
    type: "resize",
    cols: term?.cols || 100,
    rows: term?.rows || 30,
  });
}

function scheduleTerminalFit() {
  if (typeof window === "undefined") {
    fitTerminal();
    return;
  }

  cancelScheduledFit();
  fitFrameId = window.requestAnimationFrame(() => {
    fitFrameId = null;
    fitTerminal();

    // Stabilize terminal metrics after flex/layout updates.
    fitRetryTimerId = window.setTimeout(() => {
      fitRetryTimerId = null;
      fitTerminal();
    }, 90);
  });
}

function preventTerminalDrop(event) {
  event.preventDefault();
}

function initializeTerminal() {
  if (!terminalHost.value || term) {
    return;
  }

  term = new Terminal({
    cursorBlink: true,
    convertEol: true,
    fontSize: 13,
    fontFamily: "Cascadia Mono, Consolas, monospace",
    scrollback: 3000,
  });

  fitAddon = new FitAddon();
  term.loadAddon(fitAddon);
  term.open(terminalHost.value);
  void nextTick(() => {
    scheduleTerminalFit();
  });

  dataDisposable = term.onData(onTerminalInput);
  writeSystemLine(t("editor.terminalNotConnected"));

  terminalHost.value.addEventListener("dragover", preventTerminalDrop);
  terminalHost.value.addEventListener("drop", preventTerminalDrop);

  if (typeof ResizeObserver !== "undefined") {
    resizeObserver = new ResizeObserver(() => {
      scheduleTerminalFit();
    });
    resizeObserver.observe(terminalHost.value);
  }
}

function destroyTerminal() {
  cancelScheduledFit();

  if (terminalHost.value) {
    terminalHost.value.removeEventListener("dragover", preventTerminalDrop);
    terminalHost.value.removeEventListener("drop", preventTerminalDrop);
  }

  if (resizeObserver) {
    resizeObserver.disconnect();
    resizeObserver = null;
  }

  if (dataDisposable) {
    dataDisposable.dispose();
    dataDisposable = null;
  }

  if (term) {
    term.dispose();
    term = null;
  }

  fitAddon = null;
}

watch(projectIdValue, (value) => {
  disconnectSocket("project-changed");
  error.value = "";
  notice.value = "";

  if (!value) {
    sessions.value = [];
    selectedSessionId.value = 0;
    return;
  }

  void refreshSessions();
}, { immediate: true });

watch(selectedSessionId, (value) => {
  const nextId = Number(value || 0);
  if (!nextId) {
    return;
  }

  if (connected.value && activeSocketSessionId.value !== nextId) {
    disconnectSocket("session-selection-changed");
  }
});

onMounted(() => {
  initializeTerminal();

  if (typeof window !== "undefined") {
    window.addEventListener("resize", scheduleTerminalFit);

    refreshTimerId = window.setInterval(() => {
      if (projectIdValue.value) {
        void refreshSessions({ quiet: true });
      }
    }, SESSION_REFRESH_INTERVAL_MS);
  }
});

onUnmounted(() => {
  disconnectSocket("component-unmount");

  if (typeof window !== "undefined") {
    window.removeEventListener("resize", scheduleTerminalFit);

    if (refreshTimerId !== null) {
      window.clearInterval(refreshTimerId);
      refreshTimerId = null;
    }
  }

  destroyTerminal();
});
</script>
