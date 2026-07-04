import { computed, reactive, ref } from "vue";
import ace from "ace-builds/src-noconflict/ace";
import { avatarPresetStyles, defaultAvatarPreset } from "../config/avatarPresets";
import {
  applyTextOperation,
  createRealtimeOperationId,
  getOrCreateRealtimeClientId,
  isNoopTextOperation,
  normalizeTextOperation,
  transformConcurrentTextOperations,
} from "../services/collabOt";
import {
  disconnectRealtimeClient,
  joinProjectRealtimeChannel,
  leaveProjectRealtimeChannel,
} from "../services/realtime";

export function useEditorCollaboration(options) {
  const normalizedOptions = options || {};
  const {
    t,
    locale,
    session,
    isAuthenticated,
    canUseProjectFs,
    selectedProjectId,
    isProjectMode,
    activeProjectPath,
    currentText,
    dirty,
    editorInstance,
    syncingEditor,
    syncEditor,
    request,
    getSession,
    readError,
    nextTick,
    loadTree,
    loadProjectParticipants,
    canManageProjectSettings,
    notice,
    error,
  } = normalizedOptions;

const liveSyncEnabled = ref(true);
const realtimePeers = ref([]);
const chatMessages = ref([]);
const chatInputRef = ref(null);
const chatDraft = ref("");
const chatSending = ref(false);
const chatEditingMessageId = ref(0);
const chatDeletingMessageId = ref(0);
const chatVoiceSupported = ref(false);
const chatVoiceListening = ref(false);
const codeComments = ref([]);
const codeCommentsLoading = ref(false);
const codeCommentDeletingId = ref(0);
const aceCommentOverlayHost = ref(null);
const aceLineCommentTriggerRef = ref(null);
const aceLineCommentPopoverRef = ref(null);
const aceLineCommentInputRef = ref(null);
const aceLineCommentDraft = ref("");
const aceLineCommentSubmitting = ref(false);
const aceLineCommentVoiceListening = ref(false);
const aceLineCommentTrigger = reactive({
  visible: false,
  row: 0,
  line_number: 1,
  top: 0,
  left: 0,
  has_comments: false,
  count: 0,
});
const aceLineCommentPopover = reactive({
  open: false,
  isClosing: false,
  row: 0,
  line_number: 1,
  top: 0,
  left: 0,
});
const currentUserId = computed(() => Number(session.value?.user?.user_id || 0));
const chatDraftTrimmed = computed(() => String(chatDraft.value || "").trim());
const realtimeClientId = ref(getOrCreateRealtimeClientId());
const editorSyncRevision = ref(0);
const editorSyncBusy = ref(false);

const realtimePeerTtlSeconds = 20;
const realtimeRemoteColors = [
  "#0f766e",
  "#2563eb",
  "#7c3aed",
  "#c2410c",
  "#be123c",
  "#1f7a8c",
];
const EDITOR_SYNC_DEBOUNCE_MS = 36;
const EDITOR_SYNC_DELETE_DEBOUNCE_MS = 160;
const AceRange = ace.require("ace/range").Range;
const ACE_LINE_COMMENT_TRIGGER_OFFSET_X = -26;
const ACE_LINE_COMMENT_POPOVER_OFFSET_X = 8;

let activeRealtimeProjectId = "";
let realtimeChannel = null;
let realtimeChannelSubscribed = false;
let realtimeSubscribeWatchTimerId = null;
let realtimeSubscribeAttempts = 0;
let presenceIntervalTimerId = null;
let presenceDebounceTimerId = null;
let presencePruneTimerId = null;
let chatFallbackPollTimerId = null;
let editorSyncDebounceTimerId = null;
let editorSyncRetryTimerId = null;
let editorSyncPendingOps = [];
let editorSyncInflightOp = null;
let editorDeferredRemoteOps = [];
let chatVoiceRecognition = null;
let aceLineCommentVoiceRecognition = null;
let chatVoiceAbortRequested = false;
let aceLineCommentVoiceAbortRequested = false;
let chatVoiceBaseDraft = "";
let aceLineCommentVoiceBaseDraft = "";
// let chatVoiceAudioChunks = [];  // unused - only used in commented functions
// let aceLineCommentVoiceAudioChunks = [];  // unused - only used in commented functions
let applyingRemoteEditorChange = false;
let remoteMarkerStyleInjected = false;
const remoteMarkers = new Map();
const codeCommentDecoratedRows = new Set();
let presenceSyncInFlight = false;
let presenceSyncPending = false;
let chatLoading = false;
let codeCommentsLoadRequestId = 0;
let aceCommentBoundEditor = null;
let aceCommentBoundHoverHost = null;
let aceCommentMouseMoveHandler = null;
let aceCommentMouseLeaveHandler = null;
let aceCommentScrollTopHandler = null;
let aceCommentScrollLeftHandler = null;
const codeCommentsByLine = computed(() => {
  const map = new Map();

  codeComments.value.forEach((comment) => {
    const lineNumber = Math.max(1, Number(comment.line_number || 1));
    if (!map.has(lineNumber)) {
      map.set(lineNumber, []);
    }

    map.get(lineNumber).push(comment);
  });

  return map;
});
const aceLineComments = computed(() => {
  const lineNumber = Math.max(1, Number(aceLineCommentPopover.line_number || 1));
  return codeCommentsByLine.value.get(lineNumber) || [];
});

function clampValue(valueInput, min, max) {
  const value = Number(valueInput);
  if (!Number.isFinite(value)) {
    return min;
  }

  return Math.max(min, Math.min(max, value));
}

function resolveLineCommentCount(lineNumberInput) {
  const lineNumber = Math.max(1, Number(lineNumberInput || 1));
  const list = codeCommentsByLine.value.get(lineNumber);
  return Array.isArray(list) ? list.length : 0;
}

function resolveEditorLineTop(rowInput) {
  const editor = editorInstance.value;
  const host = aceCommentOverlayHost.value;
  if (!editor || !host || typeof window === "undefined") {
    return null;
  }

  const row = Math.max(0, Math.trunc(Number(rowInput || 0)));
  const coordinates = editor.renderer?.textToScreenCoordinates?.(row, 0);
  if (!coordinates) {
    return null;
  }

  const hostRect = host.getBoundingClientRect();
  const pageY = Number(coordinates.pageY || 0);
  if (!Number.isFinite(pageY)) {
    return null;
  }

  return pageY - window.scrollY - hostRect.top;
}

function hideAceLineCommentTrigger() {
  aceLineCommentTrigger.visible = false;
}

function startCloseAceLineComments() {
  // Start closing animation
  aceLineCommentPopover.isClosing = true;
}

function handleCommentPopoverAnimationEnd(event) {
  // Only close when animation ends and we're closing
  if (aceLineCommentPopover.isClosing && event.target.classList?.contains("ace-line-comment-popover")) {
    closeAceLineComments();
  }
}

function closeAceLineComments() {
  stopAceLineCommentVoiceInput({ discard: true });
  aceLineCommentPopover.open = false;
  aceLineCommentPopover.isClosing = false;
  aceLineCommentDraft.value = "";
  aceLineCommentSubmitting.value = false;
}

function resetAceLineCommentUi() {
  hideAceLineCommentTrigger();
  closeAceLineComments();
}

function positionAceLineCommentPopover(lineNumberInput) {
  const editor = editorInstance.value;
  const host = aceCommentOverlayHost.value;
  if (!editor || !host) {
    return;
  }

  const lineNumber = Math.max(1, Math.trunc(Number(lineNumberInput || 1)));
  const row = lineNumber - 1;
  const lineTop = resolveEditorLineTop(row);
  const baseTop = lineTop === null ? 8 : lineTop + 2;
  const gutterWidth = Number(editor.renderer?.gutterWidth || 48);
  const maxTop = Math.max(8, host.clientHeight - 280);
  const maxLeft = Math.max(56, host.clientWidth - 344);

  aceLineCommentPopover.row = row;
  aceLineCommentPopover.line_number = lineNumber;
  aceLineCommentPopover.top = Math.round(clampValue(baseTop, 8, maxTop));
  aceLineCommentPopover.left = Math.round(clampValue(gutterWidth + ACE_LINE_COMMENT_POPOVER_OFFSET_X, 56, maxLeft));
}

function openAceLineCommentsAtLine(lineNumberInput) {
  if (!isProjectMode.value || !activeProjectPath.value) {
    return;
  }

  const lineNumber = Math.max(1, Math.trunc(Number(lineNumberInput || 1)));
  positionAceLineCommentPopover(lineNumber);
  aceLineCommentPopover.open = true;
}

function openAceLineCommentsFromTrigger() {
  if (!aceLineCommentTrigger.visible) {
    return;
  }

  openAceLineCommentsAtLine(aceLineCommentTrigger.line_number);
}

function updateAceLineCommentTriggerPosition(clientX, clientY) {
  const editor = editorInstance.value;
  const host = aceCommentOverlayHost.value;
  if (!editor || !host || !isProjectMode.value || !activeProjectPath.value) {
    hideAceLineCommentTrigger();
    return;
  }

  const hostRect = host.getBoundingClientRect();
  if (
    clientX < hostRect.left
    || clientX > hostRect.right
    || clientY < hostRect.top
    || clientY > hostRect.bottom
  ) {
    hideAceLineCommentTrigger();
    return;
  }

  const coordinates = editor.renderer?.screenToTextCoordinates?.(clientX, clientY);
  const row = Math.max(0, Math.trunc(Number(coordinates?.row || 0)));
  const maxRow = Math.max(0, Number(editor.session?.getLength?.() || 1) - 1);
  const safeRow = Math.min(row, maxRow);
  const lineNumber = safeRow + 1;
  const lineTop = resolveEditorLineTop(safeRow);
  const baseTop = lineTop === null ? (clientY - hostRect.top) : lineTop + 1;
  const gutterWidth = Number(editor.renderer?.gutterWidth || 48);
  const maxTop = Math.max(6, host.clientHeight - 26);
  const maxLeft = Math.max(6, host.clientWidth - 26);
  const count = resolveLineCommentCount(lineNumber);

  aceLineCommentTrigger.visible = true;
  aceLineCommentTrigger.row = safeRow;
  aceLineCommentTrigger.line_number = lineNumber;
  aceLineCommentTrigger.top = Math.round(clampValue(baseTop, 6, maxTop));
  aceLineCommentTrigger.left = Math.round(clampValue(gutterWidth + ACE_LINE_COMMENT_TRIGGER_OFFSET_X, 6, maxLeft));
  aceLineCommentTrigger.has_comments = count > 0;
  aceLineCommentTrigger.count = count;
}

function isTargetInsideAceCommentUi(target) {
  if (typeof Node === "undefined") {
    return false;
  }

  const node = target instanceof Node ? target : null;
  if (!node) {
    return false;
  }

  return Boolean(aceLineCommentPopoverRef.value?.contains(node) || aceLineCommentTriggerRef.value?.contains(node));
}

function handleDocumentPointerDown(event) {
  if (!aceLineCommentPopover.open) {
    return;
  }

  if (isTargetInsideAceCommentUi(event.target)) {
    return;
  }

  closeAceLineComments();
}

function detachAceLineCommentHandlers() {
  const editor = aceCommentBoundEditor;
  if (!editor) {
    return;
  }

  const hoverHost = aceCommentBoundHoverHost || editor.container;
  if (aceCommentMouseMoveHandler) {
    hoverHost.removeEventListener("mousemove", aceCommentMouseMoveHandler);
  }

  if (aceCommentMouseLeaveHandler) {
    hoverHost.removeEventListener("mouseleave", aceCommentMouseLeaveHandler);
  }

  const sessionRef = editor.session;
  if (sessionRef) {
    const remove = (eventName, handler) => {
      if (!handler) {
        return;
      }

      if (typeof sessionRef.off === "function") {
        sessionRef.off(eventName, handler);
      } else if (typeof sessionRef.removeListener === "function") {
        sessionRef.removeListener(eventName, handler);
      }
    };

    remove("changeScrollTop", aceCommentScrollTopHandler);
    remove("changeScrollLeft", aceCommentScrollLeftHandler);
  }

  aceCommentMouseMoveHandler = null;
  aceCommentMouseLeaveHandler = null;
  aceCommentScrollTopHandler = null;
  aceCommentScrollLeftHandler = null;
  aceCommentBoundHoverHost = null;
  aceCommentBoundEditor = null;
}

function attachAceLineCommentHandlers() {
  const editor = editorInstance.value;
  if (!editor || aceCommentBoundEditor === editor) {
    return;
  }

  detachAceLineCommentHandlers();
  aceCommentBoundEditor = editor;
  aceCommentBoundHoverHost = aceCommentOverlayHost.value || editor.container;

  aceCommentMouseMoveHandler = (event) => {
    updateAceLineCommentTriggerPosition(event.clientX, event.clientY);
  };
  aceCommentMouseLeaveHandler = () => {
    hideAceLineCommentTrigger();
  };
  aceCommentScrollTopHandler = () => {
    hideAceLineCommentTrigger();
    if (aceLineCommentPopover.open) {
      positionAceLineCommentPopover(aceLineCommentPopover.line_number);
    }
  };
  aceCommentScrollLeftHandler = aceCommentScrollTopHandler;

  aceCommentBoundHoverHost.addEventListener("mousemove", aceCommentMouseMoveHandler);
  aceCommentBoundHoverHost.addEventListener("mouseleave", aceCommentMouseLeaveHandler);
  editor.session.on("changeScrollTop", aceCommentScrollTopHandler);
  editor.session.on("changeScrollLeft", aceCommentScrollLeftHandler);
}

function resetCodeCommentsState() {
  codeCommentsLoadRequestId += 1;
  codeCommentsLoading.value = false;
  codeComments.value = [];
  codeCommentDeletingId.value = 0;
  resetAceLineCommentUi();
  clearCodeCommentDecorations();
}

function normalizeCodeComment(commentInput) {
  const comment = commentInput || {};
  const author = comment.author || {};
  const commentId = Number(comment.comment_id || 0);
  if (!commentId) {
    return null;
  }

  const lineNumber = Number(comment.line_number || 1);

  return {
    comment_id: commentId,
    path: String(comment.path || ""),
    line_number: Number.isFinite(lineNumber) && lineNumber > 0 ? Math.trunc(lineNumber) : 1,
    body: String(comment.body || ""),
    created_at: String(comment.created_at || ""),
    author_user_id: Number(author.user_id || 0),
    author_name: String(author.name || author.email || `User #${author.user_id || "?"}`),
    author_avatar_preset: typeof author.avatar_preset === "string" && author.avatar_preset.trim() !== ""
      ? author.avatar_preset.trim()
      : defaultAvatarPreset,
    author_avatar_url: normalizeAvatarUrl(author.avatar_url),
  };
}

function clampCodeCommentLine(valueInput) {
  const value = Number(valueInput);
  if (!Number.isFinite(value)) {
    return 1;
  }

  const normalized = Math.trunc(value);
  return Math.max(1, Math.min(1000000, normalized));
}

function sortCodeComments(itemsInput) {
  return [...itemsInput].sort((left, right) => {
    const byLine = Number(left.line_number || 0) - Number(right.line_number || 0);
    if (byLine !== 0) {
      return byLine;
    }

    return Number(left.comment_id || 0) - Number(right.comment_id || 0);
  });
}

function applyRealtimeCodeCommentUpdate(payloadInput) {
  const payload = payloadInput && typeof payloadInput === "object" ? payloadInput : {};
  const commentPayload = payload.comment && typeof payload.comment === "object" ? payload.comment : null;
  const path = String(payload.path || commentPayload?.path || "");
  if (!activeProjectPath.value || !path || path !== activeProjectPath.value) {
    return;
  }

  const action = String(payload.action || "").trim().toLowerCase();
  if (action === "deleted") {
    const commentId = Number(payload.comment_id || commentPayload?.comment_id || 0);
    if (!commentId) {
      return;
    }

    codeComments.value = codeComments.value.filter((item) => item.comment_id !== commentId);
    return;
  }

  const normalized = normalizeCodeComment(commentPayload || payload);
  if (!normalized || normalized.path !== activeProjectPath.value) {
    return;
  }

  codeComments.value = sortCodeComments([
    ...codeComments.value.filter((item) => item.comment_id !== normalized.comment_id),
    normalized,
  ]);
}

function clearCodeCommentDecorations() {
  const sessionRef = editorInstance.value?.session;
  if (!sessionRef) {
    codeCommentDecoratedRows.clear();
    return;
  }

  Array.from(codeCommentDecoratedRows.values()).forEach((row) => {
    sessionRef.removeGutterDecoration(row, "has-code-comment");
  });
  codeCommentDecoratedRows.clear();
}

function renderCodeCommentDecorations() {
  clearCodeCommentDecorations();

  const sessionRef = editorInstance.value?.session;
  if (!sessionRef || !activeProjectPath.value) {
    return;
  }

  const uniqueRows = new Set();
  codeComments.value.forEach((comment) => {
    const row = Math.max(0, Number(comment.line_number || 1) - 1);
    if (!Number.isFinite(row) || uniqueRows.has(row)) {
      return;
    }

    sessionRef.addGutterDecoration(row, "has-code-comment");
    uniqueRows.add(row);
  });

  uniqueRows.forEach((row) => codeCommentDecoratedRows.add(row));
}

async function loadCodeComments() {
  if (!canUseProjectFs.value || !selectedProjectId.value || !activeProjectPath.value) {
    resetCodeCommentsState();
    return;
  }

  const projectId = String(selectedProjectId.value);
  const path = String(activeProjectPath.value || "");
  const requestId = ++codeCommentsLoadRequestId;
  codeCommentsLoading.value = true;

  try {
    const response = await request({
      method: "GET",
      path: `/projects/${projectId}/code-comments`,
      auth: true,
      query: {
        path,
        limit: 500,
      },
    });

    if (requestId !== codeCommentsLoadRequestId || path !== activeProjectPath.value) {
      return;
    }

    const incoming = Array.isArray(response.data?.comments) ? response.data.comments : [];
    codeComments.value = incoming
      .map((item) => normalizeCodeComment(item))
      .filter((item) => item !== null);
    renderCodeCommentDecorations();
  } catch (commentsError) {
    if (requestId === codeCommentsLoadRequestId) {
      error.value = readError(commentsError);
    }
  } finally {
    if (requestId === codeCommentsLoadRequestId) {
      codeCommentsLoading.value = false;
    }
  }
}

async function createCodeCommentEntry(lineNumberInput, bodyInput) {
  if (
    !canUseProjectFs.value
    || !selectedProjectId.value
    || !activeProjectPath.value
  ) {
    return null;
  }

  const body = String(bodyInput || "").trim();
  if (!body) {
    return null;
  }

  const lineNumber = clampCodeCommentLine(lineNumberInput);
  if (!lineNumber) {
    error.value = t("editor.commentLineInvalid");
    return null;
  }

  const response = await request({
    method: "POST",
    path: `/projects/${selectedProjectId.value}/code-comments`,
    auth: true,
    body: {
      path: activeProjectPath.value,
      line_number: lineNumber,
      body,
    },
  });

  const created = normalizeCodeComment(response.data?.comment);
  if (!created) {
    return null;
  }

  codeComments.value = sortCodeComments([
    ...codeComments.value.filter((item) => item.comment_id !== created.comment_id),
    created,
  ]);
  renderCodeCommentDecorations();
  notice.value = t("editor.commentAdded");

  return created;
}

async function submitAceLineComment() {
  if (!aceLineCommentPopover.open || aceLineCommentSubmitting.value) {
    return;
  }

  stopAceLineCommentVoiceInput({ discard: true });
  aceLineCommentSubmitting.value = true;
  error.value = "";

  try {
    const created = await createCodeCommentEntry(aceLineCommentPopover.line_number, aceLineCommentDraft.value);
    if (!created) {
      return;
    }

    aceLineCommentDraft.value = "";
    positionAceLineCommentPopover(created.line_number);
    aceLineCommentTrigger.has_comments = resolveLineCommentCount(created.line_number) > 0;
    aceLineCommentTrigger.count = resolveLineCommentCount(created.line_number);
  } catch (commentError) {
    error.value = readError(commentError);
  } finally {
    aceLineCommentSubmitting.value = false;
  }
}

async function deleteCodeComment(commentInput) {
  const comment = commentInput || {};
  const commentId = Number(comment.comment_id || 0);
  if (!selectedProjectId.value || !commentId || codeCommentDeletingId.value === commentId) {
    return;
  }

  codeCommentDeletingId.value = commentId;
  error.value = "";

  try {
    await request({
      method: "DELETE",
      path: `/projects/${selectedProjectId.value}/code-comments/${commentId}`,
      auth: true,
    });

    codeComments.value = codeComments.value.filter((item) => item.comment_id !== commentId);
    renderCodeCommentDecorations();
    if (aceLineCommentTrigger.visible) {
      const nextCount = resolveLineCommentCount(aceLineCommentTrigger.line_number);
      aceLineCommentTrigger.has_comments = nextCount > 0;
      aceLineCommentTrigger.count = nextCount;
    }
    notice.value = t("editor.commentRemoved");
  } catch (commentError) {
    error.value = readError(commentError);
  } finally {
    codeCommentDeletingId.value = 0;
  }
}

function withOpacity(hex, alpha) {
  const normalizedHex = String(hex || "").trim().replace("#", "");
  if (!/^[0-9a-fA-F]{6}$/.test(normalizedHex)) {
    return `rgba(99, 102, 241, ${alpha})`;
  }

  const r = Number.parseInt(normalizedHex.slice(0, 2), 16);
  const g = Number.parseInt(normalizedHex.slice(2, 4), 16);
  const b = Number.parseInt(normalizedHex.slice(4, 6), 16);

  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function remoteColorIndex(userId) {
  return Math.abs(Number(userId || 0)) % realtimeRemoteColors.length;
}

function ensureRemoteMarkerStyles() {
  if (remoteMarkerStyleInjected || typeof document === "undefined") {
    return;
  }

  const existing = document.getElementById("livecode-remote-marker-styles");
  if (existing) {
    remoteMarkerStyleInjected = true;
    return;
  }

  const rules = realtimeRemoteColors
    .map((color, index) => {
      return `
.ace_marker-layer .remote-cursor-${index} {
  position: absolute;
  background: ${color};
  width: 2px !important;
  margin-left: -1px;
  border-radius: 2px;
  opacity: 0.95;
}

.ace_marker-layer .remote-selection-${index} {
  position: absolute;
  background: ${withOpacity(color, 0.22)};
  border-radius: 2px;
}`;
    })
    .join("\n");

  const style = document.createElement("style");
  style.id = "livecode-remote-marker-styles";
  style.textContent = rules;
  document.head.appendChild(style);
  remoteMarkerStyleInjected = true;
}

function clearPeerMarkers(userId) {
  const markerEntry = remoteMarkers.get(Number(userId));
  const sessionRef = editorInstance.value?.session;
  if (!markerEntry || !sessionRef) {
    remoteMarkers.delete(Number(userId));
    return;
  }

  if (markerEntry.cursorId !== null && markerEntry.cursorId !== undefined) {
    sessionRef.removeMarker(markerEntry.cursorId);
  }
  if (markerEntry.selectionId !== null && markerEntry.selectionId !== undefined) {
    sessionRef.removeMarker(markerEntry.selectionId);
  }

  remoteMarkers.delete(Number(userId));
}

function clearAllPeerMarkers() {
  Array.from(remoteMarkers.keys()).forEach((userId) => {
    clearPeerMarkers(userId);
  });
}

function renderPeerMarkers(peerInput) {
  const peer = peerInput || {};
  const userId = Number(peer.user_id || 0);
  if (!userId) {
    return;
  }

  if (!editorInstance.value || peer.path !== activeProjectPath.value || !canUseProjectFs.value) {
    clearPeerMarkers(userId);
    return;
  }

  ensureRemoteMarkerStyles();
  clearPeerMarkers(userId);

  const sessionRef = editorInstance.value.session;
  const colorIndex = remoteColorIndex(userId);
  let cursorId = null;
  let selectionId = null;

  const cursorRow = peer.cursor_row;
  const cursorColumn = peer.cursor_column;
  if (Number.isFinite(cursorRow) && Number.isFinite(cursorColumn)) {
    const lineText = sessionRef.getLine(cursorRow) || "";
    const safeColumn = Math.max(0, Math.min(cursorColumn, lineText.length));
    const endColumn = safeColumn + 1;
    const cursorRange = new AceRange(cursorRow, safeColumn, cursorRow, endColumn);
    cursorId = sessionRef.addMarker(cursorRange, `remote-cursor-${colorIndex}`, "text", true);
  }

  const startRow = peer.selection_start_row;
  const startColumn = peer.selection_start_column;
  const endRow = peer.selection_end_row;
  const endColumn = peer.selection_end_column;
  const hasSelection = Number.isFinite(startRow)
    && Number.isFinite(startColumn)
    && Number.isFinite(endRow)
    && Number.isFinite(endColumn)
    && (startRow !== endRow || startColumn !== endColumn);

  if (hasSelection) {
    const normalizedStart = {
      row: Math.max(0, startRow),
      column: Math.max(0, startColumn),
    };
    const normalizedEnd = {
      row: Math.max(0, endRow),
      column: Math.max(0, endColumn),
    };

    const selectionRange = new AceRange(
      normalizedStart.row,
      normalizedStart.column,
      normalizedEnd.row,
      normalizedEnd.column,
    );
    selectionId = sessionRef.addMarker(selectionRange, `remote-selection-${colorIndex}`, "text", false);
  }

  remoteMarkers.set(userId, {
    cursorId,
    selectionId,
  });
}

function sortPeers(peers) {
  return [...peers].sort((left, right) => {
    return String(left?.name || "").localeCompare(String(right?.name || ""));
  });
}

function normalizeAvatarUrl(urlInput) {
  return typeof urlInput === "string" && urlInput.trim() !== "" ? urlInput.trim() : "";
}

function resolveAvatarStyle(presetInput) {
  const key = typeof presetInput === "string" && presetInput.trim() !== ""
    ? presetInput.trim()
    : defaultAvatarPreset;

  return avatarPresetStyles[key] || avatarPresetStyles[defaultAvatarPreset];
}

function resolvePresenceAvatarUrl(peerInput) {
  return normalizeAvatarUrl(peerInput?.avatar_url);
}

function resolvePresenceAvatarStyle(peerInput) {
  return resolveAvatarStyle(peerInput?.avatar_preset);
}

function resolveMessageAvatarUrl(messageInput) {
  const message = messageInput || {};
  const direct = normalizeAvatarUrl(message.avatar_url);
  if (direct) {
    return direct;
  }

  if (Number(message.user_id || 0) === currentUserId.value) {
    return normalizeAvatarUrl(session.value?.user?.avatar_url);
  }

  return "";
}

function resolveMessageAvatarStyle(messageInput) {
  const message = messageInput || {};
  if (typeof message.avatar_preset === "string" && message.avatar_preset.trim() !== "") {
    return resolveAvatarStyle(message.avatar_preset);
  }

  if (Number(message.user_id || 0) === currentUserId.value) {
    return resolveAvatarStyle(session.value?.user?.avatar_preset);
  }

  return resolveAvatarStyle(defaultAvatarPreset);
}

function normalizePresencePeer(itemInput) {
  const item = itemInput || {};
  const userId = Number(item.user_id || 0);
  if (!userId || userId === currentUserId.value) {
    return null;
  }

  return {
    user_id: userId,
    name: String(item.name || `User #${userId}`),
    avatar_preset: typeof item.avatar_preset === "string" && item.avatar_preset.trim() !== ""
      ? item.avatar_preset.trim()
      : defaultAvatarPreset,
    avatar_url: normalizeAvatarUrl(item.avatar_url),
    path: String(item.path || ""),
    cursor_row: item.cursor_row === null || item.cursor_row === undefined ? null : Number(item.cursor_row),
    cursor_column: item.cursor_column === null || item.cursor_column === undefined ? null : Number(item.cursor_column),
    selection_start_row: item.selection_start_row === null || item.selection_start_row === undefined ? null : Number(item.selection_start_row),
    selection_start_column: item.selection_start_column === null || item.selection_start_column === undefined ? null : Number(item.selection_start_column),
    selection_end_row: item.selection_end_row === null || item.selection_end_row === undefined ? null : Number(item.selection_end_row),
    selection_end_column: item.selection_end_column === null || item.selection_end_column === undefined ? null : Number(item.selection_end_column),
    seen_at: Number(item.seen_at || Math.floor(Date.now() / 1000)),
  };
}

function applyPresencePeers(peersInput) {
  const normalizedPeers = Array.isArray(peersInput)
    ? peersInput
      .map((item) => normalizePresencePeer(item))
      .filter((item) => item !== null)
    : [];

  const incomingIds = new Set(normalizedPeers.map((item) => item.user_id));
  realtimePeers.value.forEach((peer) => {
    if (!incomingIds.has(peer.user_id)) {
      clearPeerMarkers(peer.user_id);
    }
  });

  realtimePeers.value = sortPeers(normalizedPeers);
  realtimePeers.value.forEach((peer) => {
    renderPeerMarkers(peer);
  });
}

function upsertPresencePeer(itemInput) {
  const peer = normalizePresencePeer(itemInput);
  if (!peer) {
    return;
  }

  const next = realtimePeers.value.filter((item) => item.user_id !== peer.user_id);
  next.push(peer);
  realtimePeers.value = sortPeers(next);
  renderPeerMarkers(peer);
}

function pruneStalePeers() {
  const threshold = Math.floor(Date.now() / 1000) - realtimePeerTtlSeconds;
  const staleIds = realtimePeers.value
    .filter((peer) => Number(peer.seen_at || 0) < threshold)
    .map((peer) => peer.user_id);

  staleIds.forEach((userId) => clearPeerMarkers(userId));
  realtimePeers.value = realtimePeers.value.filter((peer) => Number(peer.seen_at || 0) >= threshold);
}

function presencePayloadFromEditor() {
  const payload = {
    path: activeProjectPath.value || "",
  };

  const editor = editorInstance.value;
  if (!editor || !activeProjectPath.value) {
    return payload;
  }

  const cursor = editor.getCursorPosition();
  payload.cursor_row = Number(cursor?.row ?? 0);
  payload.cursor_column = Number(cursor?.column ?? 0);

  const range = editor.selection?.getRange?.();
  if (range && (range.start.row !== range.end.row || range.start.column !== range.end.column)) {
    payload.selection_start_row = Number(range.start.row);
    payload.selection_start_column = Number(range.start.column);
    payload.selection_end_row = Number(range.end.row);
    payload.selection_end_column = Number(range.end.column);
  } else {
    payload.selection_start_row = null;
    payload.selection_start_column = null;
    payload.selection_end_row = null;
    payload.selection_end_column = null;
  }

  return payload;
}

async function syncPresenceHeartbeat() {
  if (!canUseProjectFs.value || !selectedProjectId.value || !isAuthenticated.value) {
    return;
  }

  const path = activeProjectPath.value;
  if (!path) {
    return;
  }

  if (presenceSyncInFlight) {
    presenceSyncPending = true;
    return;
  }

  presenceSyncPending = false;
  presenceSyncInFlight = true;
  try {
    const response = await request({
      method: "POST",
      path: `/projects/${selectedProjectId.value}/realtime/heartbeat`,
      auth: true,
      body: presencePayloadFromEditor(),
    });

    applyPresencePeers(response.data?.peers);
  } catch (_presenceError) {
    // Heartbeat failures are non-fatal and should not interrupt editing.
  } finally {
    presenceSyncInFlight = false;
    if (presenceSyncPending) {
      presenceSyncPending = false;
      void syncPresenceHeartbeat();
    }
  }
}

function sendPresenceWhisper() {
  if (!realtimeChannel || !realtimeChannelSubscribed) {
    return false;
  }

  const path = activeProjectPath.value;
  if (!path) {
    return false;
  }

  const payload = presencePayloadFromEditor();
  payload.user_id = currentUserId.value;
  realtimeChannel.whisper("presence.updated", payload);
  return true;
}

function schedulePresenceSync(delay = 500) {
  if (!canUseProjectFs.value || !liveSyncEnabled.value || !activeProjectPath.value) {
    return;
  }

  presenceSyncPending = true;

  if (presenceDebounceTimerId !== null && typeof window !== "undefined") {
    window.clearTimeout(presenceDebounceTimerId);
    presenceDebounceTimerId = null;
  }

  if (typeof window === "undefined") {
    void syncPresenceHeartbeat();
    return;
  }

  presenceDebounceTimerId = window.setTimeout(() => {
    presenceDebounceTimerId = null;
    if (realtimeChannelSubscribed && realtimeChannel) {
      sendPresenceWhisper();
    } else {
      void syncPresenceHeartbeat();
    }
  }, Math.max(0, Number(delay || 0)));
}

function normalizeChatMessage(messageInput) {
  const message = messageInput || {};
  const id = Number(message.id || 0);
  if (!id) {
    return null;
  }

  return {
    id,
    user_id: Number(message.user_id || 0),
    user_name: String(message.user_name || `User #${message.user_id || "?"}`),
    avatar_preset: typeof message.avatar_preset === "string" && message.avatar_preset.trim() !== ""
      ? message.avatar_preset.trim()
      : defaultAvatarPreset,
    avatar_url: normalizeAvatarUrl(message.avatar_url),
    message: String(message.message || ""),
    created_at: String(message.created_at || ""),
    updated_at: typeof message.updated_at === "string" && message.updated_at.trim() !== ""
      ? message.updated_at.trim()
      : null,
  };
}

function replaceChatMessages(messagesInput) {
  const normalized = Array.isArray(messagesInput)
    ? messagesInput
      .map((item) => normalizeChatMessage(item))
      .filter((item) => item !== null)
    : [];

  chatMessages.value = normalized
    .sort((left, right) => left.id - right.id)
    .slice(-200);
}

function upsertChatMessage(messageInput) {
  const normalized = normalizeChatMessage(messageInput);
  if (!normalized) {
    return;
  }

  const next = [...chatMessages.value];
  const index = next.findIndex((item) => item.id === normalized.id);
  if (index >= 0) {
    next[index] = {
      ...next[index],
      ...normalized,
    };
  } else {
    next.push(normalized);
  }

  chatMessages.value = next
    .sort((left, right) => left.id - right.id)
    .slice(-200);

  if (chatEditingMessageId.value > 0 && chatEditingMessageId.value === normalized.id) {
    const latest = chatMessages.value.find((item) => item.id === normalized.id);
    if (latest) {
      chatDraft.value = String(latest.message || "");
    }
  }
}

function removeChatMessageById(messageIdInput) {
  const messageId = Number(messageIdInput || 0);
  if (!messageId) {
    return;
  }

  chatMessages.value = chatMessages.value.filter((item) => item.id !== messageId);

  if (chatEditingMessageId.value === messageId) {
    chatEditingMessageId.value = 0;
    chatDraft.value = "";
  }
}

function focusChatInput() {
  nextTick(() => {
    const input = chatInputRef.value;
    if (!input || typeof input.focus !== "function") {
      return;
    }

    input.focus();
    if (typeof input.setSelectionRange === "function") {
      const length = String(chatDraft.value || "").length;
      input.setSelectionRange(length, length);
    }
  });
}

function focusAceLineCommentInput() {
  nextTick(() => {
    const input = aceLineCommentInputRef.value;
    if (!input || typeof input.focus !== "function") {
      return;
    }

    input.focus();
    if (typeof input.setSelectionRange === "function") {
      const length = String(aceLineCommentDraft.value || "").length;
      input.setSelectionRange(length, length);
    }
  });
}

function startChatMessageEdit(messageInput) {
  const normalized = normalizeChatMessage(messageInput);
  if (!normalized) {
    return;
  }

  if (normalized.user_id !== currentUserId.value) {
    return;
  }

  chatEditingMessageId.value = normalized.id;
  chatDraft.value = normalized.message;
  focusChatInput();
}

function cancelChatMessageEdit() {
  chatEditingMessageId.value = 0;
  chatDraft.value = "";
}

function isMicrophoneContextAllowed() {
  if (typeof window === "undefined") {
    return false;
  }

  if (window.isSecureContext) {
    return true;
  }

  const hostname = String(window.location?.hostname || "").toLowerCase();
  return hostname === "localhost" || hostname === "127.0.0.1" || hostname === "::1" || hostname === "[::1]";
}

function getSpeechRecognitionConstructor() {
  if (typeof window === "undefined") {
    return null;
  }

  const RecognitionCtor = window.SpeechRecognition || window.webkitSpeechRecognition;
  return typeof RecognitionCtor === "function" ? RecognitionCtor : null;
}

function isLocalVoiceInputSupported() {
  if (typeof window === "undefined" || !isMicrophoneContextAllowed()) {
    return false;
  }

  return Boolean(getSpeechRecognitionConstructor());
}

function resolveSpeechRecognitionLanguage() {
  const appLocale = String(locale?.value || "").toLowerCase();
  if (appLocale.startsWith("ru")) {
    return "ru-RU";
  }
  if (appLocale.startsWith("en")) {
    return "en-US";
  }

  const userLanguage = String(session.value?.user?.language || "").toLowerCase();
  if (
    userLanguage === "rus"
    || userLanguage === "ru"
    || userLanguage === "russian"
    || userLanguage.startsWith("ru")
  ) {
    return "ru-RU";
  }
  if (
    userLanguage === "eng"
    || userLanguage === "en"
    || userLanguage === "english"
    || userLanguage.startsWith("en")
  ) {
    return "en-US";
  }

  if (typeof navigator !== "undefined" && Array.isArray(navigator.languages)) {
    const preferred = navigator.languages.find((item) => typeof item === "string" && item.trim() !== "");
    if (preferred) {
      return preferred;
    }
  }

  if (typeof navigator !== "undefined" && typeof navigator.language === "string" && navigator.language.trim() !== "") {
    return navigator.language;
  }

  return "en-US";
}

function appendTranscriptToDraft(draftInput, transcriptInput) {
  const transcript = String(transcriptInput || "").trim();
  if (!transcript) {
    return String(draftInput || "");
  }

  const draft = String(draftInput || "").trim();
  return draft !== "" ? `${draft} ${transcript}` : transcript;
}

function applyChatVoiceTranscript(transcriptInput, baseDraftInput = chatVoiceBaseDraft) {
  chatDraft.value = appendTranscriptToDraft(baseDraftInput, transcriptInput);
  focusChatInput();
}

function applyAceLineCommentVoiceTranscript(transcriptInput, baseDraftInput = aceLineCommentVoiceBaseDraft) {
  aceLineCommentDraft.value = appendTranscriptToDraft(baseDraftInput, transcriptInput);
  focusAceLineCommentInput();
}

function mapSpeechRecognitionErrorCode(errorCodeInput) {
  const code = String(errorCodeInput || "").trim().toLowerCase();

  if (code === "not-allowed" || code === "service-not-allowed") {
    return t("editor.chatVoicePermissionDenied");
  }
  if (code === "not-readable" || code === "aborted") {
    return t("editor.chatVoiceMicrophoneBusy");
  }
  if (code === "audio-capture") {
    return t("editor.chatVoiceNoMicrophone");
  }
  if (code === "language-not-supported" || code === "bad-grammar") {
    return t("editor.chatVoiceLanguageUnsupported");
  }
  if (code === "network") {
    return t("editor.chatVoiceNetworkError");
  }
  if (code === "no-speech") {
    return t("editor.chatVoiceNoSpeech");
  }

  return t("editor.chatVoiceError");
}

function createSpeechRecognitionInstance() {
  const RecognitionCtor = getSpeechRecognitionConstructor();
  if (!RecognitionCtor) {
    return null;
  }

  const recognition = new RecognitionCtor();
  recognition.continuous = false;
  recognition.interimResults = false;
  recognition.maxAlternatives = 1;
  recognition.lang = resolveSpeechRecognitionLanguage();

  return recognition;
}

function extractSpeechRecognitionTranscript(eventInput) {
  const event = eventInput;
  if (!event?.results) {
    return "";
  }

  const parts = [];
  const startIndex = Math.max(0, Number(event.resultIndex || 0));
  for (let index = startIndex; index < event.results.length; index += 1) {
    const result = event.results[index];
    if (!result || result.isFinal !== true || !result[0] || typeof result[0].transcript !== "string") {
      continue;
    }
    const value = result[0].transcript.trim();
    if (value) {
      parts.push(value);
    }
  }

  return parts.join(" ").trim();
}

function clearChatVoiceRecognitionState(options = {}) {
  const keepBaseDraft = options.keepBaseDraft === true;
  chatVoiceAbortRequested = false;
  chatVoiceListening.value = false;
  chatVoiceRecognition = null;
  if (!keepBaseDraft) {
    chatVoiceBaseDraft = "";
  }
}

function clearAceLineCommentVoiceRecognitionState(options = {}) {
  const keepBaseDraft = options.keepBaseDraft === true;
  aceLineCommentVoiceAbortRequested = false;
  aceLineCommentVoiceListening.value = false;
  aceLineCommentVoiceRecognition = null;
  if (!keepBaseDraft) {
    aceLineCommentVoiceBaseDraft = "";
  }
}

function startChatVoiceRecognitionInput() {
  const recognition = createSpeechRecognitionInstance();
  if (!recognition) {
    return false;
  }

  chatVoiceBaseDraft = String(chatDraft.value || "");
  chatVoiceAbortRequested = false;
  chatVoiceRecognition = recognition;
  let hasTranscript = false;

  recognition.onresult = (event) => {
    if (chatVoiceAbortRequested) {
      return;
    }

    const transcript = extractSpeechRecognitionTranscript(event);
    if (!transcript) {
      return;
    }

    hasTranscript = true;
    applyChatVoiceTranscript(transcript, chatVoiceBaseDraft);
  };

  recognition.onerror = (event) => {
    const code = String(event?.error || "").trim().toLowerCase();
    if (chatVoiceAbortRequested && code === "aborted") {
      return;
    }

    if (code !== "aborted") {
      error.value = mapSpeechRecognitionErrorCode(code);
    }
  };

  recognition.onend = () => {
    const wasAborted = chatVoiceAbortRequested;
    clearChatVoiceRecognitionState({ keepBaseDraft: true });
    if (!wasAborted && !hasTranscript) {
      error.value = t("editor.chatVoiceNoSpeech");
    }
    chatVoiceBaseDraft = "";
  };

  try {
    error.value = "";
    recognition.start();
    chatVoiceListening.value = true;
    return true;
  } catch (_error) {
    clearChatVoiceRecognitionState();
    return false;
  }
}

function startAceLineCommentVoiceRecognitionInput() {
  const recognition = createSpeechRecognitionInstance();
  if (!recognition) {
    return false;
  }

  aceLineCommentVoiceBaseDraft = String(aceLineCommentDraft.value || "");
  aceLineCommentVoiceAbortRequested = false;
  aceLineCommentVoiceRecognition = recognition;
  let hasTranscript = false;

  recognition.onresult = (event) => {
    if (aceLineCommentVoiceAbortRequested) {
      return;
    }

    const transcript = extractSpeechRecognitionTranscript(event);
    if (!transcript) {
      return;
    }

    hasTranscript = true;
    applyAceLineCommentVoiceTranscript(transcript, aceLineCommentVoiceBaseDraft);
  };

  recognition.onerror = (event) => {
    const code = String(event?.error || "").trim().toLowerCase();
    if (aceLineCommentVoiceAbortRequested && code === "aborted") {
      return;
    }

    if (code !== "aborted") {
      error.value = mapSpeechRecognitionErrorCode(code);
    }
  };

  recognition.onend = () => {
    const wasAborted = aceLineCommentVoiceAbortRequested;
    clearAceLineCommentVoiceRecognitionState({ keepBaseDraft: true });
    if (!wasAborted && !hasTranscript) {
      error.value = t("editor.chatVoiceNoSpeech");
    }
    aceLineCommentVoiceBaseDraft = "";
  };

  try {
    error.value = "";
    recognition.start();
    aceLineCommentVoiceListening.value = true;
    return true;
  } catch (_error) {
    clearAceLineCommentVoiceRecognitionState();
    return false;
  }
}

// MediaRecorder fallback for HTTP contexts and browsers without Web Speech API
// Unused: This function is not currently integrated into the UI
/* Unused: This function is not currently integrated into the UI
async function startChatVoiceRecorderInput() {
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    const mimeType = MediaRecorder.isTypeSupported("audio/webm;codecs=opus")
      ? "audio/webm;codecs=opus"
      : "audio/wav";

    const recorder = new MediaRecorder(stream, { mimeType });
    chatVoiceAudioChunks = [];

    recorder.ondataavailable = (event) => {
      if (event.data.size > 0) {
        chatVoiceAudioChunks.push(event.data);
      }
    };

    recorder.onstart = () => {
      chatVoiceListening.value = true;
      error.value = "";
    };

    recorder.onstop = async () => {
      chatVoiceListening.value = false;
      const audioBlob = new Blob(chatVoiceAudioChunks, { type: mimeType });
      chatVoiceAudioChunks = [];

      if (audioBlob.size === 0) {
        error.value = t("editor.chatVoiceNoSpeech");
        return;
      }

      await transcribeAndApplyChatVoice(audioBlob);

      for (const track of stream.getTracks()) {
        track.stop();
      }
    };

    recorder.onerror = (event) => {
      chatVoiceListening.value = false;
      error.value = mapSpeechRecognitionErrorCode(event.error || "audio-capture");
      chatVoiceAudioChunks = [];

      for (const track of stream.getTracks()) {
        track.stop();
      }
    };

    recorder.start();
    return true;
  } catch (err) {
    const errorName = String(err?.name || "audio-capture").toLowerCase();
    error.value = errorName === "notallowederror"
      ? t("editor.chatVoicePermissionDenied")
      : mapSpeechRecognitionErrorCode("audio-capture");
    chatVoiceAudioChunks = [];
    return false;
  }
}
*/

/* Unused: These functions are only called from commented functions above
async function transcribeAndApplyChatVoice(audioBlob) {
  if (!selectedProjectId.value) {
    error.value = t("common.requestFailed");
    return;
  }

  const formData = new FormData();
  formData.append("audio", audioBlob, "audio.webm");
  formData.append("language", resolveSpeechRecognitionLanguage());

  try {
    const response = await fetch(
      `/api/projects/${selectedProjectId.value}/speech/transcribe`,
      {
        method: "POST",
        body: formData,
        headers: {
          "Authorization": `Bearer ${getAccessToken()}`,
        },
      }
    );

    if (!response.ok) {
      error.value = t("editor.chatVoiceTranscriptionFailed");
      return;
    }

    const data = await response.json();
    const transcript = String(data.transcript || "").trim();

    if (!transcript) {
      error.value = t("editor.chatVoiceNoSpeech");
      return;
    }

    applyChatVoiceTranscript(transcript, chatVoiceBaseDraft);
  } catch (err) {
    error.value = t("editor.chatVoiceNetworkError");
  }
}

/* Unused: This function is not currently integrated into the UI
async function startAceLineCommentVoiceRecorderInput() {
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    const mimeType = MediaRecorder.isTypeSupported("audio/webm;codecs=opus")
      ? "audio/webm;codecs=opus"
      : "audio/wav";

    const recorder = new MediaRecorder(stream, { mimeType });
    aceLineCommentVoiceAudioChunks = [];

    recorder.ondataavailable = (event) => {
      if (event.data.size > 0) {
        aceLineCommentVoiceAudioChunks.push(event.data);
      }
    };

    recorder.onstart = () => {
      aceLineCommentVoiceListening.value = true;
      error.value = "";
    };

    recorder.onstop = async () => {
      aceLineCommentVoiceListening.value = false;
      const audioBlob = new Blob(aceLineCommentVoiceAudioChunks, { type: mimeType });
      aceLineCommentVoiceMediaRecorder = null;
      aceLineCommentVoiceAudioChunks = [];

      if (audioBlob.size === 0) {
        error.value = t("editor.chatVoiceNoSpeech");
        return;
      }

      // Send audio to server for transcription
      await transcribeAndApplyAceLineCommentVoice(audioBlob);

      // Stop audio stream
      for (const track of stream.getTracks()) {
        track.stop();
      }
    };

    recorder.onerror = (event) => {
      aceLineCommentVoiceListening.value = false;
      error.value = mapSpeechRecognitionErrorCode(event.error || "audio-capture");
      aceLineCommentVoiceAudioChunks = [];

      for (const track of stream.getTracks()) {
        track.stop();
      }
    };

    recorder.start();
    return true;
  } catch (err) {
    const errorName = String(err?.name || "audio-capture").toLowerCase();
    error.value = errorName === "notallowederror"
      ? t("editor.chatVoicePermissionDenied")
      : mapSpeechRecognitionErrorCode("audio-capture");
    aceLineCommentVoiceAudioChunks = [];
    return false;
  }
}
*/

/* continuing comments...
async function transcribeAndApplyAceLineCommentVoice(audioBlob) {
  if (!selectedProjectId.value) {
    error.value = t("common.requestFailed");
    return;
  }

  const formData = new FormData();
  formData.append("audio", audioBlob, "audio.webm");
  formData.append("language", resolveSpeechRecognitionLanguage());

  try {
    const response = await fetch(
      `/api/projects/${selectedProjectId.value}/speech/transcribe`,
      {
        method: "POST",
        body: formData,
        headers: {
          "Authorization": `Bearer ${getAccessToken()}`,
        },
      }
    );

    if (!response.ok) {
      error.value = t("editor.chatVoiceTranscriptionFailed");
      return;
    }

    const data = await response.json();
    const transcript = String(data.transcript || "").trim();

    if (!transcript) {
      error.value = t("editor.chatVoiceNoSpeech");
      return;
    }

    applyAceLineCommentVoiceTranscript(transcript, aceLineCommentVoiceBaseDraft);
  } catch (err) {
    error.value = t("editor.chatVoiceNetworkError");
  }
}
*/

async function startChatVoiceInput() {
  if (chatVoiceListening.value) {
    return;
  }

  stopAceLineCommentVoiceInput({ discard: true });

  if (!isMicrophoneContextAllowed()) {
    error.value = t("editor.chatVoiceSecureContextRequired");
    return;
  }

  if (isLocalVoiceInputSupported() && startChatVoiceRecognitionInput()) {
    return;
  }

  chatVoiceSupported.value = false;
  error.value = t("editor.chatVoiceUnsupported");
}

function stopChatVoiceInput(options = {}) {
  const discard = options.discard === true;

  if (!chatVoiceRecognition) {
    clearChatVoiceRecognitionState();
    if (discard) {
      chatVoiceBaseDraft = "";
    }
    return;
  }

  chatVoiceAbortRequested = discard;
  const recognition = chatVoiceRecognition;

  if (discard) {
    chatVoiceBaseDraft = "";
  }

  try {
    recognition.stop();
  } catch (_error) {
    clearChatVoiceRecognitionState();
  }
}

function toggleChatVoiceInput() {
  if (chatVoiceListening.value) {
    stopChatVoiceInput();
    return;
  }

  void startChatVoiceInput();
}

function stopAceLineCommentVoiceInput(options = {}) {
  const discard = options.discard === true;

  if (!aceLineCommentVoiceRecognition) {
    clearAceLineCommentVoiceRecognitionState();
    if (discard) {
      aceLineCommentVoiceBaseDraft = "";
    }
    return;
  }

  aceLineCommentVoiceAbortRequested = discard;
  const recognition = aceLineCommentVoiceRecognition;

  if (discard) {
    aceLineCommentVoiceBaseDraft = "";
  }

  try {
    recognition.stop();
  } catch (_error) {
    clearAceLineCommentVoiceRecognitionState();
  }
}

async function startAceLineCommentVoiceInput() {
  if (!aceLineCommentPopover.open || aceLineCommentVoiceListening.value) {
    return;
  }

  stopChatVoiceInput({ discard: true });

  if (!isMicrophoneContextAllowed()) {
    error.value = t("editor.chatVoiceSecureContextRequired");
    return;
  }

  if (isLocalVoiceInputSupported() && startAceLineCommentVoiceRecognitionInput()) {
    return;
  }

  chatVoiceSupported.value = false;
  error.value = t("editor.chatVoiceUnsupported");
}

function toggleAceLineCommentVoiceInput() {
  if (aceLineCommentVoiceListening.value) {
    stopAceLineCommentVoiceInput();
    return;
  }

  void startAceLineCommentVoiceInput();
}

async function loadRealtimeChat(force = false, fullSnapshot = false) {
  if (!canUseProjectFs.value || !selectedProjectId.value || chatLoading) {
    return;
  }

  if (!force && !liveSyncEnabled.value) {
    return;
  }

  const afterId = fullSnapshot
    ? 0
    : (chatMessages.value.length > 0 ? Number(chatMessages.value[chatMessages.value.length - 1].id || 0) : 0);
  chatLoading = true;

  try {
    const response = await request({
      method: "GET",
      path: `/projects/${selectedProjectId.value}/realtime/chat`,
      auth: true,
      query: {
        after_id: afterId > 0 ? afterId : undefined,
        limit: 120,
      },
    });

    const incoming = Array.isArray(response.data?.messages) ? response.data.messages : [];
    if (fullSnapshot || afterId <= 0) {
      replaceChatMessages(incoming);
      return;
    }

    incoming.forEach((item) => upsertChatMessage(item));
  } catch (_chatError) {
    // Chat polling is best-effort.
  } finally {
    chatLoading = false;
  }
}

async function sendChatMessage() {
  if (!canUseProjectFs.value || !selectedProjectId.value || chatSending.value) {
    return;
  }

  const text = chatDraftTrimmed.value;
  if (!text) {
    return;
  }

  chatSending.value = true;
  error.value = "";
  const editingMessageId = Number(chatEditingMessageId.value || 0);
  const editingMode = editingMessageId > 0;

  try {
    const response = await request({
      method: editingMode ? "PATCH" : "POST",
      path: editingMode
        ? `/projects/${selectedProjectId.value}/realtime/chat/${editingMessageId}`
        : `/projects/${selectedProjectId.value}/realtime/chat`,
      auth: true,
      body: {
        message: text,
      },
    });

    upsertChatMessage(response.data?.message);
    chatEditingMessageId.value = 0;
    chatDraft.value = "";
  } catch (chatError) {
    error.value = readError(chatError);
  } finally {
    chatSending.value = false;
  }
}

async function deleteChatMessage(messageInput) {
  if (!canUseProjectFs.value || !selectedProjectId.value) {
    return;
  }

  const normalized = normalizeChatMessage(messageInput);
  if (!normalized || normalized.user_id !== currentUserId.value) {
    return;
  }

  const messageId = Number(normalized.id || 0);
  if (!messageId) {
    return;
  }

  chatDeletingMessageId.value = messageId;
  error.value = "";

  try {
    await request({
      method: "DELETE",
      path: `/projects/${selectedProjectId.value}/realtime/chat/${messageId}`,
      auth: true,
    });

    removeChatMessageById(messageId);
  } catch (chatError) {
    error.value = readError(chatError);
  } finally {
    if (chatDeletingMessageId.value === messageId) {
      chatDeletingMessageId.value = 0;
    }
  }
}

function normalizeRemoteOperationEnvelope(rawPayload) {
  const payload = rawPayload || {};
  const operationRaw = normalizeTextOperation(payload.operation || {});

  return {
    revision: Math.max(0, Number(payload.revision || 0)),
    path: String(payload.path || activeProjectPath.value || ""),
    client_id: String(payload.client_id || operationRaw.client_id || ""),
    op_id: String(payload.op_id || operationRaw.op_id || ""),
    user_id: Number(payload.user_id || 0),
    operation: operationRaw,
    cursor_row: payload.cursor_row === null || payload.cursor_row === undefined ? undefined : Number(payload.cursor_row),
    cursor_column: payload.cursor_column === null || payload.cursor_column === undefined ? undefined : Number(payload.cursor_column),
  };
}

function positionToIndex(position) {
  const editor = editorInstance.value;
  if (!editor) {
    return 0;
  }

  const doc = editor.session?.getDocument?.();
  if (doc && typeof doc.positionToIndex === "function") {
    return Number(doc.positionToIndex(position, 0) || 0);
  }

  return 0;
}

function getDeltaText(delta, session) {
  if (Array.isArray(delta.lines) && delta.lines.length > 0) {
    const joined = delta.lines.join("\n");
    if (joined !== "" || delta.start.row === delta.end.row) {
      return joined;
    }
  }
  if (session && delta.start && delta.end) {
    const range = new AceRange(
      delta.start.row, delta.start.column,
      delta.end.row, delta.end.column
    );
    return session.getTextRange(range);
  }
  return Array.isArray(delta.lines) ? delta.lines.join("\n") : "";
}

function deltaToOperation(deltaInput) {
  const delta = deltaInput || {};
  const action = String(delta.action || "");
  const start = positionToIndex(delta.start || { row: 0, column: 0 });
  const session = editorInstance.value?.session;
  const text = getDeltaText(delta, session);

  if (action === "insert") {
    return {
      start: Math.max(0, start),
      delete_count: 0,
      insert_text: text,
    };
  }

  if (action === "remove") {
    return {
      start: Math.max(0, start),
      delete_count: Math.max(0, text.length),
      insert_text: "",
    };
  }

  return {
    start: 0,
    delete_count: 0,
    insert_text: "",
  };
}

function applyEditorTextOperation(operationInput) {
  const operation = normalizeTextOperation(operationInput || {});
  if (isNoopTextOperation(operation)) {
    return;
  }

  const editor = editorInstance.value;
  if (!editor) {
    currentText.value = applyTextOperation(currentText.value, operation);
    dirty.value = false;
    return;
  }

  const sessionRef = editor.session;
  const doc = sessionRef.getDocument();

  applyingRemoteEditorChange = true;
  syncingEditor.value = true;

  try {
    const startPos = doc.indexToPosition(operation.start, 0);
    const endPos = doc.indexToPosition(operation.start + operation.delete_count, 0);

    if (operation.delete_count > 0) {
      const removeRange = new AceRange(startPos.row, startPos.column, endPos.row, endPos.column);
      sessionRef.remove(removeRange);
    }

    if (operation.insert_text !== "") {
      const insertPos = doc.indexToPosition(operation.start, 0);
      sessionRef.insert(insertPos, operation.insert_text);
    }

    currentText.value = editor.getValue();
    dirty.value = false;
  } finally {
    syncingEditor.value = false;
    applyingRemoteEditorChange = false;
  }
}

function applyRemoteEditorOperation(rawPayload) {
  const envelope = normalizeRemoteOperationEnvelope(rawPayload);

  if (!envelope.path || envelope.path !== activeProjectPath.value) {
    return;
  }

  if (!envelope.revision || envelope.revision <= editorSyncRevision.value) {
    return;
  }

  if (isNoopTextOperation(envelope.operation)) {
    editorSyncRevision.value = Math.max(editorSyncRevision.value, envelope.revision);
    return;
  }

  if (editorSyncInflightOp && envelope.op_id && envelope.op_id === editorSyncInflightOp.op_id) {
    editorSyncRevision.value = Math.max(editorSyncRevision.value, envelope.revision);
    editorSyncInflightOp = null;
    editorSyncBusy.value = false;
    applyDeferredRemoteEditorOperations();
    if (editorSyncPendingOps.length > 0) {
      scheduleEditorSync();
    }

    return;
  }

  if (envelope.revision > (editorSyncRevision.value + 1)) {
    editorDeferredRemoteOps.push(envelope);
    editorDeferredRemoteOps.sort((left, right) => left.revision - right.revision);
    return;
  }

  let transformedRemote = {
    ...envelope.operation,
    client_id: envelope.client_id,
    op_id: envelope.op_id,
  };

  if (editorSyncInflightOp) {
    const pair = transformConcurrentTextOperations(
      transformedRemote,
      editorSyncInflightOp.operation,
    );

    transformedRemote = {
      ...pair.remote,
      client_id: envelope.client_id,
      op_id: envelope.op_id,
    };

    editorSyncInflightOp = {
      ...editorSyncInflightOp,
      operation: {
        ...pair.local,
        client_id: editorSyncInflightOp.operation.client_id,
        op_id: editorSyncInflightOp.operation.op_id,
      },
    };
  }

  editorSyncPendingOps = editorSyncPendingOps.map((pendingOperation) => {
    const pair = transformConcurrentTextOperations(
      transformedRemote,
      pendingOperation.operation,
    );

    transformedRemote = {
      ...pair.remote,
      client_id: envelope.client_id,
      op_id: envelope.op_id,
    };

    return {
      ...pendingOperation,
      operation: {
        ...pair.local,
        client_id: pendingOperation.operation.client_id,
        op_id: pendingOperation.operation.op_id,
      },
    };
  });

  applyEditorTextOperation(transformedRemote);
  editorSyncRevision.value = envelope.revision;
  applyDeferredRemoteEditorOperations();

  if (envelope.user_id && envelope.user_id !== currentUserId.value) {
    const existingPeer = realtimePeers.value.find((p) => p.user_id === envelope.user_id);
    if (existingPeer) {
      upsertPresencePeer({
        ...existingPeer,
        cursor_row: envelope.cursor_row !== undefined ? envelope.cursor_row : existingPeer.cursor_row,
        cursor_column: envelope.cursor_column !== undefined ? envelope.cursor_column : existingPeer.cursor_column,
      });
    }
  }
}

function applyDeferredRemoteEditorOperations() {
  if (editorSyncInflightOp || editorDeferredRemoteOps.length === 0) {
    return;
  }

  editorDeferredRemoteOps.sort((left, right) => left.revision - right.revision);

  while (editorDeferredRemoteOps.length > 0) {
    const nextRevision = editorSyncRevision.value + 1;
    const index = editorDeferredRemoteOps.findIndex(
      (entry) => entry.revision === nextRevision
    );

    if (index === -1) {
      break;
    }

    const entry = editorDeferredRemoteOps.splice(index, 1)[0];
    applyRemoteEditorOperation(entry);
  }
}

function resetEditorSyncState() {
  editorSyncRevision.value = 0;
  editorSyncBusy.value = false;
  editorSyncPendingOps = [];
  editorSyncInflightOp = null;
  editorDeferredRemoteOps = [];
}

function ensureEditorSyncPathState(path) {
  if (!path || !activeProjectPath.value || path !== activeProjectPath.value) {
    resetEditorSyncState();
  }
}

function mergeQueuedEditorOperations(previousInput, nextInput) {
  const previous = normalizeTextOperation(previousInput || {});
  const next = normalizeTextOperation(nextInput || {});

  if (!previous.client_id || previous.client_id !== next.client_id) {
    return null;
  }

  const previousInsertOnly = previous.delete_count === 0;
  const nextInsertOnly = next.delete_count === 0;

  if (previousInsertOnly && nextInsertOnly) {
    if ((previous.start + previous.insert_text.length) !== next.start) {
      return null;
    }

    return {
      ...previous,
      insert_text: `${previous.insert_text}${next.insert_text}`,
    };
  }

  const previousDeleteOnly = previous.insert_text === "" && previous.delete_count > 0;
  const nextDeleteOnly = next.insert_text === "" && next.delete_count > 0;
  if (!previousDeleteOnly || !nextDeleteOnly) {
    return null;
  }

  // Delete key: repeated removals from the same cursor index.
  if (next.start === previous.start) {
    return {
      ...previous,
      delete_count: previous.delete_count + next.delete_count,
    };
  }

  // Backspace: each next delete expands the range to the left.
  if ((next.start + next.delete_count) === previous.start) {
    return {
      ...previous,
      start: next.start,
      delete_count: previous.delete_count + next.delete_count,
    };
  }

  return null;
}

function queueLocalEditorOperation(deltaInput) {
  if (applyingRemoteEditorChange) {
    dirty.value = false;
    return;
  }

  if (!canUseProjectFs.value || !liveSyncEnabled.value || !activeProjectPath.value) {
    return;
  }

  const baseOperation = deltaToOperation(deltaInput);
  if (isNoopTextOperation(baseOperation)) {
    return;
  }

  const operation = normalizeTextOperation({
    ...baseOperation,
    client_id: realtimeClientId.value,
    op_id: createRealtimeOperationId(),
  });

  const lastIndex = editorSyncPendingOps.length - 1;
  const previous = editorSyncPendingOps[lastIndex];
  const mergedOperation = previous
    ? mergeQueuedEditorOperations(previous.operation, operation)
    : null;

  if (previous && mergedOperation) {
    editorSyncPendingOps[lastIndex] = {
      ...previous,
      operation: mergedOperation,
    };
  } else {
    editorSyncPendingOps.push({
      path: activeProjectPath.value,
      base_revision: editorSyncRevision.value,
      operation,
      created_at: Date.now(),
      op_id: operation.op_id,
    });
  }

  const isDeleteOperation = operation.insert_text === "" && operation.delete_count > 0;
  scheduleEditorSync(isDeleteOperation ? EDITOR_SYNC_DELETE_DEBOUNCE_MS : EDITOR_SYNC_DEBOUNCE_MS);
  schedulePresenceSync(160);
}

function buildReplaceOperation(fromTextInput, toTextInput) {
  const fromText = String(fromTextInput || "");
  const toText = String(toTextInput || "");

  if (fromText === toText) {
    return null;
  }

  let prefix = 0;
  const minLength = Math.min(fromText.length, toText.length);
  while (prefix < minLength && fromText[prefix] === toText[prefix]) {
    prefix += 1;
  }

  let suffix = 0;
  while (
    suffix < (minLength - prefix)
    && fromText[fromText.length - 1 - suffix] === toText[toText.length - 1 - suffix]
  ) {
    suffix += 1;
  }

  const deleteCount = fromText.length - prefix - suffix;
  const insertText = toText.slice(prefix, toText.length - suffix);

  return normalizeTextOperation({
    start: prefix,
    delete_count: Math.max(0, deleteCount),
    insert_text: insertText,
    client_id: realtimeClientId.value,
    op_id: createRealtimeOperationId(),
  });
}

async function bootstrapEditorRealtimeState(seedContent = "", options = {}) {
  const normalizedOptions = options || {};
  const includeSeedContent = normalizedOptions.includeSeedContent !== false;
  if (!canUseProjectFs.value || !selectedProjectId.value || !activeProjectPath.value) {
    resetEditorSyncState();
    return;
  }

  try {
    const body = {
      path: activeProjectPath.value,
      reset: normalizedOptions.reset === true,
    };
    if (includeSeedContent || normalizedOptions.reset === true) {
      body.seed_content = String(seedContent || "");
    }

    const response = await request({
      method: "POST",
      path: `/projects/${selectedProjectId.value}/realtime/editor-state`,
      auth: true,
      body,
    });

    const remoteRevision = Math.max(0, Number(response.data?.revision || 0));
    const remoteContent = String(response.data?.content || "");
    const localContent = String(currentText.value || "");
    const preserveLocal = normalizedOptions.preserveLocal === true;

    resetEditorSyncState();
    editorSyncRevision.value = remoteRevision;

    if (preserveLocal && localContent !== remoteContent) {
      const replacement = buildReplaceOperation(remoteContent, localContent);
      if (replacement && !isNoopTextOperation(replacement)) {
        editorSyncPendingOps.push({
          path: activeProjectPath.value,
          base_revision: remoteRevision,
          operation: replacement,
          created_at: Date.now(),
          op_id: replacement.op_id,
        });
        scheduleEditorSync();
        return;
      }
    }

    if (localContent !== remoteContent) {
      syncingEditor.value = true;
      applyingRemoteEditorChange = true;
      try {
        currentText.value = remoteContent;
        syncEditor();
      } finally {
        syncingEditor.value = false;
        applyingRemoteEditorChange = false;
      }
    }

    dirty.value = false;
  } catch (syncError) {
    error.value = readError(syncError);
  }
}

function scheduleEditorSync(delay = EDITOR_SYNC_DEBOUNCE_MS) {
  if (!canUseProjectFs.value || !liveSyncEnabled.value || !activeProjectPath.value) {
    return;
  }

  if (editorSyncDebounceTimerId !== null && typeof window !== "undefined") {
    window.clearTimeout(editorSyncDebounceTimerId);
    editorSyncDebounceTimerId = null;
  }

  if (typeof window === "undefined") {
    void flushEditorSyncQueue();
    return;
  }

  editorSyncDebounceTimerId = window.setTimeout(() => {
    editorSyncDebounceTimerId = null;
    void flushEditorSyncQueue();
  }, Math.max(0, Number(delay || EDITOR_SYNC_DEBOUNCE_MS)));
}

async function flushEditorSyncQueue() {
  if (!canUseProjectFs.value || !liveSyncEnabled.value || !activeProjectPath.value) {
    return;
  }

  if (editorSyncBusy.value || editorSyncInflightOp || editorSyncPendingOps.length === 0) {
    return;
  }

  const nextOperation = editorSyncPendingOps.shift();
  if (!nextOperation) {
    return;
  }

  if (nextOperation.path !== activeProjectPath.value) {
    return;
  }

  editorSyncBusy.value = true;
  editorSyncInflightOp = {
    ...nextOperation,
    base_revision: editorSyncRevision.value,
  };

  try {
    const editorCursor = editorInstance.value?.getCursorPosition?.();
    const payload = {
      path: activeProjectPath.value,
      client_id: nextOperation.operation.client_id,
      op_id: nextOperation.operation.op_id,
      base_revision: editorSyncRevision.value,
      start: nextOperation.operation.start,
      delete_count: nextOperation.operation.delete_count,
      insert_text: nextOperation.operation.insert_text,
      cursor_row: editorCursor ? Number(editorCursor.row) : undefined,
      cursor_column: editorCursor ? Number(editorCursor.column) : undefined,
    };

    const response = await request({
      method: "POST",
      path: `/projects/${selectedProjectId.value}/realtime/editor-sync`,
      auth: true,
      body: payload,
    });

    editorSyncRevision.value = Math.max(
      editorSyncRevision.value,
      Number(response.data?.revision || (editorSyncRevision.value + 1)),
    );
    editorSyncInflightOp = null;
    editorSyncBusy.value = false;
    applyDeferredRemoteEditorOperations();

    if (editorSyncPendingOps.length > 0) {
      scheduleEditorSync();
    }
  } catch (syncError) {
    if (syncError?.status === 409) {
      const requiresResync = Boolean(syncError?.data?.requires_resync);
      const operations = Array.isArray(syncError?.data?.operations) ? syncError.data.operations : [];
      const remoteRevision = Math.max(0, Number(syncError?.data?.revision || editorSyncRevision.value));

      if (requiresResync) {
        const localSnapshot = String(currentText.value || "");
        await bootstrapEditorRealtimeState(localSnapshot, {
          preserveLocal: true,
          includeSeedContent: false,
        });
        editorSyncBusy.value = false;
        editorSyncInflightOp = null;
        return;
      }

      editorSyncBusy.value = false;
      const sortedOperations = [...operations].sort((left, right) => {
        return Number(left?.revision || 0) - Number(right?.revision || 0);
      });
      sortedOperations.forEach((entry) => {
        applyRemoteEditorOperation(entry);
      });
      editorSyncRevision.value = Math.max(editorSyncRevision.value, remoteRevision);

      if (editorSyncInflightOp) {
        editorSyncPendingOps = [
          {
            ...editorSyncInflightOp,
            base_revision: editorSyncRevision.value,
          },
          ...editorSyncPendingOps,
        ];
        editorSyncInflightOp = null;
      }

      if (editorSyncPendingOps.length > 0) {
        scheduleEditorSync();
      }

      return;
    }

    if (editorSyncInflightOp) {
      editorSyncPendingOps = [editorSyncInflightOp, ...editorSyncPendingOps];
      editorSyncInflightOp = null;
    }

    editorSyncBusy.value = false;
    if (typeof window !== "undefined" && editorSyncRetryTimerId === null) {
      editorSyncRetryTimerId = window.setTimeout(() => {
        editorSyncRetryTimerId = null;
        if (editorSyncPendingOps.length > 0) {
          scheduleEditorSync();
        }
      }, 800);
    }
  }
}

function scheduleRealtimeSubscriptionWatchdog() {
  if (typeof window === "undefined") {
    return;
  }

  if (realtimeSubscribeWatchTimerId !== null) {
    window.clearTimeout(realtimeSubscribeWatchTimerId);
    realtimeSubscribeWatchTimerId = null;
  }

  realtimeSubscribeWatchTimerId = window.setTimeout(() => {
    realtimeSubscribeWatchTimerId = null;
    if (!realtimeChannelSubscribed && canUseProjectFs.value) {
      const forceRestart = realtimeSubscribeAttempts > 0;
      realtimeSubscribeAttempts += 1;
      void startRealtimeSession({ forceRestart });
    }
  }, 2500);
}

function clearRealtimeTimers() {
  if (typeof window === "undefined") {
    return;
  }

  if (presenceIntervalTimerId !== null) {
    window.clearInterval(presenceIntervalTimerId);
    presenceIntervalTimerId = null;
  }

  if (presenceDebounceTimerId !== null) {
    window.clearTimeout(presenceDebounceTimerId);
    presenceDebounceTimerId = null;
  }

  if (presencePruneTimerId !== null) {
    window.clearInterval(presencePruneTimerId);
    presencePruneTimerId = null;
  }

  if (chatFallbackPollTimerId !== null) {
    window.clearInterval(chatFallbackPollTimerId);
    chatFallbackPollTimerId = null;
  }

  if (editorSyncDebounceTimerId !== null) {
    window.clearTimeout(editorSyncDebounceTimerId);
    editorSyncDebounceTimerId = null;
  }

  if (editorSyncRetryTimerId !== null) {
    window.clearTimeout(editorSyncRetryTimerId);
    editorSyncRetryTimerId = null;
  }

  if (realtimeSubscribeWatchTimerId !== null) {
    window.clearTimeout(realtimeSubscribeWatchTimerId);
    realtimeSubscribeWatchTimerId = null;
  }
}

function stopRealtimeSession() {
  clearRealtimeTimers();

  if (activeRealtimeProjectId) {
    leaveProjectRealtimeChannel(activeRealtimeProjectId);
  }

  activeRealtimeProjectId = "";
  realtimeChannel = null;
  realtimeChannelSubscribed = false;
  realtimeSubscribeAttempts = 0;
}

function resetRealtimeState() {
  stopRealtimeSession();
  resetEditorSyncState();
  stopChatVoiceInput({ discard: true });
  stopAceLineCommentVoiceInput({ discard: true });
  realtimePeers.value = [];
  chatMessages.value = [];
  chatDraft.value = "";
  chatEditingMessageId.value = 0;
  chatDeletingMessageId.value = 0;
  clearAllPeerMarkers();
}

async function startRealtimeSession(options = {}) {
  const forceRestart = Boolean(options?.forceRestart);

  if (!canUseProjectFs.value || !selectedProjectId.value || !isAuthenticated.value) {
    resetRealtimeState();
    return;
  }

  const token = String(getSession().accessToken || "");
  if (!token) {
    resetRealtimeState();
    return;
  }

  if (!forceRestart && activeRealtimeProjectId && activeRealtimeProjectId === String(selectedProjectId.value) && realtimeChannel) {
    if (!realtimeChannelSubscribed) {
      scheduleRealtimeSubscriptionWatchdog();
    }
    return;
  }

  stopRealtimeSession();
  if (forceRestart) {
    disconnectRealtimeClient();
  }
  clearAllPeerMarkers();
  realtimePeers.value = [];
  activeRealtimeProjectId = String(selectedProjectId.value);
  realtimeChannelSubscribed = false;
  realtimeSubscribeAttempts = 0;

  const channel = joinProjectRealtimeChannel(selectedProjectId.value, token);
  if (!channel) {
    scheduleRealtimeSubscriptionWatchdog();
    return;
  }

  realtimeChannel = channel;
  scheduleRealtimeSubscriptionWatchdog();

  channel.subscribed(() => {
    realtimeChannelSubscribed = true;
    realtimeSubscribeAttempts = 0;
    if (realtimeSubscribeWatchTimerId !== null && typeof window !== "undefined") {
      window.clearTimeout(realtimeSubscribeWatchTimerId);
      realtimeSubscribeWatchTimerId = null;
    }
  });

  channel.error(() => {
    realtimeChannelSubscribed = false;
    realtimeSubscribeAttempts = Math.max(realtimeSubscribeAttempts, 1);
    scheduleRealtimeSubscriptionWatchdog();
  });

  channel.listen(".realtime.presence.updated", (payload) => {
    upsertPresencePeer(payload?.peer || payload);
  });

  channel.listen(".realtime.chat.message", (payload) => {
    upsertChatMessage(payload?.message || payload);
  });

  channel.listen(".realtime.chat.updated", (payload) => {
    upsertChatMessage(payload?.message || payload);
  });

  channel.listen(".realtime.chat.deleted", (payload) => {
    removeChatMessageById(payload?.message_id || payload?.id || 0);
  });

  channel.listen(".realtime.project.participants.updated", () => {
    if (canManageProjectSettings.value) {
      void loadProjectParticipants();
    }
  });

  channel.listen(".realtime.code_comment.updated", (payload) => {
    applyRealtimeCodeCommentUpdate(payload);
  });

  channel.listen(".realtime.editor.operation", (payload) => {
    applyRemoteEditorOperation(payload?.operation || payload);
  });

  channel.listenForWhisper("presence.updated", (payload) => {
    upsertPresencePeer(payload);
  });

  channel.listen(".file_created", () => {
    void loadTree();
  });
  channel.listen(".folder_created", () => {
    void loadTree();
  });
  channel.listen(".path_deleted", () => {
    void loadTree();
  });
  channel.listen(".path_moved", () => {
    void loadTree();
  });
  channel.listen(".file_saved", () => {
    void loadTree();
  });

  await loadRealtimeChat(true, true);
  await syncPresenceHeartbeat();

  if (typeof window !== "undefined") {
    if (chatFallbackPollTimerId !== null) {
      window.clearInterval(chatFallbackPollTimerId);
    }
    chatFallbackPollTimerId = window.setInterval(() => {
      if (!realtimeChannelSubscribed) {
        void loadRealtimeChat(false, true);
      }
    }, 8000);

    presenceIntervalTimerId = window.setInterval(() => {
      if (!realtimeChannelSubscribed) {
        void syncPresenceHeartbeat();
      }
    }, 9000);

    presencePruneTimerId = window.setInterval(() => {
      pruneStalePeers();
    }, 4000);
  }

  if (liveSyncEnabled.value && activeProjectPath.value) {
    await bootstrapEditorRealtimeState(currentText.value);
  }
}

  return {
    liveSyncEnabled,
    realtimePeers,
    chatMessages,
    chatInputRef,
    chatDraft,
    chatSending,
    chatEditingMessageId,
    chatDeletingMessageId,
    chatVoiceSupported,
    chatVoiceListening,
    codeComments,
    codeCommentsLoading,
    codeCommentDeletingId,
    aceCommentOverlayHost,
    aceLineCommentTriggerRef,
    aceLineCommentPopoverRef,
    aceLineCommentInputRef,
    aceLineCommentDraft,
    aceLineCommentSubmitting,
    aceLineCommentVoiceListening,
    aceLineCommentTrigger,
    aceLineCommentPopover,
    currentUserId,
    chatDraftTrimmed,
    editorSyncRevision,
    editorSyncBusy,
    codeCommentsByLine,
    aceLineComments,
    resolveAvatarStyle,
    resolvePresenceAvatarUrl,
    resolvePresenceAvatarStyle,
    resolveMessageAvatarUrl,
    resolveMessageAvatarStyle,
    openAceLineCommentsFromTrigger,
    startCloseAceLineComments,
    handleCommentPopoverAnimationEnd,
    deleteCodeComment,
    submitAceLineComment,
    toggleAceLineCommentVoiceInput,
    toggleChatVoiceInput,
    startChatMessageEdit,
    deleteChatMessage,
    sendChatMessage,
    cancelChatMessageEdit,
    queueLocalEditorOperation,
    schedulePresenceSync,
    scheduleEditorSync,
    ensureEditorSyncPathState,
    bootstrapEditorRealtimeState,
    resetEditorSyncState,
    resetRealtimeState,
    startRealtimeSession,
    handleDocumentPointerDown,
    resolveLineCommentCount,
    positionAceLineCommentPopover,
    attachAceLineCommentHandlers,
    detachAceLineCommentHandlers,
    renderCodeCommentDecorations,
    renderPeerMarkers,
    resetCodeCommentsState,
    loadCodeComments,
    isLocalVoiceInputSupported,
    stopChatVoiceInput,
    stopAceLineCommentVoiceInput,
    disconnectRealtimeClient,
  };
}
