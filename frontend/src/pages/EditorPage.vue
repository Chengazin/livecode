<template>
  <div class="page editor-page">
    <div
      ref="editorLayoutHost"
      class="editor-layout"
      :class="editorLayoutClasses"
      :style="editorLayoutStyle"
    >
      <aside
        v-if="showSidebar"
        ref="editorSidebarPane"
        class="card editor-sidebar"
        :class="{ 'editor-sidebar--collapsed': !sidebarContentVisible }"
      >
        <EditorSidebar />
      </aside>

      <button
        v-if="canResizeSidebarPane && sidebarContentVisible"
        class="editor-splitter editor-splitter--sidebar"
        :class="{ 'is-active': activeResizePane === 'sidebar' }"
        type="button"
        :aria-label="t('editor.ariaResizeSidebar')"
        @pointerdown="startPaneResize('sidebar', $event)"
      />
      <div
        v-else-if="showSidebar"
        class="editor-splitter editor-splitter--sidebar editor-splitter--placeholder"
        aria-hidden="true"
      />

      <div class="editor-main-stack">
        <section ref="editorMainPane" class="card editor-main">
          <EditorMain
            :t="t"
            :notice="notice"
            :error="error"
            :can-use-project-fs="canUseProjectFs"
            :terminal-dock-open="terminalDockOpen"
            :terminal-dock-style="terminalDockStyle"
            :selected-project-id="selectedProjectId"
            :active-project-path="activeProjectPath"
            :before-run-active-file="saveActiveProjectFileForRun"
            :show-sidebar="showSidebar"
            :editor-language="editorLanguage"
            :language-options="languageOptions"
            :saving="saving"
            :is-project-route="isProjectRoute"
            @update:editorLanguage="updateEditorLanguage"
            @download-file="downloadFile"
            @save-file="saveFile"
            @open-terminal-dock="openTerminalDock"
            @start-terminal-dock-pull="startTerminalDockPull"
            @start-terminal-dock-resize="startTerminalDockResize"
            @close-terminal-dock="closeTerminalDock"
            @dismiss-notice="dismissNotice"
            @dismiss-error="dismissError"
          >
            <template #editor-stage>
              <AceLineCommentsOverlay />
            </template>
          </EditorMain>
        </section>
      </div>

      <button
        v-if="canUseProjectFs && canResizeChatPane && chatContentVisible"
        class="editor-splitter editor-splitter--chat"
        :class="{ 'is-active': activeResizePane === 'chat' }"
        type="button"
        :aria-label="t('editor.ariaResizeChatPanel')"
        @pointerdown="startPaneResize('chat', $event)"
      />
      <div
        v-else-if="canUseProjectFs && canResizeChatPane"
        class="editor-splitter editor-splitter--chat editor-splitter--placeholder"
        aria-hidden="true"
      />

      <EditorChatPanel v-if="canUseProjectFs" />
    </div>

    <ProjectSettingsModal
      :open="showProjectSettingsModal && canManageProjectSettings"
      :project="projectMeta"
      :saving="projectSettingsBusy"
      :can-delete="false"
      :stats="projectStats"
      :stats-loading="projectStatsLoading"
      :stats-error="projectStatsError"
      :stats-period-days="projectStatsPeriodDays"
      :selected-code="projectSettingsSelectedCode"
      :selected-code-language="editorLanguage"
      :selected-code-path="currentPath"
      @close="closeProjectSettingsModal"
      @save="saveProjectSettings"
      @update:stats-period-days="loadProjectStats"
      @refresh-stats="loadProjectStats"
    >
      <template #extra-fields>
        <label class="field field-row">
          <span>{{ t("common.theme") }}</span>
          <select v-model="editorTheme">
            <option v-for="item in themeOptions" :key="item.value" :value="item.value">{{ item.label }}</option>
          </select>
        </label>
      </template>
    </ProjectSettingsModal>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter } from "vue-router";
