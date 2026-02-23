import { computed, nextTick, ref, watch } from "vue";

const LAYOUT_KEY = "livecode.editor.layout";
const SIDEBAR_MIN_WIDTH = 220;
const SIDEBAR_MAX_WIDTH = 640;
const CHAT_MIN_WIDTH = 140;
const CHAT_MAX_WIDTH = 760;
const MAIN_MIN_WIDTH = 460;
const SPLITTER_WIDTH = 18;
const LAYOUT_RATIO_SIDEBAR = 30;
const LAYOUT_RATIO_MAIN = 50;
const LAYOUT_RATIO_CHAT = 10;
const TERMINAL_DOCK_MIN_HEIGHT = 160;
const TERMINAL_DOCK_MAX_HEIGHT = 520;
const TERMINAL_DOCK_DEFAULT_HEIGHT = 280;
const TERMINAL_DOCK_ABSOLUTE_MIN_HEIGHT = 120;
const TERMINAL_DOCK_EDITOR_STAGE_MIN_HEIGHT = 180;
const TERMINAL_DOCK_RESERVED_CHROME_HEIGHT = 26;

export function useEditorLayout(options) {
  const normalizedOptions = options || {};
  const {
    showSidebar,
    canUseProjectFs,
    getEditor,
  } = normalizedOptions;

  const editorLayoutHost = ref(null);
  const editorMainPane = ref(null);
  const editorSidebarPane = ref(null);
  const editorChatPane = ref(null);

  const viewportWidth = ref(typeof window !== "undefined" ? window.innerWidth : 1600);
  const sidebarWidth = ref(420);
  const chatWidth = ref(160);
  const sidebarResized = ref(false);
  const chatResized = ref(false);
  const terminalDockOpen = ref(true);
  const terminalDockHeight = ref(TERMINAL_DOCK_DEFAULT_HEIGHT);
  const terminalDockResized = ref(false);
  const activeResizePane = ref("");

  let editorResizeFrameId = null;
  let paneResizeState = null;
  let terminalDockResizeState = null;

  const canResizeChatPane = computed(() => canUseProjectFs.value && viewportWidth.value > 1260);
  const canResizeSidebarPane = computed(() => {
    if (!showSidebar.value) {
      return false;
    }

    if (canUseProjectFs.value) {
      return viewportWidth.value > 1260;
    }

    return viewportWidth.value > 1040;
  });

  const editorLayoutStyle = computed(() => {
    const style = {};

    if (showSidebar.value) {
      Object.assign(style, {
        "--editor-sidebar-width": `${Math.round(sidebarWidth.value)}px`,
      });
    }

    if (canUseProjectFs.value) {
      Object.assign(style, {
        "--editor-chat-width": `${Math.round(chatWidth.value)}px`,
      });
    }

    return style;
  });

  const terminalDockStyle = computed(() => {
    if (!canUseProjectFs.value || !terminalDockOpen.value) {
      return {};
    }

    return {
      "--editor-terminal-height": `${Math.round(terminalDockHeight.value)}px`,
    };
  });

  function clampNumber(value, min, max) {
    if (!Number.isFinite(value)) {
      return min;
    }

    return Math.min(max, Math.max(min, value));
  }

  function parseCssSize(value) {
    const numeric = Number.parseFloat(String(value || ""));
    return Number.isFinite(numeric) ? numeric : 0;
  }

  function resolveTerminalDockBounds() {
    if (typeof window === "undefined") {
      return {
        minHeight: TERMINAL_DOCK_MIN_HEIGHT,
        maxHeight: TERMINAL_DOCK_MAX_HEIGHT,
      };
    }

    const viewportMax = Math.min(
      TERMINAL_DOCK_MAX_HEIGHT,
      Math.max(TERMINAL_DOCK_ABSOLUTE_MIN_HEIGHT, window.innerHeight - 240),
    );

    let maxHeight = viewportMax;
    const pane = editorMainPane.value;

    if (pane) {
      const paneRect = pane.getBoundingClientRect();
      const paneHeight = Number(paneRect?.height || 0);

      if (paneHeight > 0) {
        const paneStyles = window.getComputedStyle(pane);
        const paddingTop = parseCssSize(paneStyles.paddingTop);
        const paddingBottom = parseCssSize(paneStyles.paddingBottom);
        const rowGap = parseCssSize(paneStyles.rowGap || paneStyles.gap);
        const contentHeight = paneHeight - paddingTop - paddingBottom;
        const paneMax = contentHeight - TERMINAL_DOCK_EDITOR_STAGE_MIN_HEIGHT - rowGap - TERMINAL_DOCK_RESERVED_CHROME_HEIGHT;

        if (Number.isFinite(paneMax)) {
          maxHeight = Math.min(maxHeight, paneMax);
        }
      }
    }

    maxHeight = Math.max(TERMINAL_DOCK_ABSOLUTE_MIN_HEIGHT, maxHeight);
    const minHeight = Math.min(TERMINAL_DOCK_MIN_HEIGHT, maxHeight);
    return { minHeight, maxHeight };
  }

  function normalizeTerminalDockHeight() {
    const { minHeight, maxHeight } = resolveTerminalDockBounds();
    terminalDockHeight.value = clampNumber(
      terminalDockHeight.value,
      minHeight,
      maxHeight,
    );
  }

  function openTerminalDock() {
    if (terminalDockOpen.value) {
      return;
    }

    terminalDockOpen.value = true;
    normalizeTerminalDockHeight();
    persistEditorLayoutPrefs();
    scheduleEditorResize();
  }

  function closeTerminalDock() {
    if (!terminalDockOpen.value) {
      return;
    }

    stopTerminalDockResize();
    terminalDockOpen.value = false;
    persistEditorLayoutPrefs();
    scheduleEditorResize();
  }

  function beginTerminalDockResize(startY, startHeight) {
    terminalDockResizeState = {
      startY,
      startHeight,
    };

    if (typeof window !== "undefined") {
      window.addEventListener("pointermove", onTerminalDockResizeMove);
      window.addEventListener("pointerup", stopTerminalDockResize);
      window.addEventListener("pointercancel", stopTerminalDockResize);
      document.body.classList.add("is-resizing-terminal-dock");
    }
  }

  function stopTerminalDockResize() {
    if (typeof window !== "undefined") {
      window.removeEventListener("pointermove", onTerminalDockResizeMove);
      window.removeEventListener("pointerup", stopTerminalDockResize);
      window.removeEventListener("pointercancel", stopTerminalDockResize);
      document.body.classList.remove("is-resizing-terminal-dock");
    }

    if (!terminalDockResizeState) {
      return;
    }

    terminalDockResizeState = null;
    persistEditorLayoutPrefs();
    scheduleEditorResize();
  }

  function onTerminalDockResizeMove(event) {
    if (!terminalDockResizeState || !terminalDockOpen.value) {
      return;
    }

    const { minHeight, maxHeight } = resolveTerminalDockBounds();
    const delta = terminalDockResizeState.startY - event.clientY;
    const nextHeight = clampNumber(
      terminalDockResizeState.startHeight + delta,
      minHeight,
      maxHeight,
    );

    terminalDockHeight.value = nextHeight;
    terminalDockResized.value = true;
    scheduleEditorResize();
  }

  function startTerminalDockResize(event) {
    if (event.button !== 0 || !canUseProjectFs.value || !terminalDockOpen.value) {
      return;
    }

    normalizeTerminalDockHeight();
    beginTerminalDockResize(event.clientY, terminalDockHeight.value);
    event.preventDefault();
  }

  function startTerminalDockPull(event) {
    if (event.button !== 0 || !canUseProjectFs.value) {
      return;
    }

    const fallbackHeight = terminalDockResized.value ? terminalDockHeight.value : TERMINAL_DOCK_DEFAULT_HEIGHT;
    const { minHeight, maxHeight } = resolveTerminalDockBounds();
    terminalDockOpen.value = true;
    const startHeight = clampNumber(
      fallbackHeight,
      minHeight,
      maxHeight,
    );
    terminalDockHeight.value = startHeight;
    terminalDockResized.value = true;
    beginTerminalDockResize(event.clientY, startHeight);
    scheduleEditorResize();
    event.preventDefault();
  }

  function measurePaneWidth(paneRef, fallback) {
    const pane = paneRef?.value;
    if (!pane) {
      return fallback;
    }

    const width = pane.getBoundingClientRect().width;
    if (!Number.isFinite(width) || width <= 0) {
      return fallback;
    }

    return width;
  }

  function resolveLayoutWidth() {
    const host = editorLayoutHost.value;
    if (!host) {
      return 0;
    }

    const width = host.getBoundingClientRect().width;
    if (!Number.isFinite(width) || width <= 0) {
      return 0;
    }

    return width;
  }

  function resolvePaneRatioTargets(layoutWidth) {
    const hasChatPane = canUseProjectFs.value && viewportWidth.value > 1260;
    const ratioSum = hasChatPane
      ? (LAYOUT_RATIO_SIDEBAR + LAYOUT_RATIO_MAIN + LAYOUT_RATIO_CHAT)
      : (LAYOUT_RATIO_SIDEBAR + LAYOUT_RATIO_MAIN);
    const splitters = hasChatPane ? SPLITTER_WIDTH * 2 : SPLITTER_WIDTH;
    const usableWidth = Math.max(0, layoutWidth - splitters);

    const sidebarTarget = usableWidth > 0
      ? (usableWidth * LAYOUT_RATIO_SIDEBAR) / ratioSum
      : SIDEBAR_MIN_WIDTH;
    const chatTarget = hasChatPane && usableWidth > 0
      ? (usableWidth * LAYOUT_RATIO_CHAT) / ratioSum
      : CHAT_MIN_WIDTH;

    return {
      sidebarTarget,
      chatTarget,
      hasChatPane,
    };
  }

  function measureSidebarWidth() {
    if (sidebarResized.value) {
      return sidebarWidth.value;
    }

    const layoutWidth = resolveLayoutWidth();
    if (layoutWidth > 0) {
      const { sidebarTarget } = resolvePaneRatioTargets(layoutWidth);
      return measurePaneWidth(editorSidebarPane, sidebarTarget);
    }

    return measurePaneWidth(editorSidebarPane, 420);
  }

  function measureChatWidth() {
    if (chatResized.value) {
      return chatWidth.value;
    }

    const layoutWidth = resolveLayoutWidth();
    if (layoutWidth > 0) {
      const { chatTarget } = resolvePaneRatioTargets(layoutWidth);
      return measurePaneWidth(editorChatPane, chatTarget);
    }

    return measurePaneWidth(editorChatPane, 160);
  }

  function resolveSidebarMax(layoutWidth, currentChatWidth) {
    const splitters = canUseProjectFs.value ? SPLITTER_WIDTH * 2 : SPLITTER_WIDTH;
    const available = layoutWidth - splitters - MAIN_MIN_WIDTH - (canUseProjectFs.value ? currentChatWidth : 0);
    const bounded = Math.min(SIDEBAR_MAX_WIDTH, available);

    return Math.max(SIDEBAR_MIN_WIDTH, bounded);
  }

  function resolveChatMax(layoutWidth, currentSidebarWidth) {
    const available = layoutWidth - (SPLITTER_WIDTH * 2) - MAIN_MIN_WIDTH - currentSidebarWidth;
    const bounded = Math.min(CHAT_MAX_WIDTH, available);

    return Math.max(CHAT_MIN_WIDTH, bounded);
  }

  function enforceMainWidthWithChat(layoutWidth, sidebarValue, chatValue) {
    const availableMain = layoutWidth - (SPLITTER_WIDTH * 2) - sidebarValue - chatValue;
    if (availableMain >= MAIN_MIN_WIDTH) {
      return {
        sidebar: sidebarValue,
        chat: chatValue,
      };
    }

    let deficit = MAIN_MIN_WIDTH - availableMain;
    let nextSidebar = sidebarValue;
    let nextChat = chatValue;

    const sidebarSpare = Math.max(0, nextSidebar - SIDEBAR_MIN_WIDTH);
    const chatSpare = Math.max(0, nextChat - CHAT_MIN_WIDTH);
    const totalSpare = sidebarSpare + chatSpare;

    if (totalSpare <= 0) {
      return {
        sidebar: nextSidebar,
        chat: nextChat,
      };
    }

    const reduceSidebar = Math.min(sidebarSpare, deficit * (sidebarSpare / totalSpare));
    nextSidebar -= reduceSidebar;
    deficit -= reduceSidebar;

    if (deficit > 0) {
      const reduceChat = Math.min(chatSpare, deficit);
      nextChat -= reduceChat;
    }

    return {
      sidebar: nextSidebar,
      chat: nextChat,
    };
  }

  function normalizeEditorLayoutWidths() {
    const width = resolveLayoutWidth();
    if (!width || viewportWidth.value <= 1040 || !showSidebar.value) {
      return;
    }

    if (canUseProjectFs.value && viewportWidth.value > 1260) {
      const { sidebarTarget, chatTarget } = resolvePaneRatioTargets(width);
      const preferredSidebar = sidebarResized.value ? measureSidebarWidth() : sidebarTarget;
      const preferredChat = chatResized.value ? measureChatWidth() : chatTarget;
      let safeSidebar = clampNumber(preferredSidebar, SIDEBAR_MIN_WIDTH, resolveSidebarMax(width, preferredChat));
      let safeChat = clampNumber(preferredChat, CHAT_MIN_WIDTH, resolveChatMax(width, safeSidebar));
      const adjusted = enforceMainWidthWithChat(width, safeSidebar, safeChat);

      safeSidebar = clampNumber(adjusted.sidebar, SIDEBAR_MIN_WIDTH, resolveSidebarMax(width, adjusted.chat));
      safeChat = clampNumber(adjusted.chat, CHAT_MIN_WIDTH, resolveChatMax(width, safeSidebar));

      sidebarWidth.value = safeSidebar;
      chatWidth.value = safeChat;

      return;
    }

    if (canResizeSidebarPane.value) {
      const { sidebarTarget } = resolvePaneRatioTargets(width);
      const preferredSidebar = sidebarResized.value ? measureSidebarWidth() : sidebarTarget;
      const safeSidebar = clampNumber(
        preferredSidebar,
        SIDEBAR_MIN_WIDTH,
        Math.max(SIDEBAR_MIN_WIDTH, Math.min(SIDEBAR_MAX_WIDTH, width - SPLITTER_WIDTH - MAIN_MIN_WIDTH)),
      );

      sidebarWidth.value = safeSidebar;
    }
  }

  function scheduleEditorResize() {
    const editor = typeof getEditor === "function" ? getEditor() : null;
    if (!editor || typeof window === "undefined") {
      return;
    }

    if (editorResizeFrameId !== null) {
      return;
    }

    editorResizeFrameId = window.requestAnimationFrame(() => {
      editorResizeFrameId = null;
      editor.resize();
    });
  }

  function cancelEditorResizeFrame() {
    if (typeof window === "undefined" || editorResizeFrameId === null) {
      return;
    }

    window.cancelAnimationFrame(editorResizeFrameId);
    editorResizeFrameId = null;
  }

  function persistEditorLayoutPrefs() {
    if (typeof window === "undefined") {
      return;
    }

    try {
      const payload = {
        sidebar_width: sidebarResized.value ? Math.round(sidebarWidth.value) : null,
        chat_width: chatResized.value ? Math.round(chatWidth.value) : null,
        terminal_dock_open: terminalDockOpen.value,
        terminal_dock_height: terminalDockResized.value ? Math.round(terminalDockHeight.value) : null,
      };

      window.localStorage.setItem(LAYOUT_KEY, JSON.stringify(payload));
    } catch (_error) {
      // Ignore localStorage write failures.
    }
  }

  function restoreEditorLayoutPrefs() {
    if (typeof window === "undefined") {
      return;
    }

    try {
      const raw = window.localStorage.getItem(LAYOUT_KEY);
      if (!raw) {
        return;
      }

      const payload = JSON.parse(raw);
      const persistedSidebar = Number(payload?.sidebar_width);
      const persistedChat = Number(payload?.chat_width);
      const persistedTerminalHeight = Number(payload?.terminal_dock_height);

      if (Number.isFinite(persistedSidebar) && persistedSidebar > 0) {
        sidebarWidth.value = persistedSidebar;
        sidebarResized.value = true;
      }

      if (Number.isFinite(persistedChat) && persistedChat > 0) {
        chatWidth.value = persistedChat;
        chatResized.value = true;
      }

      if (typeof payload?.terminal_dock_open === "boolean") {
        terminalDockOpen.value = payload.terminal_dock_open;
      }

      if (Number.isFinite(persistedTerminalHeight) && persistedTerminalHeight > 0) {
        terminalDockHeight.value = persistedTerminalHeight;
        terminalDockResized.value = true;
      }

      normalizeTerminalDockHeight();
    } catch (_error) {
      // Ignore malformed localStorage data.
    }
  }

  function stopPaneResize() {
    if (typeof window !== "undefined") {
      window.removeEventListener("pointermove", onPaneResizeMove);
      window.removeEventListener("pointerup", stopPaneResize);
      window.removeEventListener("pointercancel", stopPaneResize);
      document.body.classList.remove("is-resizing-editor-layout");
    }

    if (!paneResizeState) {
      activeResizePane.value = "";
      return;
    }

    paneResizeState = null;
    activeResizePane.value = "";
    persistEditorLayoutPrefs();
    scheduleEditorResize();
  }

  function onPaneResizeMove(event) {
    if (!paneResizeState) {
      return;
    }

    const width = resolveLayoutWidth();
    if (!width) {
      return;
    }

    if (paneResizeState.pane === "sidebar") {
      const delta = event.clientX - paneResizeState.startX;
      const chatReserve = canUseProjectFs.value ? (chatResized.value ? chatWidth.value : paneResizeState.startChatWidth) : 0;
      const maxSidebar = canUseProjectFs.value
        ? resolveSidebarMax(width, chatReserve)
        : Math.max(SIDEBAR_MIN_WIDTH, Math.min(SIDEBAR_MAX_WIDTH, width - SPLITTER_WIDTH - MAIN_MIN_WIDTH));

      sidebarWidth.value = clampNumber(
        paneResizeState.startSidebarWidth + delta,
        SIDEBAR_MIN_WIDTH,
        maxSidebar,
      );
      sidebarResized.value = true;
      scheduleEditorResize();
      return;
    }

    if (paneResizeState.pane === "chat" && canUseProjectFs.value) {
      const delta = event.clientX - paneResizeState.startX;
      const sidebarReserve = sidebarResized.value ? sidebarWidth.value : paneResizeState.startSidebarWidth;
      const maxChat = resolveChatMax(width, sidebarReserve);

      chatWidth.value = clampNumber(
        paneResizeState.startChatWidth - delta,
        CHAT_MIN_WIDTH,
        maxChat,
      );
      chatResized.value = true;
      scheduleEditorResize();
    }
  }

  function startPaneResize(pane, event) {
    if (event.button !== 0) {
      return;
    }

    if (pane === "sidebar" && !canResizeSidebarPane.value) {
      return;
    }

    if (pane === "chat" && !canResizeChatPane.value) {
      return;
    }

    paneResizeState = {
      pane,
      startX: event.clientX,
      startSidebarWidth: measureSidebarWidth(),
      startChatWidth: measureChatWidth(),
    };
    activeResizePane.value = pane;

    if (typeof window !== "undefined") {
      window.addEventListener("pointermove", onPaneResizeMove);
      window.addEventListener("pointerup", stopPaneResize);
      window.addEventListener("pointercancel", stopPaneResize);
      document.body.classList.add("is-resizing-editor-layout");
    }

    event.preventDefault();
  }

  function onViewportResize() {
    if (typeof window === "undefined") {
      return;
    }

    viewportWidth.value = window.innerWidth;
    normalizeEditorLayoutWidths();
    normalizeTerminalDockHeight();
    scheduleEditorResize();
  }

  function attachViewportListener() {
    if (typeof window === "undefined") {
      return;
    }

    viewportWidth.value = window.innerWidth;
    window.addEventListener("resize", onViewportResize);
    normalizeTerminalDockHeight();
  }

  function detachViewportListener() {
    if (typeof window === "undefined") {
      return;
    }

    window.removeEventListener("resize", onViewportResize);
  }

  watch(
    [showSidebar, canUseProjectFs, canResizeSidebarPane, canResizeChatPane],
    async () => {
      if (!showSidebar.value) {
        stopPaneResize();
      }

      if (!canUseProjectFs.value) {
        stopTerminalDockResize();
      } else {
        normalizeTerminalDockHeight();
      }

      if (!canResizeSidebarPane.value && activeResizePane.value === "sidebar") {
        stopPaneResize();
      }

      if (!canResizeChatPane.value && activeResizePane.value === "chat") {
        stopPaneResize();
      }

      await nextTick();
      normalizeEditorLayoutWidths();
      scheduleEditorResize();
    },
    { immediate: true },
  );

  return {
    editorLayoutHost,
    editorMainPane,
    editorSidebarPane,
    editorChatPane,
    viewportWidth,
    sidebarWidth,
    chatWidth,
    sidebarResized,
    chatResized,
    terminalDockOpen,
    terminalDockHeight,
    terminalDockResized,
    activeResizePane,
    canResizeChatPane,
    canResizeSidebarPane,
    editorLayoutStyle,
    terminalDockStyle,
    openTerminalDock,
    closeTerminalDock,
    startTerminalDockResize,
    startTerminalDockPull,
    stopTerminalDockResize,
    startPaneResize,
    stopPaneResize,
    normalizeEditorLayoutWidths,
    normalizeTerminalDockHeight,
    scheduleEditorResize,
    cancelEditorResizeFrame,
    persistEditorLayoutPrefs,
    restoreEditorLayoutPrefs,
    attachViewportListener,
    detachViewportListener,
  };
}