import { buildApiUrl, request } from "../services/api";
import { getSession } from "../services/auth";
import ProjectSettingsModal from "../components/ProjectSettingsModal.vue";
import AceLineCommentsOverlay from "../components/editor/AceLineCommentsOverlay.vue";
import EditorChatPanel from "../components/editor/EditorChatPanel.vue";
import EditorSidebar from "../components/editor/EditorSidebar.vue";
import EditorMain from "../components/editor/EditorMain.vue";
import { useAceEditor } from "../composables/useAceEditor.ts";
import { useEditorLayout } from "../composables/useEditorLayout.ts";
import { useEditorCollaboration } from "../composables/useEditorCollaboration.ts";
import {
  provideEditorCollaborationContext,
  provideEditorFilesystemContext,
  provideEditorForgejoContext,
  provideEditorLayoutContext,
  provideEditorSettingsContext,
} from "../composables/useEditorPageContext";
import { useGuestEditorState } from "../composables/useGuestEditorState.ts";
import { useProjectFilesystem } from "../composables/useProjectFilesystem.ts";
import { useProjectScopedReset } from "../composables/useProjectScopedReset.ts";
import { useProjectSettings } from "../composables/useProjectSettings.ts";
import { useForgejoIntegration } from "../composables/useForgejoIntegration.ts";

const route = useRoute();
const router = useRouter();
const { t, locale } = useI18n();

const session = ref(getSession());
const isAuthenticated = computed(() => Boolean(session.value.accessToken));
const isProjectRoute = computed(() => Boolean(route.params.projectId));
const showSidebar = computed(() => isAuthenticated.value && isProjectRoute.value);
const sidebarContentVisible = ref(true);
const chatContentVisible = ref(true);

const currentText = ref(t("editor.guestStub"));
const currentPath = ref("scratch/main.js");
const isProjectMode = ref(false);
const activeProjectPath = ref("");
const dirty = ref(false);

const selectedProjectId = ref("");
const projectName = ref("");
const projectMeta = ref(null);
const saving = ref(false);

const notice = ref("");
const noticeClosing = ref(false);
const error = ref("");
const errorClosing = ref(false);

const lastKnownFileUpdatedAt = ref("");
const projectSettingsSelectedCode = ref("");
const canUseProjectFs = computed(() => isAuthenticated.value && isProjectRoute.value && Boolean(selectedProjectId.value));
let collaborationApi = null;
let captureSelectionForSettings = () => {};

const {
  editorHost,
  editorInstance,
  syncingEditor,
  editorLanguage,
  editorTheme,
  languageOptions,
  themeOptions,
  syncEditor,
  resolveDefaultEditorTheme,
  initEditor,
  destroyEditor,
} = useAceEditor({
  currentText,
  currentPath,
  dirty,
  onEditorDelta: (delta) => {
    collaborationApi?.queueLocalEditorOperation(delta);
  },
  onCursorChange: () => {
    collaborationApi?.schedulePresenceSync(140);
  },
  onSelectionChange: () => {
    collaborationApi?.schedulePresenceSync(140);
    captureSelectionForSettings();
  },
});

captureSelectionForSettings = () => {
  const editor = editorInstance.value;
  if (!editor || typeof editor.getSelectedText !== "function") {
    projectSettingsSelectedCode.value = "";
    return;
  }

  projectSettingsSelectedCode.value = String(editor.getSelectedText() || "");
};

const {
  editorLayoutHost,
  editorMainPane,
  editorSidebarPane,
  editorChatPane,
  activeResizePane,
  canResizeChatPane,
  canResizeSidebarPane,
  editorLayoutStyle,
  terminalDockOpen,
  terminalDockStyle,
  openTerminalDock,
  closeTerminalDock,
  startTerminalDockResize,
  startTerminalDockPull,
  stopTerminalDockResize,
  startPaneResize,
  stopPaneResize,
  normalizeEditorLayoutWidths,
  scheduleEditorResize,
  cancelEditorResizeFrame,
  restoreEditorLayoutPrefs,
  attachViewportListener,
  detachViewportListener,
} = useEditorLayout({
  showSidebar,
  canUseProjectFs,
  getEditor: () => editorInstance.value,
});

const editorLayoutClasses = computed(() => ({
  "editor-layout--main-only": !showSidebar.value,
  "editor-layout--with-sidebar": showSidebar.value,
  "editor-layout--with-chat": canUseProjectFs.value && canResizeChatPane.value,
  "editor-layout--sidebar-collapsed": showSidebar.value && !sidebarContentVisible.value,
  "editor-layout--chat-collapsed": canUseProjectFs.value && canResizeChatPane.value && !chatContentVisible.value,
}));

function handleSidebarContentVisibilityChange(nextVisible) {
  const normalizedVisible = Boolean(nextVisible);
  if (sidebarContentVisible.value === normalizedVisible) {
    return;
  }

  sidebarContentVisible.value = normalizedVisible;
  if (!normalizedVisible) {
    stopPaneResize();
  }

  nextTick(() => {
    scheduleEditorResize();
  });
}

function toggleChatContentVisibility() {
  chatContentVisible.value = !chatContentVisible.value;
  if (!chatContentVisible.value) {
    stopPaneResize();
  }

  nextTick(() => {
    scheduleEditorResize();
  });
}

function readError(errorInput) {
  if (typeof errorInput?.data?.message === "string" && errorInput.data.message.trim() !== "") {
    return errorInput.data.message;
  }

  if (typeof errorInput?.data?.error === "string" && errorInput.data.error.trim() !== "") {
    return errorInput.data.error;
  }

  if (typeof errorInput?.message === "string" && errorInput.message.trim() !== "") {
    return errorInput.message;
  }

  return t("common.requestFailed");
}

const {
  persistGuest,
  restoreGuest,
  switchToGuest,
  clearProjectEditor,
} = useGuestEditorState({
  t,
  currentPath,
  currentText,
  editorLanguage,
  dirty,
  syncEditor,
  isProjectMode,
  activeProjectPath,
  lastKnownFileUpdatedAt,
});

const projectFilesystem = useProjectFilesystem({
  t,
  request,
  buildApiUrl,
  getSession,
  nextTick,
  readError,
  canUseProjectFs,
  selectedProjectId,
  isProjectMode,
  activeProjectPath,
  currentPath,
  currentText,
  dirty,
  lastKnownFileUpdatedAt,
  notice,
  error,
  syncEditor,
  clearProjectEditor,
  onFileOpened: async ({ path, content }) => {
    collaborationApi?.ensureEditorSyncPathState(path);
    collaborationApi?.schedulePresenceSync(40);
    if (collaborationApi?.liveSyncEnabled.value) {
      await collaborationApi.bootstrapEditorRealtimeState(content || "");
    }
  },
});

const {
  tree,
  treeLoading,
  newNodePath,
  newNodeInput,
  hasPath,
  selectProjectRoot,
  clearTreeItemClickTimer,
  resetProjectTreeState,
  loadTree,
  openFile,
} = projectFilesystem;

const isProjectOwner = computed(() => {
  const sessionUserId = Number(session.value?.user?.user_id);
  const ownerId = Number(projectMeta.value?.owner_id);

  if (!sessionUserId || !ownerId) {
    return false;
  }

  return sessionUserId === ownerId;
});

const canManageProjectSettings = computed(() => canUseProjectFs.value && isProjectOwner.value);
const forgejoAccountConnected = computed(() => {
  const user = session.value?.user || {};

  return Boolean(user?.forgejo_connected_at || user?.forgejo_user_id);
});
const hasForgejoRepo = computed(() => {
  return Boolean(projectMeta.value?.git_enabled)
    && Boolean(projectMeta.value?.forgejo_repo_clone_url || projectMeta.value?.forgejo_repo_full_name);
});
const canCreatePullRequest = computed(() => canUseProjectFs.value && hasForgejoRepo.value);
const forgejoRepoFullName = computed(() => String(projectMeta.value?.forgejo_repo_full_name || ""));
const forgejoRepoHtmlUrl = computed(() => String(projectMeta.value?.forgejo_repo_html_url || ""));
const canSyncFromForgejo = computed(() => {
  if (!canUseProjectFs.value || !isProjectOwner.value) {
    return false;
  }

  return hasForgejoRepo.value;
});

const workspaceTitle = computed(() => {
  if (!isProjectRoute.value) {
    return t("editor.workspace");
  }

  if (projectName.value) {
    return projectName.value;
  }

  return t("editor.toolbarProject", { id: selectedProjectId.value });
});

const projectSettings = useProjectSettings({
  t,
  request,
  readError,
  canManageProjectSettings,
  selectedProjectId,
  projectMeta,
  projectName,
  isAuthenticated,
  route,
  router,
  notice,
  error,
  onReloadProjectMeta: () => loadProjectName(),
  onReloadTree: () => loadTree(),
});

const {
  projectSettingsBusy,
  showProjectSettingsModal,
  collaboratorUserId,
  projectStats,
  projectStatsLoading,
  projectStatsError,
  projectStatsPeriodDays,
  openProjectSettingsModal: openProjectSettingsModalBase,
  closeProjectSettingsModal,
  resetProjectSettingsState,
  loadProjectStats,
  loadProjectParticipants,
  saveProjectSettings,
  acceptInviteFromQuery,
} = projectSettings;

function handleOpenProjectSettingsModal() {
  captureSelectionForSettings();
  openProjectSettingsModalBase();
}

const editorCollaboration = useEditorCollaboration({
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
});
collaborationApi = editorCollaboration;

const {
  liveSyncEnabled,
  realtimePeers,
  chatVoiceSupported,
  codeComments,
  aceLineCommentTrigger,
  aceLineCommentPopover,
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
} = editorCollaboration;

const { resetProjectScopedState } = useProjectScopedReset({
  projectName,
  projectMeta,
  resetProjectTreeState,
  resetProjectSettingsState,
  resetRealtimeState,
  resetCodeCommentsState,
});

const forgejoIntegration = useForgejoIntegration({
  t,
  request,
  readError,
  route,
  isAuthenticated,
  selectedProjectId,
  canUseProjectFs,
  isProjectOwner,
  canCreatePullRequest,
  canSyncFromForgejo,
  treeLoading,
  isProjectMode,
  activeProjectPath,
  dirty,
  tree,
  hasPath,
  projectMeta,
  projectName,
  notice,
  error,
  onLoadTree: () => loadTree(),
  onOpenFile: (path) => openFile(path),
});

const {
  repoSyncBusy,
  forgejoMode,
  forgejoRepoName,
  forgejoRepoUrl,
  forgejoMessage,
  forgejoPrTitle,
  forgejoPrBody,
  forgejoPrMessage,
} = forgejoIntegration;

const syncBusy = computed(() => treeLoading.value || repoSyncBusy.value);
const setString = (target, fallback = "", trim = true) => (value) => {
  const nextValue = String(value || fallback);
  target.value = trim ? nextValue.trim() : nextValue;
};

const updateEditorLanguage = setString(editorLanguage, "text", false);
const updateNewNodePath = setString(newNodePath);
const updateCollaboratorUserId = setString(collaboratorUserId);
const updateForgejoMode = setString(forgejoMode, "create", false);
const updateForgejoRepoName = setString(forgejoRepoName);
const updateForgejoRepoUrl = setString(forgejoRepoUrl);
const updateForgejoMessage = setString(forgejoMessage);
const updateForgejoPrTitle = setString(forgejoPrTitle);
const updateForgejoPrBody = setString(forgejoPrBody);
const updateForgejoPrMessage = setString(forgejoPrMessage);

provideEditorLayoutContext({
  t,
  workspaceTitle,
  canManageProjectSettings,
  currentPath,
  editorLanguage,
  languageOptions,
  saving,
  isProjectRoute,
  canUseProjectFs,
  syncBusy,
  isAuthenticated,
  editorHost,
  editorChatPane,
  chatContentVisible,
  toggleChatContentVisibility,
  handleSidebarContentVisibilityChange,
  handleOpenProjectSettingsModal,
  goToProjects,
  updateEditorLanguage,
  saveFile,
  downloadFile,
});

provideEditorFilesystemContext({
  updateNewNodePath,
  newNodeInputRef: newNodeInput,
  ...projectFilesystem,
});

provideEditorSettingsContext({
  updateCollaboratorUserId,
  ...projectSettings,
});

provideEditorForgejoContext({
  isAuthenticated,
  isProjectRoute,
  isProjectOwner,
  forgejoAccountConnected,
  hasForgejoRepo,
  forgejoRepoFullName,
  forgejoRepoHtmlUrl,
  canCreatePullRequest,
  selectedProjectId,
  updateForgejoMode,
  updateForgejoRepoName,
  updateForgejoRepoUrl,
  updateForgejoMessage,
  updateForgejoPrTitle,
  updateForgejoPrBody,
  updateForgejoPrMessage,
  ...forgejoIntegration,
});

provideEditorCollaborationContext({
  ...editorCollaboration,
});

function syncProjectIdFromRoute() {
  if (!isProjectRoute.value) {
    selectedProjectId.value = "";
    return;
  }

  const raw = route.params.projectId;
  const value = typeof raw === "string" ? raw.trim() : "";
  selectedProjectId.value = /^\d+$/.test(value) ? value : "";
}

async function loadProjectName() {
  if (!isProjectRoute.value || !isAuthenticated.value || !selectedProjectId.value) {
    projectName.value = "";
    projectMeta.value = null;
    resetProjectSettingsState();
    return;
  }

  const requestedId = selectedProjectId.value;

  try {
    const response = await request({
      method: "GET",
      path: `/projects/${requestedId}`,
      auth: true,
    });

    const payload = response.data || {};
    const name = typeof payload?.name === "string" ? payload.name.trim() : "";
    if (selectedProjectId.value === requestedId) {
      projectName.value = name;
      projectMeta.value = payload;

      if (canManageProjectSettings.value) {
        void loadProjectParticipants();
      } else {
        resetProjectSettingsState();
      }
    }
  } catch (_error) {
    if (selectedProjectId.value === requestedId) {
      projectName.value = "";
      projectMeta.value = null;
      resetProjectSettingsState();
    }
  }
}

async function goToProjects() {
  await router.push("/projects");
}

function onAuthChanged() {
  session.value = getSession();

  if (!isAuthenticated.value) {
    selectedProjectId.value = "";
    resetProjectScopedState();
    switchToGuest();
    return;
  }

  if (!isProjectRoute.value) {
    selectedProjectId.value = "";
    resetProjectScopedState();
    return;
  }

  if (!selectedProjectId.value) {
    resetProjectScopedState();
    switchToGuest();
    return;
  }

  if (!isProjectMode.value || !activeProjectPath.value) {
    clearProjectEditor();
  }

  void loadProjectName();
  void loadTree();
  void acceptInviteFromQuery();
  void startRealtimeSession();
}

async function saveFile() {
  error.value = "";

  if (!dirty.value) {
    notice.value = t("editor.noChanges");
    return;
  }

  if (!isProjectRoute.value) {
    persistGuest();
    dirty.value = false;
    notice.value = t("editor.guestSaved");
    return;
  }

  if (!canUseProjectFs.value || !activeProjectPath.value) {
    error.value = t("editor.projectNotSelected");
    return;
  }

  saving.value = true;

  try {
    const response = await request({
      method: "PUT",
      path: `/projects/${selectedProjectId.value}/filesystem/file`,
      auth: true,
      body: {
        path: activeProjectPath.value,
        content: currentText.value,
      },
    });

    dirty.value = false;
    lastKnownFileUpdatedAt.value = String(response.data?.updated_at || "");
    notice.value = t("editor.fileSaved");
    await loadTree();
  } catch (saveError) {
    error.value = readError(saveError);
  } finally {
    saving.value = false;
  }
}

async function saveActiveProjectFileForRun() {
  if (!dirty.value) {
    return true;
  }

  if (!isProjectRoute.value || !canUseProjectFs.value || !activeProjectPath.value) {
    error.value = t("editor.projectNotSelected");
    return false;
  }

  saving.value = true;
  error.value = "";

  try {
    const response = await request({
      method: "PUT",
      path: `/projects/${selectedProjectId.value}/filesystem/file`,
      auth: true,
      body: {
        path: activeProjectPath.value,
        content: currentText.value,
      },
    });

    dirty.value = false;
    lastKnownFileUpdatedAt.value = String(response.data?.updated_at || "");
    await loadTree();
    return true;
  } catch (saveError) {
    error.value = readError(saveError);
    return false;
  } finally {
    saving.value = false;
  }
}

function downloadFile() {
  const blob = new Blob([currentText.value || ""], { type: "text/plain;charset=utf-8" });
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = currentPath.value.split("/").pop() || t("editor.downloadDefaultFilename");
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}

function dismissNotice() {
  notice.value = "";
  noticeClosing.value = false;
}

function dismissError() {
  error.value = "";
  errorClosing.value = false;
}

watch(selectedProjectId, (value) => {
  if (!value || !isProjectRoute.value || !isAuthenticated.value) {
    resetProjectScopedState();
    return;
  }

  if (!isProjectMode.value || !activeProjectPath.value) {
    clearProjectEditor();
  }

  selectProjectRoot();
  void loadProjectName();
  void loadTree();
  void acceptInviteFromQuery();
  void startRealtimeSession();
});

watch(
  () => route.params.projectId,
  () => {
    syncProjectIdFromRoute();

    if (!isProjectRoute.value) {
      selectedProjectId.value = "";
      resetProjectScopedState();
      switchToGuest();
      return;
    }

    if (!selectedProjectId.value) {
      resetProjectScopedState();
      switchToGuest();
    }
  },
);

watch(
  () => route.query.invite,
  () => {
    void acceptInviteFromQuery();
  },
);

watch(activeProjectPath, () => {
  lastKnownFileUpdatedAt.value = "";
  if (!activeProjectPath.value) {
    resetEditorSyncState();
    resetCodeCommentsState();
  } else {
    ensureEditorSyncPathState(activeProjectPath.value);
    void loadCodeComments();
  }
  realtimePeers.value.forEach((peer) => renderPeerMarkers(peer));
  schedulePresenceSync(40);
});

watch(codeComments, () => {
  renderCodeCommentDecorations();
  if (aceLineCommentTrigger.visible) {
    const nextCount = resolveLineCommentCount(aceLineCommentTrigger.line_number);
    aceLineCommentTrigger.has_comments = nextCount > 0;
    aceLineCommentTrigger.count = nextCount;
  }
  if (aceLineCommentPopover.open) {
    positionAceLineCommentPopover(aceLineCommentPopover.line_number);
  }
});

watch(
  editorInstance,
  () => {
    if (!editorInstance.value) {
      detachAceLineCommentHandlers();
      return;
    }

    attachAceLineCommentHandlers();
    renderCodeCommentDecorations();
  },
);

watch(
  canUseProjectFs,
  (enabled) => {
    if (enabled) {
      void startRealtimeSession();
      return;
    }

    resetRealtimeState();
  },
  { immediate: true },
);

watch(notice, (value) => {
  if (value) {
    // Auto-dismiss notice after 4 seconds
    setTimeout(() => {
      dismissNotice();
    }, 4000);
  }
});

watch(error, (value) => {
  if (value) {
    // Auto-dismiss error after 5 seconds
    setTimeout(() => {
      dismissError();
    }, 5000);
  }
});

watch(liveSyncEnabled, (enabled) => {
  if (!enabled) {
    return;
  }

  if (isProjectMode.value && activeProjectPath.value) {
    void bootstrapEditorRealtimeState(currentText.value, { preserveLocal: true });
    scheduleEditorSync();
    schedulePresenceSync(0);
  }
});

onMounted(() => {
  restoreEditorLayoutPrefs();
  restoreGuest();
  editorTheme.value = resolveDefaultEditorTheme(session);
  syncProjectIdFromRoute();
  chatVoiceSupported.value = isLocalVoiceInputSupported();

  if (route.query.forgejo === "connected") {
    notice.value = t("editor.authConnectedNotice");
    const query = { ...route.query };
    delete query.forgejo;
    router.replace({ query });
  }

  attachViewportListener();
  window.addEventListener("auth-changed", onAuthChanged);
  window.addEventListener("pointerdown", handleDocumentPointerDown);
  initEditor();
  attachAceLineCommentHandlers();
  onAuthChanged();
  nextTick(() => {
    normalizeEditorLayoutWidths();
    scheduleEditorResize();
  });
});

onUnmounted(() => {
  stopPaneResize();
  stopTerminalDockResize();
  clearTreeItemClickTimer();
  detachViewportListener();

  window.removeEventListener("auth-changed", onAuthChanged);
  window.removeEventListener("pointerdown", handleDocumentPointerDown);
  detachAceLineCommentHandlers();
  stopChatVoiceInput({ discard: true });
  stopAceLineCommentVoiceInput({ discard: true });
  resetRealtimeState();
  resetCodeCommentsState();
  disconnectRealtimeClient();

  cancelEditorResizeFrame();
  destroyEditor();
});
</script>
