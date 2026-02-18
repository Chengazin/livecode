<template>
  <div class="page editor-page">

    <div
      ref="editorLayoutHost"
      class="editor-layout"
      :class="{
        'editor-layout--main-only': !showSidebar,
        'editor-layout--with-sidebar': showSidebar,
        'editor-layout--with-chat': canUseProjectFs,
      }"
      :style="editorLayoutStyle"
    >
      <aside v-if="showSidebar" ref="editorSidebarPane" class="card editor-sidebar">
        <section class="sidebar-block">
          <div class="sidebar-head">
            <h2>{{ workspaceTitle }}</h2>
            <div class="sidebar-head-actions">
              <button class="btn btn-sm btn-ghost" type="button" @click="goToProjects">
                {{ t("editor.backToProjects") }}
              </button>
              <button
                v-if="canManageProjectSettings"
                class="icon-btn"
                type="button"
                :title="t('editor.projectSettings')"
                :aria-label="t('editor.projectSettings')"
                @click="openProjectSettingsModal"
              >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M12 8.2a3.8 3.8 0 1 0 0 7.6 3.8 3.8 0 0 0 0-7.6Z" />
                  <path d="m4.8 13.5 1.5.4a6 6 0 0 0 .6 1.4l-.9 1.3 1.8 1.8 1.3-.9a6 6 0 0 0 1.4.6l.4 1.5h2.6l.4-1.5a6 6 0 0 0 1.4-.6l1.3.9 1.8-1.8-.9-1.3a6 6 0 0 0 .6-1.4l1.5-.4v-2.6l-1.5-.4a6 6 0 0 0-.6-1.4l.9-1.3-1.8-1.8-1.3.9a6 6 0 0 0-1.4-.6l-.4-1.5h-2.6l-.4 1.5a6 6 0 0 0-1.4.6l-1.3-.9-1.8 1.8.9 1.3a6 6 0 0 0-.6 1.4l-1.5.4v2.6Z" />
                </svg>
              </button>
            </div>
          </div>
          <p class="muted-text">{{ t("editor.currentFile") }} <code>{{ currentPath }}</code></p>
          <div class="sidebar-editor-actions">
            <label class="field field-row">
              <span>{{ t("common.language") }}</span>
              <select v-model="editorLanguage">
                <option v-for="item in languageOptions" :key="item.value" :value="item.value">{{ item.label }}</option>
              </select>
            </label>
            <button class="btn" type="button" :disabled="saving" @click="saveFile">
              {{ saving ? t("common.saving") : isProjectRoute ? t("editor.saveToProject") : t("editor.saveLocal") }}
            </button>
          </div>
        </section>

        <section v-if="canUseProjectFs" class="sidebar-block">
          <div class="sidebar-head">
            <h2>{{ t("editor.files") }}</h2>
            <button class="btn btn-sm btn-ghost" type="button" :disabled="syncBusy" @click="syncProjectWorkspace">
              {{ syncBusy ? t("editor.syncing") : t("editor.sync") }}
            </button>
          </div>
          <div class="tree-utility-actions">
            <button
              class="btn btn-sm btn-ghost"
              type="button"
              :disabled="!currentPath"
              @click="downloadFile"
            >
              {{ t("editor.downloadFile") }}
            </button>
            <button
              class="btn btn-sm btn-secondary"
              type="button"
              :disabled="downloadBusy || !canUseProjectFs"
              @click="downloadProjectArchive"
            >
              {{ downloadBusy && downloadingScope === "project" ? t("editor.downloading") : t("editor.downloadProject") }}
            </button>
            <button
              class="btn btn-sm btn-ghost"
              type="button"
              :disabled="downloadBusy || !canDownloadSelectedFolder"
              @click="downloadSelectedFolder"
            >
              {{ downloadBusy && downloadingScope === "folder" ? t("editor.downloading") : t("editor.downloadFolder") }}
            </button>
          </div>

          <p v-if="treeLoading" class="muted-text">{{ t("editor.loadingTree") }}</p>
          <p v-else-if="flatTree.length === 0" class="muted-text">{{ t("editor.emptyProject") }}</p>

          <div class="file-tree">
            <div class="tree-actions">
              <button
                class="tree-action-btn"
                type="button"
                :title="t('editor.newFile')"
                :disabled="nodeBusy"
                @click="startCreateNode('file')"
              >
                <svg viewBox="0 0 24 24" class="tree-action-svg" aria-hidden="true">
                  <path d="M7 3h7l3 3v15H7z" />
                  <path d="M14 3v3h3" />
                  <path d="M12 10v6" />
                  <path d="M9 13h6" />
                </svg>
              </button>
              <button
                class="tree-action-btn"
                type="button"
                :title="t('editor.newFolder')"
                :disabled="nodeBusy"
                @click="startCreateNode('folder')"
              >
                <svg viewBox="0 0 24 24" class="tree-action-svg" aria-hidden="true">
                  <path d="M3 7h7l2 2h9v10H3z" />
                  <path d="M12 11v6" />
                  <path d="M9 14h6" />
                </svg>
              </button>
              <button
                class="tree-action-btn"
                type="button"
                :title="t('editor.moveToRoot')"
                :disabled="!canMoveSelectedToRoot || moveBusy"
                @click="moveSelectedToRoot"
              >
                <svg viewBox="0 0 24 24" class="tree-action-svg" aria-hidden="true">
                  <path d="M12 18V7" />
                  <path d="M8 11l4-4 4 4" />
                  <path d="M5 19h14" />
                </svg>
              </button>
            </div>

            <form v-if="newNodeKind" class="tree-inline-create" @submit.prevent="submitCreateNode">
              <span class="tree-inline-kind" :class="`kind-${newNodeKind}`" />
              <input
                ref="newNodeInput"
                v-model.trim="newNodePath"
                class="tree-inline-input"
                type="text"
                maxlength="2048"
                :placeholder="newNodeKind === 'folder' ? t('editor.createFolderPlaceholder') : t('editor.createFilePlaceholder')"
                @keydown.esc.prevent="cancelCreateNode"
              />
              <button class="btn btn-sm btn-secondary" type="submit" :disabled="nodeBusy || !newNodePath">
                {{ t("editor.create") }}
              </button>
              <button class="btn btn-sm btn-ghost" type="button" :disabled="nodeBusy" @click="cancelCreateNode">
                {{ t("editor.cancel") }}
              </button>
            </form>

            <div
              class="tree-root-drop"
              :class="{ 'drop-target': isRootDropTarget, selected: selectedTreeType === 'root' }"
              @click="selectProjectRoot"
              @dragover.prevent="onRootDragOver"
              @dragleave="onRootDragLeave"
              @drop.prevent="onRootDrop"
            >
              {{ t("editor.projectRoot") }}
            </div>
            <div
              v-for="item in flatTree"
              :key="item.path"
              class="tree-row"
              :class="{
                active: isProjectMode && activeProjectPath === item.path,
                selected: selectedTreeType !== 'root' && selectedTreePath === item.path,
                dragging: draggedPath === item.path,
                'drop-target': item.type === 'folder' && dropTargetPath === item.path,
              }"
              @click="selectTreeItem(item.path, item.type)"
              @dragover.prevent="onTreeRowDragOver(item, $event)"
              @dragleave="onTreeRowDragLeave(item)"
              @drop.prevent="onTreeRowDrop(item)"
            >
              <button
                class="tree-drag-handle"
                type="button"
                :disabled="moveBusy"
                draggable="true"
                @dragstart="onTreeDragStart(item, $event)"
                @dragend="resetDragState"
                @click.stop="selectTreeItem(item.path, item.type)"
              >
                <svg viewBox="0 0 24 24" class="tree-action-svg" aria-hidden="true">
                  <circle cx="9" cy="7" r="1.2" />
                  <circle cx="15" cy="7" r="1.2" />
                  <circle cx="9" cy="12" r="1.2" />
                  <circle cx="15" cy="12" r="1.2" />
                  <circle cx="9" cy="17" r="1.2" />
                  <circle cx="15" cy="17" r="1.2" />
                </svg>
              </button>
              <button
                class="tree-open"
                type="button"
                @click="clickTreeItem(item)"
              >
                <span class="tree-indent" :style="{ width: `${item.depth * 14}px` }" />
                <span class="tree-prefix">{{ item.type === "folder" ? (isExpanded(item.path) ? "v" : ">") : "-" }}</span>
                <span class="tree-name">{{ item.name }}</span>
              </button>
              <button class="tree-delete" type="button" :title="t('editor.deleteTitle')" @click="removeTreeItem(item)">x</button>
            </div>
          </div>
        </section>

        <section v-if="canManageProjectSettings" class="sidebar-block">
          <div class="project-settings-block">
            <div class="sidebar-head">
              <h2>{{ t("editor.collaborators") }}</h2>
            </div>

            <form class="form-grid compact-form" @submit.prevent="addCollaboratorById">
              <label class="field field-row">
                <span>{{ t("editor.collaboratorUserId") }}</span>
                <input
                  v-model.trim="collaboratorUserId"
                  type="number"
                  min="1"
                  step="1"
                  inputmode="numeric"
                  :placeholder="t('editor.collaboratorUserIdPlaceholder')"
                />
              </label>
              <button class="btn btn-secondary" type="submit" :disabled="collaboratorBusy || !collaboratorUserId">
                {{ collaboratorBusy ? t("common.saving") : t("editor.addCollaborator") }}
              </button>
            </form>

            <div class="project-invite-actions">
              <button class="btn btn-sm btn-secondary" type="button" :disabled="inviteBusy" @click="createInviteLink">
                {{ inviteBusy ? t("editor.generatingInvite") : t("editor.generateInviteLink") }}
              </button>
              <button class="btn btn-sm btn-ghost" type="button" :disabled="!inviteLink" @click="copyInviteLink">
                {{ t("editor.copyInviteLink") }}
              </button>
            </div>
            <p v-if="inviteLink" class="muted-text project-invite-link"><code>{{ inviteLink }}</code></p>

            <p v-if="participantsLoading" class="muted-text">{{ t("editor.loadingCollaborators") }}</p>
            <p v-else-if="projectParticipants.length === 0" class="muted-text">{{ t("editor.noCollaborators") }}</p>

            <div v-else class="collaborator-list">
              <div v-for="participant in projectParticipants" :key="participant.participant_id" class="collaborator-row">
                <div class="collaborator-copy">
                  <strong>#{{ participant.user_id }}</strong>
                  <small v-if="participant.user?.name || participant.user?.email">
                    {{ participant.user?.name || participant.user?.email }}
                  </small>
                </div>
                <button
                  class="btn btn-sm btn-ghost"
                  type="button"
                  :disabled="collaboratorBusy"
                  @click="removeCollaborator(participant)"
                >
                  {{ t("editor.removeCollaborator") }}
                </button>
              </div>
            </div>
          </div>
        </section>

        <section v-if="canUseProjectFs" class="sidebar-block">
          <details class="git-accordion realtime-accordion" open>
            <summary>{{ t("editor.liveSession") }}</summary>

            <div class="realtime-head">
              <p class="muted-text">{{ t("editor.liveSyncHint") }}</p>
              <label class="realtime-toggle">
                <input v-model="liveSyncEnabled" type="checkbox" />
                <span>{{ t("editor.liveSyncEnabled") }}</span>
              </label>
            </div>

            <div class="realtime-presence">
              <h3>{{ t("editor.onlineCollaborators") }}</h3>
              <p v-if="realtimePeers.length === 0" class="muted-text">{{ t("editor.onlyYouOnline") }}</p>
              <div v-else class="presence-list">
                <div v-for="peer in realtimePeers" :key="peer.user_id" class="presence-row">
                  <strong>{{ peer.name }}</strong>
                  <small v-if="peer.path">
                    {{ peer.path }}<span v-if="peer.cursor_row !== null"> @ {{ peer.cursor_row + 1 }}:{{ (peer.cursor_column ?? 0) + 1 }}</span>
                  </small>
                  <small v-else>{{ t("editor.presenceNoFile") }}</small>
                </div>
              </div>
            </div>
          </details>
        </section>

        <section v-if="isAuthenticated && isProjectRoute" class="sidebar-block">
          <details class="git-accordion">
            <summary>{{ t("editor.gitWork") }}</summary>
            <p class="muted-text">{{ t("editor.gitPanelHint") }}</p>

            <div class="sidebar-head">
              <h2>{{ t("editor.forgejo") }}</h2>
              <button class="btn btn-sm btn-secondary" type="button" :disabled="forgejoBusy" @click="startForgejoConnect">
                {{ t("editor.connectAccount") }}
              </button>
            </div>

            <form class="form-grid compact-form" @submit.prevent="connectProjectForgejo">
              <label class="field field-row">
                <span>{{ t("editor.mode") }}</span>
                <select v-model="forgejoMode">
                  <option value="create">{{ t("editor.createRepo") }}</option>
                  <option value="existing">{{ t("editor.existingRepo") }}</option>
                </select>
              </label>

              <label v-if="forgejoMode === 'create'" class="field field-row">
                <span>{{ t("editor.repositoryName") }}</span>
                <input v-model.trim="forgejoRepoName" type="text" maxlength="255" placeholder="my-project" />
              </label>

              <label v-if="forgejoMode === 'existing'" class="field">
                <span>{{ t("editor.repositoryUrl") }}</span>
                <input
                  v-model.trim="forgejoRepoUrl"
                  type="text"
                  maxlength="2048"
                  :placeholder="t('editor.repositoryUrlPlaceholder')"
                />
              </label>

              <button class="btn" type="submit" :disabled="forgejoBusy || !selectedProjectId">{{ t("editor.connectProjectRepo") }}</button>
            </form>

            <form class="form-grid compact-form" @submit.prevent="pushToForgejo">
              <label class="field field-row">
                <span>{{ t("editor.commitMessage") }}</span>
                <input v-model.trim="forgejoMessage" type="text" maxlength="255" :placeholder="t('editor.manualSavePlaceholder')" />
              </label>
              <button class="btn btn-secondary" type="submit" :disabled="forgejoBusy || !selectedProjectId">{{ t("editor.pushToForgejo") }}</button>
            </form>
          </details>
        </section>
      </aside>

      <button
        v-if="canResizeSidebarPane"
        class="editor-splitter editor-splitter--sidebar"
        :class="{ 'is-active': activeResizePane === 'sidebar' }"
        type="button"
        aria-label="Resize sidebar"
        @pointerdown="startPaneResize('sidebar', $event)"
      />

      <div class="editor-main-stack">
        <section ref="editorMainPane" class="card editor-main">
          <p v-if="notice" class="notice-banner">{{ notice }}</p>
          <p v-if="error" class="error-banner">{{ error }}</p>
          <div class="editor-stage">
            <div ref="editorHost" class="ace-editor-host" />
          </div>
          <div
            v-if="canUseProjectFs"
            class="editor-main-terminal-dock"
            :class="{ 'is-open': terminalDockOpen }"
            :style="terminalDockStyle"
          >
            <button
              v-if="!terminalDockOpen"
              class="editor-main-terminal-peek"
              type="button"
              :title="t('editor.terminalDockShow')"
              :aria-label="t('editor.terminalDockShow')"
              @click="openTerminalDock"
              @pointerdown="startTerminalDockPull"
            >
              <span>{{ t("editor.sessionTerminal") }}</span>
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M12 16V8" />
                <path d="m7 13 5-5 5 5" />
              </svg>
            </button>

            <div v-else class="editor-main-terminal">
              <button
                class="editor-main-terminal-resizer"
                type="button"
                :title="t('editor.terminalDockResize')"
                :aria-label="t('editor.terminalDockResize')"
                @pointerdown="startTerminalDockResize"
              />
              <div class="editor-main-terminal-controls">
                <button class="btn btn-sm btn-ghost" type="button" @click="closeTerminalDock">
                  {{ t("editor.terminalDockHide") }}
                </button>
              </div>
              <ProjectTerminalPanel :project-id="selectedProjectId" :embedded="true" />
            </div>
          </div>
          <div v-if="!showSidebar" class="editor-main-standalone-actions">
            <label class="field-inline">
              <span>{{ t("common.language") }}</span>
              <select v-model="editorLanguage">
                <option v-for="item in languageOptions" :key="item.value" :value="item.value">{{ item.label }}</option>
              </select>
            </label>
            <button class="btn btn-secondary" type="button" @click="downloadFile">{{ t("editor.downloadFile") }}</button>
            <button class="btn" type="button" :disabled="saving" @click="saveFile">
              {{ saving ? t("common.saving") : isProjectRoute ? t("editor.saveToProject") : t("editor.saveLocal") }}
            </button>
          </div>
        </section>
      </div>

      <button
        v-if="canResizeChatPane"
        class="editor-splitter editor-splitter--chat"
        :class="{ 'is-active': activeResizePane === 'chat' }"
        type="button"
        aria-label="Resize chat panel"
        @pointerdown="startPaneResize('chat', $event)"
      />

      <section v-if="canUseProjectFs" ref="editorChatPane" class="card editor-chat-column">
        <div class="editor-chat-head">
          <h3>{{ t("editor.sessionChat") }}</h3>
        </div>
        <div class="chat-log">
          <p v-if="chatMessages.length === 0" class="muted-text">{{ t("editor.chatEmpty") }}</p>
          <div v-for="message in chatMessages" :key="message.id" class="chat-row" :class="{ self: message.user_id === currentUserId }">
            <div class="chat-copy">
              <strong>{{ message.user_name }}</strong>
              <small>{{ message.created_at_label }}</small>
            </div>
            <p>{{ message.message }}</p>
          </div>
        </div>

        <form class="chat-form" @submit.prevent="sendChatMessage">
          <div class="chat-input-shell">
            <input
              v-model.trim="chatDraft"
              type="text"
              maxlength="1000"
              :placeholder="t('editor.chatPlaceholder')"
            />
            <button
              class="chat-send-btn"
              type="submit"
              :title="t('editor.chatSend')"
              :aria-label="t('editor.chatSend')"
              :disabled="chatSending || !chatDraft"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 12h13" />
                <path d="m12 5 7 7-7 7" />
              </svg>
            </button>
          </div>
        </form>
      </section>
    </div>

    <ProjectSettingsModal
      :open="showProjectSettingsModal && canManageProjectSettings"
      :project="projectMeta"
      :saving="projectSettingsBusy"
      :can-delete="false"
      @close="closeProjectSettingsModal"
      @save="saveProjectSettings"
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
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter } from "vue-router";
import ace from "ace-builds/src-noconflict/ace";
import "ace-builds/src-noconflict/ext-language_tools";
import "ace-builds/src-noconflict/theme-github";
import "ace-builds/src-noconflict/theme-monokai";
import "ace-builds/src-noconflict/theme-tomorrow_night";
import "ace-builds/src-noconflict/mode-javascript";
import "ace-builds/src-noconflict/mode-typescript";
import "ace-builds/src-noconflict/mode-json";
import "ace-builds/src-noconflict/mode-html";
import "ace-builds/src-noconflict/mode-css";
import "ace-builds/src-noconflict/mode-php";
import "ace-builds/src-noconflict/mode-python";
import "ace-builds/src-noconflict/mode-markdown";
import "ace-builds/src-noconflict/mode-text";
import { buildApiUrl, request } from "../services/api";
import { getSession } from "../services/auth";
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
import ProjectSettingsModal from "../components/ProjectSettingsModal.vue";
import ProjectTerminalPanel from "../components/ProjectTerminalPanel.vue";

const route = useRoute();
const router = useRouter();
const { t, locale } = useI18n();

const GUEST_KEY = "livecode.editor.guest";
const FORGEJO_RETURN_KEY = "livecode.forgejo.return_path";
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
const EDITOR_SYNC_FALLBACK_POLL_INTERVAL_MS = 2000;
const REALTIME_SUBSCRIBE_GRACE_MS = 3000;
const EDITOR_SYNC_DEBOUNCE_MS = 16;
const EDITOR_SYNC_RETRY_LOCK_MS = 80;
const EDITOR_SYNC_RETRY_DEFAULT_MS = 160;
const CHAT_SYNC_FALLBACK_POLL_INTERVAL_MS = 2500;

const session = ref(getSession());
const isAuthenticated = computed(() => Boolean(session.value.accessToken));
const isProjectRoute = computed(() => Boolean(route.params.projectId));
const showSidebar = computed(() => isAuthenticated.value && isProjectRoute.value);

const languageOptions = [
  { value: "javascript", label: "JavaScript" },
  { value: "typescript", label: "TypeScript" },
  { value: "json", label: "JSON" },
  { value: "html", label: "HTML" },
  { value: "css", label: "CSS" },
  { value: "php", label: "PHP" },
  { value: "python", label: "Python" },
  { value: "markdown", label: "Markdown" },
  { value: "text", label: "Plain text" },
];

const themeOptions = [
  { value: "github", label: "GitHub light" },
  { value: "monokai", label: "Monokai" },
  { value: "tomorrow_night", label: "Tomorrow night" },
];

const editorHost = ref(null);
const editorLayoutHost = ref(null);
const editorMainPane = ref(null);
const editorSidebarPane = ref(null);
const editorChatPane = ref(null);
let editor = null;
let syncingEditor = false;

const editorLanguage = ref("javascript");
const editorTheme = ref("github");
const viewportWidth = ref(typeof window !== "undefined" ? window.innerWidth : 1600);
const sidebarWidth = ref(420);
const chatWidth = ref(160);
const sidebarResized = ref(false);
const chatResized = ref(false);
const terminalDockOpen = ref(true);
const terminalDockHeight = ref(TERMINAL_DOCK_DEFAULT_HEIGHT);
const terminalDockResized = ref(false);
const activeResizePane = ref("");

const guest = reactive({
  path: "scratch/main.js",
  content: t("editor.guestStub"),
});

const currentText = ref(guest.content);
const currentPath = ref(guest.path);
const isProjectMode = ref(false);
const activeProjectPath = ref("");
const dirty = ref(false);

const selectedProjectId = ref("");
const projectName = ref("");
const projectMeta = ref(null);
const tree = ref([]);
const treeLoading = ref(false);
const expanded = ref([]);
const draggedPath = ref("");
const draggedType = ref("");
const dropTargetPath = ref("");
const isRootDropTarget = ref(false);
const moveBusy = ref(false);
const selectedTreePath = ref("");
const selectedTreeType = ref("");
const downloadBusy = ref(false);
const downloadingScope = ref("");

const newNodeKind = ref("");
const newNodePath = ref("");
const newNodeInput = ref(null);
const nodeBusy = ref(false);
const saving = ref(false);
const forgejoBusy = ref(false);
const repoSyncBusy = ref(false);

const forgejoMode = ref("create");
const forgejoRepoName = ref("");
const forgejoRepoUrl = ref("");
const forgejoMessage = ref("");
const projectSettingsBusy = ref(false);
const showProjectSettingsModal = ref(false);
const projectParticipants = ref([]);
const participantsLoading = ref(false);
const collaboratorBusy = ref(false);
const collaboratorUserId = ref("");
const inviteBusy = ref(false);
const inviteLink = ref("");
const inviteHandlingBusy = ref(false);
const liveSyncEnabled = ref(true);
const liveSyncBusy = ref(false);
const lastKnownFileUpdatedAt = ref("");
const realtimePeers = ref([]);
const chatMessages = ref([]);
const chatDraft = ref("");
const chatSending = ref(false);
const realtimeBusy = ref(false);
const lastChatId = ref(0);
const editorSyncBusy = ref(false);
const realtimeClientId = ref(getOrCreateRealtimeClientId());

let editorDocRevision = 0;
let editorDocSyncedPath = "";
let editorStateBootstrapping = false;
let editorSyncPendingOps = [];
let editorSyncInflightOp = null;
let editorDeferredRemoteOps = [];

let presenceIntervalTimerId = null;
let presenceDebounceTimerId = null;
let presencePruneTimerId = null;
let editorSyncDebounceTimerId = null;
let autosaveDebounceTimerId = null;
let reconnectTimerId = null;
let treeRefreshTimerId = null;
let editorSyncRetryTimerId = null;
let editorSyncFallbackPollTimerId = null;
let realtimeSubscribeWatchTimerId = null;
let chatFallbackPollTimerId = null;
let participantsRefreshTimerId = null;
let applyingRemoteEditorSync = false;
let activeRealtimeProjectId = "";
let editorResizeFrameId = null;
let paneResizeState = null;
let terminalDockResizeState = null;
let lastPresenceSignature = "";
let lastPresenceSentAtMs = 0;
let realtimeChannelSubscribed = false;

const notice = ref("");
const error = ref("");

const canUseProjectFs = computed(() => isAuthenticated.value && isProjectRoute.value && Boolean(selectedProjectId.value));
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
    style["--editor-sidebar-width"] = `${Math.round(sidebarWidth.value)}px`;
  }

  if (canUseProjectFs.value) {
    style["--editor-chat-width"] = `${Math.round(chatWidth.value)}px`;
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
const currentUserId = computed(() => Number(session.value?.user?.user_id || 0));
const isProjectOwner = computed(() => {
  const sessionUserId = Number(session.value?.user?.user_id);
  const ownerId = Number(projectMeta.value?.owner_id);

  if (!sessionUserId || !ownerId) {
    return false;
  }

  return sessionUserId === ownerId;
});
const canManageProjectSettings = computed(() => canUseProjectFs.value && isProjectOwner.value);
const canSyncFromForgejo = computed(() => {
  if (!canUseProjectFs.value || !isProjectOwner.value) {
    return false;
  }

  return Boolean(projectMeta.value?.git_enabled)
    && Boolean(projectMeta.value?.forgejo_repo_clone_url || projectMeta.value?.forgejo_repo_full_name);
});
const syncBusy = computed(() => treeLoading.value || repoSyncBusy.value);
const canDownloadSelectedFolder = computed(() => {
  return canUseProjectFs.value && selectedTreeType.value === "folder" && Boolean(selectedTreePath.value);
});
const canMoveSelectedToRoot = computed(() => {
  return canUseProjectFs.value && selectedTreeType.value !== "root" && Boolean(selectedTreePath.value);
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

const flatTree = computed(() => {
  const list = [];

  function walk(items, depth) {
    items.forEach((item) => {
      list.push({ ...item, depth });

      if (item.type === "folder" && isExpanded(item.path)) {
        walk(Array.isArray(item.children) ? item.children : [], depth + 1);
      }
    });
  }

  walk(tree.value, 0);
  return list;
});

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

function detectLanguage(path) {
  const ext = String(path || "").toLowerCase().split(".").pop() || "";
  const map = {
    js: "javascript",
    ts: "typescript",
    json: "json",
    html: "html",
    htm: "html",
    css: "css",
    php: "php",
    py: "python",
    md: "markdown",
    txt: "text",
  };

  return map[ext] || "text";
}

function syncEditor() {
  editorLanguage.value = detectLanguage(currentPath.value);
  if (!editor) {
    return;
  }

  syncingEditor = true;
  editor.session.setValue(currentText.value || "");
  editor.clearSelection();
  syncingEditor = false;
}

function persistGuest() {
  try {
    window.localStorage.setItem(GUEST_KEY, JSON.stringify({ ...guest, language: editorLanguage.value }));
  } catch (_e) {
    // ignore
  }
}

function restoreGuest() {
  try {
    const raw = window.localStorage.getItem(GUEST_KEY);
    if (!raw) {
      return;
    }

    const parsed = JSON.parse(raw);
    if (typeof parsed?.path === "string" && parsed.path.trim() !== "") {
      guest.path = parsed.path;
    }

    if (typeof parsed?.content === "string") {
      guest.content = parsed.content;
    }

    if (typeof parsed?.language === "string") {
      editorLanguage.value = parsed.language;
    }
  } catch (_e) {
    // ignore
  }
}

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

function isExpanded(path) {
  return expanded.value.includes(path);
}

function expandParents(path) {
  const parts = String(path || "").split("/").filter(Boolean);
  const next = [...expanded.value];

  for (let index = 0; index < parts.length - 1; index += 1) {
    const folderPath = parts.slice(0, index + 1).join("/");
    if (!next.includes(folderPath)) {
      next.push(folderPath);
    }
  }

  expanded.value = next;
}

function firstFile(items) {
  for (const item of items) {
    if (item.type === "file") {
      return item.path;
    }

    if (item.type === "folder" && Array.isArray(item.children)) {
      const nested = firstFile(item.children);
      if (nested) {
        return nested;
      }
    }
  }

  return "";
}

function firstRootFile(items) {
  for (const item of items) {
    if (item.type === "file") {
      return item.path;
    }
  }

  return "";
}

function hasPath(path, items) {
  for (const item of items) {
    if (item.path === path) {
      return true;
    }

    if (item.type === "folder" && Array.isArray(item.children) && hasPath(path, item.children)) {
      return true;
    }
  }

  return false;
}

function localizeNodeType(type) {
  return type === "folder" ? t("editor.typeFolder") : t("editor.typeFile");
}

function basename(path) {
  const segments = String(path || "").split("/").filter(Boolean);
  return segments.length > 0 ? segments[segments.length - 1] : "";
}

function parentPath(path) {
  const segments = String(path || "").split("/").filter(Boolean);
  if (segments.length <= 1) {
    return "";
  }

  return segments.slice(0, -1).join("/");
}

function selectTreeItem(path, type) {
  selectedTreePath.value = String(path || "");
  selectedTreeType.value = String(type || "");
}

function selectProjectRoot() {
  selectedTreePath.value = "";
  selectedTreeType.value = "root";
}

function resetDragState() {
  draggedPath.value = "";
  draggedType.value = "";
  dropTargetPath.value = "";
  isRootDropTarget.value = false;
}

function canDropToFolder(folderPath) {
  if (!draggedPath.value) {
    return false;
  }

  if (draggedType.value === "folder" && folderPath.startsWith(`${draggedPath.value}/`)) {
    return false;
  }

  const sourceParent = parentPath(draggedPath.value);
  if (sourceParent === folderPath) {
    return false;
  }

  if (folderPath === draggedPath.value) {
    return false;
  }

  return true;
}

function onTreeDragStart(item, event) {
  selectTreeItem(item.path, item.type);
  draggedPath.value = item.path;
  draggedType.value = item.type;
  dropTargetPath.value = "";
  isRootDropTarget.value = false;

  if (event?.dataTransfer) {
    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData("text/plain", item.path);
  }
}

function onTreeRowDragOver(item, event) {
  if (item.type !== "folder") {
    return;
  }

  if (!canDropToFolder(item.path)) {
    return;
  }

  dropTargetPath.value = item.path;
  isRootDropTarget.value = false;

  if (event?.dataTransfer) {
    event.dataTransfer.dropEffect = "move";
  }
}

function onTreeRowDragLeave(item) {
  if (dropTargetPath.value === item.path) {
    dropTargetPath.value = "";
  }
}

function onRootDragOver(event) {
  if (!canDropToFolder("")) {
    return;
  }

  isRootDropTarget.value = true;
  dropTargetPath.value = "";

  if (event?.dataTransfer) {
    event.dataTransfer.dropEffect = "move";
  }
}

function onRootDragLeave() {
  isRootDropTarget.value = false;
}

async function onTreeRowDrop(item) {
  if (item.type !== "folder") {
    return;
  }

  if (!canDropToFolder(item.path)) {
    return;
  }

  await moveDraggedPath(item.path);
}

async function onRootDrop() {
  if (!canDropToFolder("")) {
    return;
  }

  await moveDraggedPath("");
}

async function moveDraggedPath(targetFolderPath) {
  if (!canUseProjectFs.value || !draggedPath.value || moveBusy.value) {
    resetDragState();
    return;
  }

  const sourcePath = draggedPath.value;

  await movePathToFolder(sourcePath, targetFolderPath);
  resetDragState();
}

async function movePathToFolder(sourcePath, targetFolderPath) {
  if (!canUseProjectFs.value || !sourcePath || moveBusy.value) {
    return;
  }

  const name = basename(sourcePath);
  const targetPath = targetFolderPath ? `${targetFolderPath}/${name}` : name;
  if (!targetPath || targetPath === sourcePath) {
    return;
  }

  moveBusy.value = true;
  error.value = "";
  notice.value = "";

  try {
    const response = await request({
      method: "PUT",
      path: `/projects/${selectedProjectId.value}/filesystem/move`,
      auth: true,
      body: {
        from_path: sourcePath,
        to_path: targetPath,
      },
    });

    const fromPath = response.data?.from_path || sourcePath;
    const toPath = response.data?.to_path || targetPath;

    if (isProjectMode.value && activeProjectPath.value) {
      if (activeProjectPath.value === fromPath) {
        activeProjectPath.value = toPath;
        currentPath.value = toPath;
      } else if (activeProjectPath.value.startsWith(`${fromPath}/`)) {
        const suffix = activeProjectPath.value.slice(fromPath.length + 1);
        activeProjectPath.value = `${toPath}/${suffix}`;
        currentPath.value = activeProjectPath.value;
      }
    }

    if (selectedTreeType.value !== "root" && selectedTreePath.value) {
      if (selectedTreePath.value === fromPath) {
        selectedTreePath.value = toPath;
      } else if (selectedTreePath.value.startsWith(`${fromPath}/`)) {
        const suffix = selectedTreePath.value.slice(fromPath.length + 1);
        selectedTreePath.value = `${toPath}/${suffix}`;
      }
    }

    expandParents(toPath);
    await loadTree();
    notice.value = t("editor.movedTo", { path: toPath });
  } catch (moveError) {
    error.value = readError(moveError);
  } finally {
    moveBusy.value = false;
  }
}

async function moveSelectedToRoot() {
  if (!canMoveSelectedToRoot.value || !selectedTreePath.value) {
    return;
  }

  if (parentPath(selectedTreePath.value) === "") {
    notice.value = t("editor.alreadyInRoot");
    error.value = "";
    return;
  }

  await movePathToFolder(selectedTreePath.value, "");
}

function switchToGuest() {
  isProjectMode.value = false;
  activeProjectPath.value = "";
  currentPath.value = guest.path;
  currentText.value = guest.content;
  lastKnownFileUpdatedAt.value = "";
  dirty.value = false;
  resetEditorSyncState();
  syncEditor();
}

function clearProjectEditor() {
  isProjectMode.value = false;
  activeProjectPath.value = "";
  currentPath.value = "";
  currentText.value = "";
  lastKnownFileUpdatedAt.value = "";
  dirty.value = false;
  resetEditorSyncState();
  syncEditor();
}

function resolveDefaultEditorTheme() {
  const preference = typeof session.value?.user?.theme === "string" ? session.value.user.theme.trim() : "";
  if (preference === "dark") {
    return "tomorrow_night";
  }

  if (preference === "light") {
    return "github";
  }

  if (typeof document === "undefined") {
    return "github";
  }

  const effectiveTheme = document.documentElement.getAttribute("data-theme");
  return effectiveTheme === "dark" ? "tomorrow_night" : "github";
}

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

function openProjectSettingsModal() {
  if (!canManageProjectSettings.value) {
    return;
  }

  showProjectSettingsModal.value = true;
}

function closeProjectSettingsModal() {
  showProjectSettingsModal.value = false;
}

function resetProjectTreeState() {
  tree.value = [];
  expanded.value = [];
  selectedTreePath.value = "";
  selectedTreeType.value = "";
  cancelCreateNode();
  resetDragState();
}

function resetProjectSettingsState() {
  showProjectSettingsModal.value = false;
  projectParticipants.value = [];
  collaboratorUserId.value = "";
  inviteLink.value = "";
}

function clearRealtimeTimers() {
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

  if (editorSyncDebounceTimerId !== null) {
    window.clearTimeout(editorSyncDebounceTimerId);
    editorSyncDebounceTimerId = null;
  }

  if (autosaveDebounceTimerId !== null) {
    window.clearTimeout(autosaveDebounceTimerId);
    autosaveDebounceTimerId = null;
  }

  if (reconnectTimerId !== null) {
    window.clearTimeout(reconnectTimerId);
    reconnectTimerId = null;
  }

  if (treeRefreshTimerId !== null) {
    window.clearTimeout(treeRefreshTimerId);
    treeRefreshTimerId = null;
  }

  if (editorSyncRetryTimerId !== null) {
    window.clearTimeout(editorSyncRetryTimerId);
    editorSyncRetryTimerId = null;
  }

  if (editorSyncFallbackPollTimerId !== null) {
    window.clearInterval(editorSyncFallbackPollTimerId);
    editorSyncFallbackPollTimerId = null;
  }

  if (realtimeSubscribeWatchTimerId !== null) {
    window.clearTimeout(realtimeSubscribeWatchTimerId);
    realtimeSubscribeWatchTimerId = null;
  }

  if (chatFallbackPollTimerId !== null) {
    window.clearInterval(chatFallbackPollTimerId);
    chatFallbackPollTimerId = null;
  }

  if (participantsRefreshTimerId !== null) {
    window.clearTimeout(participantsRefreshTimerId);
    participantsRefreshTimerId = null;
  }

  realtimeChannelSubscribed = false;
}

function resetEditorSyncState() {
  editorSyncBusy.value = false;
  applyingRemoteEditorSync = false;
  editorStateBootstrapping = false;
  editorDocRevision = 0;
  editorDocSyncedPath = "";
  editorSyncPendingOps = [];
  editorSyncInflightOp = null;
  editorDeferredRemoteOps = [];
}

function stopRealtimeSession() {
  clearRealtimeTimers();

  if (activeRealtimeProjectId) {
    leaveProjectRealtimeChannel(activeRealtimeProjectId);
    activeRealtimeProjectId = "";
  }
}

function resetRealtimeState() {
  stopRealtimeSession();
  realtimePeers.value = [];
  chatMessages.value = [];
  chatDraft.value = "";
  lastChatId.value = 0;
  liveSyncBusy.value = false;
  realtimeBusy.value = false;
  lastPresenceSignature = "";
  lastPresenceSentAtMs = 0;
  resetEditorSyncState();
  lastKnownFileUpdatedAt.value = "";
  disconnectRealtimeClient();
}

function formatChatTimestamp(rawValue) {
  const value = String(rawValue || "");
  if (!value) {
    return "";
  }

  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat(locale.value === "ru" ? "ru-RU" : "en-US", {
    hour: "2-digit",
    minute: "2-digit",
  }).format(parsed);
}

function normalizeChatMessage(item) {
  return {
    id: Number(item?.id || 0),
    user_id: Number(item?.user_id || 0),
    user_name: String(item?.user_name || ""),
    message: String(item?.message || ""),
    created_at: String(item?.created_at || ""),
    created_at_label: formatChatTimestamp(item?.created_at),
  };
}

function normalizePeer(item) {
  const userId = Number(item?.user_id || 0);
  if (!Number.isInteger(userId) || userId <= 0 || userId === currentUserId.value) {
    return null;
  }

  return {
    user_id: userId,
    name: String(item?.name || ""),
    path: String(item?.path || ""),
    cursor_row: item?.cursor_row === null || item?.cursor_row === undefined ? null : Number(item.cursor_row),
    cursor_column: item?.cursor_column === null || item?.cursor_column === undefined ? null : Number(item.cursor_column),
    seen_at: Number(item?.seen_at || 0),
  };
}

function sortPeers(peers) {
  return [...peers].sort((left, right) => {
    const leftName = String(left?.name || "");
    const rightName = String(right?.name || "");
    return leftName.localeCompare(rightName, locale.value === "ru" ? "ru-RU" : "en-US");
  });
}

function setRealtimePeers(peers) {
  const normalized = peers
    .map((peer) => normalizePeer(peer))
    .filter((peer) => Boolean(peer));

  realtimePeers.value = sortPeers(normalized);
}

function upsertRealtimePeer(rawPeer) {
  const peer = normalizePeer(rawPeer);
  if (!peer) {
    return;
  }

  const next = realtimePeers.value.filter((item) => item.user_id !== peer.user_id);
  next.push(peer);
  realtimePeers.value = sortPeers(next);
}

function pruneRealtimePeers() {
  const threshold = Math.floor(Date.now() / 1000) - 25;
  realtimePeers.value = realtimePeers.value.filter((peer) => Number(peer?.seen_at || 0) >= threshold);
}

function appendChatMessage(rawMessage) {
  const message = normalizeChatMessage(rawMessage);
  if (message.id <= 0) {
    return;
  }

  if (chatMessages.value.some((item) => item.id === message.id)) {
    return;
  }

  chatMessages.value = [...chatMessages.value, message]
    .sort((left, right) => left.id - right.id)
    .slice(-150);
  lastChatId.value = Math.max(lastChatId.value, message.id);
}

function buildPresencePayload() {
  const payload = {
    path: isProjectMode.value ? activeProjectPath.value : "",
  };

  if (editor && isProjectMode.value && activeProjectPath.value) {
    const cursor = editor.getCursorPosition();
    payload.cursor_row = Number(cursor?.row ?? 0);
    payload.cursor_column = Number(cursor?.column ?? 0);
  }

  return payload;
}

function serializePresencePayload(payload) {
  return JSON.stringify({
    path: String(payload?.path || ""),
    cursor_row: payload?.cursor_row ?? null,
    cursor_column: payload?.cursor_column ?? null,
  });
}

async function syncRealtimePresence(options = {}) {
  if (!canUseProjectFs.value || !selectedProjectId.value || realtimeBusy.value) {
    return;
  }

  const force = Boolean(options?.force);
  const payload = buildPresencePayload();
  const nowMs = Date.now();
  const signature = serializePresencePayload(payload);

  if (!force && signature === lastPresenceSignature && (nowMs - lastPresenceSentAtMs) < 2500) {
    return;
  }

  realtimeBusy.value = true;

  try {
    const response = await request({
      method: "POST",
      path: `/projects/${selectedProjectId.value}/realtime/heartbeat`,
      auth: true,
      body: payload,
    });

    const peers = Array.isArray(response.data?.peers) ? response.data.peers : [];
    setRealtimePeers(peers);
    lastPresenceSignature = signature;
    lastPresenceSentAtMs = Date.now();
  } catch (_error) {
    // Best effort: next heartbeat retries automatically.
  } finally {
    realtimeBusy.value = false;
  }
}

function schedulePresenceSync(delay = 500) {
  if (typeof window === "undefined" || !canUseProjectFs.value) {
    return;
  }

  if (presenceDebounceTimerId !== null) {
    window.clearTimeout(presenceDebounceTimerId);
  }

  presenceDebounceTimerId = window.setTimeout(() => {
    presenceDebounceTimerId = null;
    void syncRealtimePresence();
  }, delay);
}

async function loadChatMessages(options = {}) {
  if (!canUseProjectFs.value || !selectedProjectId.value) {
    return;
  }

  const forceReload = Boolean(options?.forceReload);
  const afterId = forceReload ? 0 : Math.max(0, Number(lastChatId.value || 0));

  try {
    const response = await request({
      method: "GET",
      path: `/projects/${selectedProjectId.value}/realtime/chat`,
      auth: true,
      query: {
        after_id: afterId,
        limit: 150,
      },
    });

    const incomingRaw = Array.isArray(response.data?.messages) ? response.data.messages : [];
    const incoming = incomingRaw
      .map((item) => normalizeChatMessage(item))
      .filter((item) => item.id > 0);

    if (afterId > 0) {
      incoming.forEach((message) => {
        appendChatMessage(message);
      });
    } else {
      chatMessages.value = incoming.slice(-150);
    }

    const latest = Number(response.data?.latest_id || 0);
    if (Number.isFinite(latest)) {
      lastChatId.value = Math.max(lastChatId.value, Math.max(0, latest));
    }
  } catch (_error) {
    // Best effort bootstrap. New messages still come via websocket channel.
  }
}

function canSyncEditorRealtime() {
  return liveSyncEnabled.value
    && canUseProjectFs.value
    && isProjectMode.value
    && Boolean(activeProjectPath.value);
}

function ensureEditorSyncPathState(pathValue = activeProjectPath.value) {
  const path = String(pathValue || "");
  if (!path) {
    resetEditorSyncState();
    return;
  }

  if (editorDocSyncedPath === path) {
    return;
  }

  editorDocSyncedPath = path;
  editorDocRevision = 0;
  editorSyncPendingOps = [];
  editorSyncInflightOp = null;
  editorDeferredRemoteOps = [];
  editorSyncBusy.value = false;
}

function setEditorContentFromRealtime(contentInput = "", keepDirty = false) {
  const content = String(contentInput ?? "");
  applyingRemoteEditorSync = true;
  syncingEditor = true;
  currentText.value = content;

  if (editor) {
    editor.session.setValue(content);
  }

  syncingEditor = false;
  applyingRemoteEditorSync = false;
  dirty.value = keepDirty ? dirty.value : false;
}

function applyEditorTextOperation(operationInput = {}) {
  const operation = normalizeTextOperation(operationInput);
  if (isNoopTextOperation(operation)) {
    return;
  }

  if (!editor) {
    currentText.value = applyTextOperation(currentText.value, operation);
    return;
  }

  const documentRef = editor.session?.getDocument?.();
  if (!documentRef || typeof documentRef.indexToPosition !== "function") {
    currentText.value = applyTextOperation(currentText.value, operation);
    syncEditor();
    return;
  }

  const currentValue = editor.getValue();
  const boundedStart = Math.max(0, Math.min(operation.start, currentValue.length));
  const boundedDelete = Math.max(0, Math.min(operation.delete_count, currentValue.length - boundedStart));

  applyingRemoteEditorSync = true;
  syncingEditor = true;

  if (boundedDelete > 0) {
    const removeStart = documentRef.indexToPosition(boundedStart, 0);
    const removeEnd = documentRef.indexToPosition(boundedStart + boundedDelete, 0);
    documentRef.remove({
      start: removeStart,
      end: removeEnd,
    });
  }

  if (operation.insert_text) {
    const insertPosition = documentRef.indexToPosition(boundedStart, 0);
    documentRef.insert(insertPosition, operation.insert_text);
  }

  currentText.value = editor.getValue();
  syncingEditor = false;
  applyingRemoteEditorSync = false;
}

function normalizeEditorOperationEnvelope(payload) {
  const entryCandidate = payload?.operation && typeof payload.operation === "object"
    ? payload.operation
    : payload;
  const operationCandidate = entryCandidate?.operation && typeof entryCandidate.operation === "object"
    ? entryCandidate.operation
    : entryCandidate;
  const operation = normalizeTextOperation({
    ...operationCandidate,
    client_id: entryCandidate?.client_id ?? payload?.client_id ?? "",
    op_id: entryCandidate?.op_id ?? payload?.op_id ?? "",
  });

  if (isNoopTextOperation(operation)) {
    return null;
  }

  const revision = Number(entryCandidate?.revision ?? payload?.revision ?? 0);
  return {
    revision: Number.isFinite(revision) ? Math.max(0, revision) : 0,
    path: String(entryCandidate?.path ?? payload?.path ?? ""),
    user_id: Number(entryCandidate?.user_id ?? payload?.user_id ?? 0),
    client_id: String(entryCandidate?.client_id ?? payload?.client_id ?? operation.client_id ?? ""),
    op_id: String(entryCandidate?.op_id ?? payload?.op_id ?? operation.op_id ?? ""),
    operation: {
      ...operation,
      client_id: String(entryCandidate?.client_id ?? payload?.client_id ?? operation.client_id ?? ""),
      op_id: String(entryCandidate?.op_id ?? payload?.op_id ?? operation.op_id ?? ""),
    },
  };
}

function enqueueDeferredRemoteOperation(operationEnvelope) {
  if (!operationEnvelope) {
    return;
  }

  const exists = editorDeferredRemoteOps.some((item) => {
    return item.revision === operationEnvelope.revision
      && item.client_id === operationEnvelope.client_id
      && item.op_id === operationEnvelope.op_id;
  });

  if (exists) {
    return;
  }

  editorDeferredRemoteOps.push(operationEnvelope);
  editorDeferredRemoteOps.sort((left, right) => left.revision - right.revision);
}

function applyRemoteEditorOperation(rawPayload) {
  if (!liveSyncEnabled.value || !isProjectMode.value || !activeProjectPath.value) {
    return;
  }

  const envelope = normalizeEditorOperationEnvelope(rawPayload);
  if (!envelope) {
    return;
  }

  if (envelope.path !== activeProjectPath.value) {
    return;
  }

  ensureEditorSyncPathState(envelope.path);

  if (envelope.revision > 0 && envelope.revision <= editorDocRevision) {
    return;
  }

  if (envelope.client_id === realtimeClientId.value) {
    if (editorSyncInflightOp && envelope.op_id && envelope.op_id === editorSyncInflightOp.op_id) {
      editorDocRevision = Math.max(editorDocRevision, envelope.revision);
      editorSyncInflightOp = null;
      editorSyncBusy.value = false;
      drainDeferredRemoteOperations();
      void flushEditorSync();
    }

    return;
  }

  if (editorSyncInflightOp) {
    enqueueDeferredRemoteOperation(envelope);
    return;
  }

  let transformedRemote = {
    ...envelope.operation,
    client_id: envelope.client_id,
    op_id: envelope.op_id,
  };

  editorSyncPendingOps = editorSyncPendingOps.map((pendingOperation) => {
    const pair = transformConcurrentTextOperations(
      transformedRemote,
      pendingOperation,
    );

    transformedRemote = {
      ...pair.remote,
      client_id: envelope.client_id,
      op_id: envelope.op_id,
    };

    return {
      ...pendingOperation,
      ...pair.local,
      client_id: pendingOperation.client_id,
      op_id: pendingOperation.op_id,
      path: pendingOperation.path,
    };
  });

  applyEditorTextOperation(transformedRemote);
  editorDocRevision = Math.max(editorDocRevision, envelope.revision);
  dirty.value = true;
}

function drainDeferredRemoteOperations() {
  if (editorSyncInflightOp || editorDeferredRemoteOps.length === 0) {
    return;
  }

  const queued = [...editorDeferredRemoteOps];
  editorDeferredRemoteOps = [];

  queued
    .sort((left, right) => left.revision - right.revision)
    .forEach((operationEnvelope) => {
      applyRemoteEditorOperation(operationEnvelope);
    });
}

function readDeltaText(delta) {
  const lines = Array.isArray(delta?.lines) ? delta.lines : [];
  if (lines.length === 0) {
    return "";
  }

  const separator = typeof delta?.nl === "string" ? delta.nl : "\n";
  return lines.join(separator);
}

function createEditorOperationFromDelta(delta) {
  if (!editor || !delta || !delta.start) {
    return null;
  }

  const documentRef = editor.session?.getDocument?.();
  if (!documentRef || typeof documentRef.positionToIndex !== "function") {
    return null;
  }

  const start = Number(documentRef.positionToIndex(delta.start, 0));
  if (!Number.isFinite(start) || start < 0) {
    return null;
  }

  const text = readDeltaText(delta);
  if (delta.action === "insert") {
    return normalizeTextOperation({
      start,
      delete_count: 0,
      insert_text: text,
    });
  }

  if (delta.action === "remove") {
    return normalizeTextOperation({
      start,
      delete_count: text.length,
      insert_text: "",
    });
  }

  return null;
}

function tryMergePendingEditorOperation(operation) {
  if (editorSyncPendingOps.length === 0) {
    return false;
  }

  const lastIndex = editorSyncPendingOps.length - 1;
  const previous = editorSyncPendingOps[lastIndex];
  if (!previous || previous.path !== operation.path) {
    return false;
  }

  if (
    previous.delete_count === 0
    && operation.delete_count === 0
    && previous.start + previous.insert_text.length === operation.start
  ) {
    editorSyncPendingOps[lastIndex] = {
      ...previous,
      insert_text: `${previous.insert_text}${operation.insert_text}`,
    };
    return true;
  }

  if (
    previous.insert_text === ""
    && operation.insert_text === ""
    && previous.start === operation.start
  ) {
    editorSyncPendingOps[lastIndex] = {
      ...previous,
      delete_count: previous.delete_count + operation.delete_count,
    };
    return true;
  }

  return false;
}

function queueLocalEditorOperation(delta) {
  if (!canSyncEditorRealtime() || applyingRemoteEditorSync) {
    return;
  }

  ensureEditorSyncPathState(activeProjectPath.value);

  const operation = createEditorOperationFromDelta(delta);
  if (!operation || isNoopTextOperation(operation)) {
    return;
  }

  const queuedOperation = {
    ...operation,
    path: activeProjectPath.value,
    client_id: realtimeClientId.value,
    op_id: createRealtimeOperationId(),
  };

  if (tryMergePendingEditorOperation(queuedOperation)) {
    scheduleEditorSync();
    return;
  }

  editorSyncPendingOps.push(queuedOperation);
  scheduleEditorSync();
}

async function bootstrapEditorRealtimeState(seedContent = "", options = {}) {
  if (
    !canUseProjectFs.value
    || !selectedProjectId.value
    || !isProjectMode.value
    || !activeProjectPath.value
    || editorStateBootstrapping
  ) {
    return;
  }

  ensureEditorSyncPathState(activeProjectPath.value);
  editorStateBootstrapping = true;

  const preserveLocal = Boolean(options?.preserveLocal);

  try {
    const response = await request({
      method: "POST",
      path: `/projects/${selectedProjectId.value}/realtime/editor-state`,
      auth: true,
      body: {
        path: activeProjectPath.value,
        seed_content: String(seedContent ?? ""),
      },
    });

    const revision = Number(response.data?.revision || 0);
    const remoteContent = String(response.data?.content ?? "");
    editorDocRevision = Number.isFinite(revision) ? Math.max(0, revision) : 0;
    editorDocSyncedPath = activeProjectPath.value;
    editorSyncPendingOps = [];
    editorSyncInflightOp = null;
    editorDeferredRemoteOps = [];
    editorSyncBusy.value = false;

    if (remoteContent !== currentText.value) {
      if (preserveLocal) {
        const replacementOperation = normalizeTextOperation({
          start: 0,
          delete_count: remoteContent.length,
          insert_text: currentText.value,
        });

        if (!isNoopTextOperation(replacementOperation)) {
          editorSyncPendingOps = [{
            ...replacementOperation,
            path: activeProjectPath.value,
            client_id: realtimeClientId.value,
            op_id: createRealtimeOperationId(),
          }, ...editorSyncPendingOps];
          scheduleEditorSync();
        }

        return;
      }

      setEditorContentFromRealtime(remoteContent);
    }
  } catch (_error) {
    // Best effort bootstrap. Local editing can continue and next sync will retry.
  } finally {
    editorStateBootstrapping = false;
  }
}

function scheduleEditorSync() {
  if (typeof window === "undefined" || !canSyncEditorRealtime() || applyingRemoteEditorSync) {
    return;
  }

  if (editorSyncDebounceTimerId !== null) {
    window.clearTimeout(editorSyncDebounceTimerId);
  }

  editorSyncDebounceTimerId = window.setTimeout(() => {
    editorSyncDebounceTimerId = null;
    void flushEditorSync();
  }, EDITOR_SYNC_DEBOUNCE_MS);
}

async function flushEditorSync() {
  if (!canSyncEditorRealtime() || applyingRemoteEditorSync || editorStateBootstrapping) {
    return;
  }

  ensureEditorSyncPathState(activeProjectPath.value);

  if (editorSyncBusy.value || editorSyncInflightOp || editorSyncPendingOps.length === 0) {
    return;
  }

  const nextOperation = editorSyncPendingOps.shift();
  if (!nextOperation) {
    return;
  }

  editorSyncBusy.value = true;
  editorSyncInflightOp = {
    ...nextOperation,
  };

  try {
    const payload = {
      path: nextOperation.path,
      client_id: nextOperation.client_id,
      op_id: nextOperation.op_id,
      base_revision: editorDocRevision,
      start: nextOperation.start,
      delete_count: nextOperation.delete_count,
      insert_text: nextOperation.insert_text,
      ...buildPresencePayload(),
    };

    const response = await request({
      method: "POST",
      path: `/projects/${selectedProjectId.value}/realtime/editor-sync`,
      auth: true,
      body: payload,
    });

    const revision = Number(response.data?.revision || 0);
    if (Number.isFinite(revision) && revision > editorDocRevision) {
      editorDocRevision = revision;
    }
    editorSyncInflightOp = null;
    editorSyncBusy.value = false;
    drainDeferredRemoteOperations();
    if (editorSyncPendingOps.length > 0) {
      void flushEditorSync();
    }
  } catch (syncError) {
    const status = Number(syncError?.status || 0);

    if (editorSyncInflightOp) {
      editorSyncPendingOps = [editorSyncInflightOp, ...editorSyncPendingOps];
      editorSyncInflightOp = null;
    }

    if (status === 409) {
      const revision = Number(syncError?.data?.revision || 0);
      if (Number.isFinite(revision) && revision >= 0) {
        editorDocRevision = revision;
      }

      const requiresResync = Boolean(syncError?.data?.requires_resync);
      const remoteContent = typeof syncError?.data?.content === "string"
        ? syncError.data.content
        : null;

      if (requiresResync && typeof remoteContent === "string") {
        setEditorContentFromRealtime(remoteContent, true);
        editorSyncPendingOps = [];
      }

      const operations = Array.isArray(syncError?.data?.operations)
        ? syncError.data.operations
        : [];

      operations.forEach((operationEntry) => {
        applyRemoteEditorOperation(operationEntry);
      });

      editorSyncBusy.value = false;
      drainDeferredRemoteOperations();
      if (editorSyncPendingOps.length > 0) {
        void flushEditorSync();
      }
      return;
    }

    editorSyncBusy.value = false;
    if (typeof window !== "undefined" && editorSyncRetryTimerId === null) {
      const retryDelay = status === 423
        ? EDITOR_SYNC_RETRY_LOCK_MS
        : EDITOR_SYNC_RETRY_DEFAULT_MS;

      editorSyncRetryTimerId = window.setTimeout(() => {
        editorSyncRetryTimerId = null;
        if (editorSyncPendingOps.length > 0) {
          void flushEditorSync();
        }
      }, retryDelay);
    }
  } finally {
    if (editorSyncBusy.value && !editorSyncInflightOp) {
      editorSyncBusy.value = false;
    }
  }
}

function scheduleLiveSyncPersist() {
  if (typeof window === "undefined" || !canSyncEditorRealtime()) {
    return;
  }

  if (autosaveDebounceTimerId !== null) {
    window.clearTimeout(autosaveDebounceTimerId);
  }

  autosaveDebounceTimerId = window.setTimeout(() => {
    autosaveDebounceTimerId = null;
    void persistLiveSyncChanges();
  }, 1000);
}

async function persistLiveSyncChanges() {
  if (!canSyncEditorRealtime() || !dirty.value) {
    return;
  }

  if (liveSyncBusy.value || saving.value || nodeBusy.value || moveBusy.value) {
    return;
  }

  liveSyncBusy.value = true;

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
  } catch (_error) {
    // Do not block editing when autosave fails.
  } finally {
    liveSyncBusy.value = false;
  }
}

function handleRealtimeFilesystemEvent(eventName, payload) {
  if (Number(payload?.user_id || 0) === currentUserId.value) {
    return;
  }

  if (eventName === "file_saved") {
    return;
  }

  if (treeRefreshTimerId !== null) {
    return;
  }

  treeRefreshTimerId = window.setTimeout(() => {
    treeRefreshTimerId = null;
    void loadTree();
  }, 400);
}

function scheduleProjectParticipantsRefresh(delay = 250) {
  if (typeof window === "undefined" || !canManageProjectSettings.value) {
    return;
  }

  if (participantsRefreshTimerId !== null) {
    window.clearTimeout(participantsRefreshTimerId);
  }

  participantsRefreshTimerId = window.setTimeout(() => {
    participantsRefreshTimerId = null;
    void loadProjectParticipants();
  }, delay);
}

function scheduleRealtimeReconnect() {
  if (typeof window === "undefined" || reconnectTimerId !== null || !canUseProjectFs.value) {
    return;
  }

  reconnectTimerId = window.setTimeout(() => {
    reconnectTimerId = null;

    if (canUseProjectFs.value) {
      void startRealtimeSession();
    }
  }, 1500);
}

function startEditorSyncFallbackPolling() {
  if (typeof window === "undefined" || editorSyncFallbackPollTimerId !== null || !canUseProjectFs.value) {
    return;
  }

  editorSyncFallbackPollTimerId = window.setInterval(() => {
    void pollEditorRealtimeUpdates();
  }, EDITOR_SYNC_FALLBACK_POLL_INTERVAL_MS);

  void pollEditorRealtimeUpdates();
}

function stopEditorSyncFallbackPolling() {
  if (editorSyncFallbackPollTimerId === null) {
    return;
  }

  window.clearInterval(editorSyncFallbackPollTimerId);
  editorSyncFallbackPollTimerId = null;
}

function scheduleRealtimeSubscribeWatch() {
  if (typeof window === "undefined") {
    return;
  }

  if (realtimeSubscribeWatchTimerId !== null) {
    window.clearTimeout(realtimeSubscribeWatchTimerId);
  }

  realtimeSubscribeWatchTimerId = window.setTimeout(() => {
    realtimeSubscribeWatchTimerId = null;

    if (!realtimeChannelSubscribed) {
      startEditorSyncFallbackPolling();
    }
  }, REALTIME_SUBSCRIBE_GRACE_MS);
}

async function startRealtimeSession() {
  if (typeof window === "undefined" || !canUseProjectFs.value || !selectedProjectId.value) {
    return;
  }

  stopRealtimeSession();

  const token = String(session.value?.accessToken || "");
  const channel = joinProjectRealtimeChannel(selectedProjectId.value, token);

  if (!channel) {
    startEditorSyncFallbackPolling();
    scheduleRealtimeReconnect();
    return;
  }

  activeRealtimeProjectId = selectedProjectId.value;
  realtimeChannelSubscribed = false;
  scheduleRealtimeSubscribeWatch();

  channel.subscribed(() => {
    realtimeChannelSubscribed = true;
    stopEditorSyncFallbackPolling();

    if (realtimeSubscribeWatchTimerId !== null) {
      window.clearTimeout(realtimeSubscribeWatchTimerId);
      realtimeSubscribeWatchTimerId = null;
    }

    if (reconnectTimerId !== null) {
      window.clearTimeout(reconnectTimerId);
      reconnectTimerId = null;
    }
  });

  channel.error(() => {
    realtimeChannelSubscribed = false;
    startEditorSyncFallbackPolling();
    scheduleRealtimeReconnect();
  });

  channel.listen(".realtime.presence.updated", (payload) => {
    upsertRealtimePeer(payload?.peer || payload);
  });

  channel.listen(".realtime.chat.message", (payload) => {
    appendChatMessage(payload?.message || payload);
  });

  channel.listen(".realtime.project.participants.updated", () => {
    scheduleProjectParticipantsRefresh();
  });

  channel.listen(".realtime.editor.operation", (payload) => {
    applyRemoteEditorOperation(payload?.operation || payload);
  });

  channel.listen(".realtime.terminal.session.updated", (payload) => {
    if (typeof window === "undefined") {
      return;
    }

    window.dispatchEvent(new CustomEvent("project-terminal-session-updated", {
      detail: {
        projectId: Number(selectedProjectId.value || 0),
        session: payload?.session || payload,
      },
    }));
  });

  channel.listen(".file_created", (payload) => {
    handleRealtimeFilesystemEvent("file_created", payload);
  });
  channel.listen(".folder_created", (payload) => {
    handleRealtimeFilesystemEvent("folder_created", payload);
  });
  channel.listen(".path_deleted", (payload) => {
    handleRealtimeFilesystemEvent("path_deleted", payload);
  });
  channel.listen(".path_moved", (payload) => {
    handleRealtimeFilesystemEvent("path_moved", payload);
  });
  channel.listen(".file_saved", (payload) => {
    handleRealtimeFilesystemEvent("file_saved", payload);
  });

  await syncRealtimePresence({ force: true });
  await loadChatMessages();
  if (liveSyncEnabled.value && isProjectMode.value && activeProjectPath.value) {
    await bootstrapEditorRealtimeState(currentText.value);
  }

  if (chatFallbackPollTimerId !== null) {
    window.clearInterval(chatFallbackPollTimerId);
  }
  chatFallbackPollTimerId = window.setInterval(() => {
    void loadChatMessages();
  }, CHAT_SYNC_FALLBACK_POLL_INTERVAL_MS);

  presenceIntervalTimerId = window.setInterval(() => {
    void syncRealtimePresence({ force: true });
  }, 10000);

  presencePruneTimerId = window.setInterval(() => {
    pruneRealtimePeers();
  }, 3000);
}

async function pollEditorRealtimeUpdates() {
  if (
    !canSyncEditorRealtime()
    || !selectedProjectId.value
    || !activeProjectPath.value
    || editorStateBootstrapping
    || applyingRemoteEditorSync
  ) {
    return;
  }

  if (editorSyncBusy.value || editorSyncInflightOp || editorSyncPendingOps.length > 0) {
    return;
  }

  try {
    const response = await request({
      method: "POST",
      path: `/projects/${selectedProjectId.value}/realtime/editor-state`,
      auth: true,
      body: {
        path: activeProjectPath.value,
      },
    });

    const revision = Number(response.data?.revision || 0);
    if (!Number.isFinite(revision) || revision < 0) {
      return;
    }

    const remoteContent = typeof response.data?.content === "string"
      ? response.data.content
      : null;
    const hasNewRevision = revision > editorDocRevision;

    if (hasNewRevision) {
      editorDocRevision = revision;
    }

    if (remoteContent !== null && hasNewRevision && remoteContent !== currentText.value) {
      setEditorContentFromRealtime(remoteContent, true);
      dirty.value = true;
    }
  } catch (_pollError) {
    // Best-effort fallback when websocket channel is unavailable.
  }
}

async function sendChatMessage() {
  if (!canUseProjectFs.value || !selectedProjectId.value || chatSending.value) {
    return;
  }

  const text = String(chatDraft.value || "").trim();
  if (!text) {
    return;
  }

  chatSending.value = true;

  try {
    const response = await request({
      method: "POST",
      path: `/projects/${selectedProjectId.value}/realtime/chat`,
      auth: true,
      body: {
        message: text,
      },
    });

    chatDraft.value = "";
    appendChatMessage(response.data?.message || {});
  } catch (chatError) {
    error.value = readError(chatError);
  } finally {
    chatSending.value = false;
  }
}

async function loadProjectParticipants() {
  if (!canManageProjectSettings.value || !selectedProjectId.value) {
    projectParticipants.value = [];
    return;
  }

  participantsLoading.value = true;

  try {
    const response = await request({
      method: "GET",
      path: "/project-participants",
      auth: true,
      query: {
        project_id: selectedProjectId.value,
        per_page: 200,
      },
    });

    projectParticipants.value = Array.isArray(response.data?.data) ? response.data.data : [];
  } catch (participantsError) {
    error.value = readError(participantsError);
  } finally {
    participantsLoading.value = false;
  }
}

async function saveProjectSettings(payload) {
  if (!canManageProjectSettings.value || !selectedProjectId.value || projectSettingsBusy.value) {
    return;
  }

  const nextName = String(payload?.name || "").trim();
  const nextDescriptionText = String(payload?.description || "").trim();
  const nextVisibility = payload?.visibility === "public" ? "public" : "private";
  if (!nextName) {
    error.value = t("editor.projectNameRequired");
    return;
  }

  projectSettingsBusy.value = true;
  error.value = "";

  try {
    const response = await request({
      method: "PATCH",
      path: `/projects/${selectedProjectId.value}`,
      auth: true,
      body: {
        name: nextName,
        description: nextDescriptionText !== "" ? nextDescriptionText : null,
        is_public: nextVisibility === "public",
      },
    });

    const payload = response.data || {};
    projectMeta.value = payload;
    projectName.value = typeof payload?.name === "string" ? payload.name.trim() : nextName;
    notice.value = t("editor.projectSettingsSaved");
    closeProjectSettingsModal();
  } catch (settingsError) {
    error.value = readError(settingsError);
  } finally {
    projectSettingsBusy.value = false;
  }
}

async function addCollaboratorById() {
  if (!canManageProjectSettings.value || !selectedProjectId.value || collaboratorBusy.value) {
    return;
  }

  const userId = Number.parseInt(String(collaboratorUserId.value || "").trim(), 10);
  if (!Number.isInteger(userId) || userId <= 0) {
    error.value = t("editor.invalidCollaboratorId");
    return;
  }

  collaboratorBusy.value = true;
  error.value = "";

  try {
    await request({
      method: "POST",
      path: "/project-participants",
      auth: true,
      body: {
        project_id: Number(selectedProjectId.value),
        user_id: userId,
      },
    });

    collaboratorUserId.value = "";
    await loadProjectParticipants();
    notice.value = t("editor.collaboratorAdded");
  } catch (participantError) {
    error.value = readError(participantError);
  } finally {
    collaboratorBusy.value = false;
  }
}

async function removeCollaborator(participant) {
  if (!canManageProjectSettings.value || !participant?.participant_id || collaboratorBusy.value) {
    return;
  }

  collaboratorBusy.value = true;
  error.value = "";

  try {
    await request({
      method: "DELETE",
      path: `/project-participants/${participant.participant_id}`,
      auth: true,
    });

    await loadProjectParticipants();
    notice.value = t("editor.collaboratorRemoved");
  } catch (participantError) {
    error.value = readError(participantError);
  } finally {
    collaboratorBusy.value = false;
  }
}

async function createInviteLink() {
  if (!canManageProjectSettings.value || !selectedProjectId.value || inviteBusy.value) {
    return;
  }

  inviteBusy.value = true;
  error.value = "";

  try {
    const response = await request({
      method: "POST",
      path: "/project-invitations",
      auth: true,
      body: {
        project_id: Number(selectedProjectId.value),
      },
    });

    const token = String(response.data?.invite_token || "");
    if (!token) {
      throw new Error(t("common.requestFailed"));
    }

    const baseUrl = typeof window !== "undefined" ? window.location.origin : "";
    const invitePath = `/projects/${selectedProjectId.value}/editor?invite=${encodeURIComponent(token)}`;
    inviteLink.value = baseUrl ? `${baseUrl}${invitePath}` : invitePath;
    notice.value = t("editor.inviteLinkReady");
  } catch (inviteError) {
    error.value = readError(inviteError);
  } finally {
    inviteBusy.value = false;
  }
}

async function copyInviteLink() {
  if (!inviteLink.value) {
    return;
  }

  try {
    if (typeof navigator !== "undefined" && navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(inviteLink.value);
      notice.value = t("editor.inviteLinkCopied");
      return;
    }
  } catch (_error) {
    // fallback below
  }

  const textarea = document.createElement("textarea");
  textarea.value = inviteLink.value;
  textarea.style.position = "fixed";
  textarea.style.left = "-9999px";
  document.body.appendChild(textarea);
  textarea.focus();
  textarea.select();
  document.execCommand("copy");
  textarea.remove();
  notice.value = t("editor.inviteLinkCopied");
}

async function acceptInviteFromQuery() {
  if (!isAuthenticated.value || inviteHandlingBusy.value) {
    return;
  }

  const inviteToken = typeof route.query.invite === "string" ? route.query.invite.trim() : "";
  if (!inviteToken) {
    return;
  }

  inviteHandlingBusy.value = true;

  try {
    const response = await request({
      method: "POST",
      path: "/project-invitations/accept",
      auth: true,
      body: {
        invite_token: inviteToken,
      },
    });

    const acceptedProjectId = String(response.data?.project_id || "").trim();
    const acceptedProjectName = String(response.data?.project_name || "").trim();
    notice.value = acceptedProjectName
      ? t("editor.inviteAcceptedProject", { name: acceptedProjectName })
      : t("editor.inviteAccepted");
    error.value = "";

    const query = { ...route.query };
    delete query.invite;

    if (acceptedProjectId && acceptedProjectId !== selectedProjectId.value) {
      await router.replace({
        path: `/projects/${acceptedProjectId}/editor`,
        query,
      });
      return;
    }

    await router.replace({ query });
    if (acceptedProjectId && acceptedProjectId === selectedProjectId.value) {
      void loadProjectName();
      void loadTree();
    }
  } catch (inviteError) {
    error.value = readError(inviteError);

    const query = { ...route.query };
    delete query.invite;
    await router.replace({ query });
  } finally {
    inviteHandlingBusy.value = false;
  }
}

function startCreateNode(kind) {
  if (!canUseProjectFs.value || nodeBusy.value) {
    return;
  }

  if (newNodeKind.value === kind) {
    cancelCreateNode();
    return;
  }

  newNodeKind.value = kind;
  newNodePath.value = "";
  error.value = "";
  notice.value = "";

  nextTick(() => {
    if (newNodeInput.value && typeof newNodeInput.value.focus === "function") {
      newNodeInput.value.focus();
    }
  });
}

function cancelCreateNode() {
  newNodeKind.value = "";
  newNodePath.value = "";
}

async function submitCreateNode() {
  if (!newNodeKind.value || !newNodePath.value) {
    return;
  }

  if (newNodeKind.value === "folder") {
    const created = await createFolder(newNodePath.value);
    if (created) {
      cancelCreateNode();
    }
    return;
  }

  const created = await createFile(newNodePath.value);
  if (created) {
    cancelCreateNode();
  }
}

async function loadTree() {
  if (!canUseProjectFs.value) {
    tree.value = [];
    return;
  }

  treeLoading.value = true;
  error.value = "";

  try {
    const response = await request({
      method: "GET",
      path: `/projects/${selectedProjectId.value}/filesystem/tree`,
      auth: true,
    });

    tree.value = Array.isArray(response.data?.items) ? response.data.items : [];

    if (selectedTreeType.value !== "root" && selectedTreePath.value && !hasPath(selectedTreePath.value, tree.value)) {
      selectedTreePath.value = "";
      selectedTreeType.value = "";
    }

    const hasActiveProjectFile =
      isProjectMode.value &&
      activeProjectPath.value &&
      hasPath(activeProjectPath.value, tree.value);

    if (!hasActiveProjectFile) {
      const first = firstRootFile(tree.value) || firstFile(tree.value);

      if (first) {
        await openFile(first);
      } else {
        clearProjectEditor();
      }
    }
  } catch (treeError) {
    error.value = readError(treeError);
  } finally {
    treeLoading.value = false;
  }
}

async function createFile(pathValue = "") {
  const requestedPath = String(pathValue || "").trim();

  if (!canUseProjectFs.value || !requestedPath) {
    return false;
  }

  nodeBusy.value = true;
  error.value = "";
  notice.value = "";

  try {
    const response = await request({
      method: "POST",
      path: `/projects/${selectedProjectId.value}/filesystem`,
      auth: true,
      body: {
        action: "create_file",
        path: requestedPath,
        content: "",
      },
    });

    await loadTree();
    await openFile(response.data?.path || requestedPath);
    selectTreeItem(response.data?.path || requestedPath, "file");
    notice.value = t("editor.fileCreated");
    return true;
  } catch (createError) {
    error.value = readError(createError);
    return false;
  } finally {
    nodeBusy.value = false;
  }
}

async function createFolder(pathValue = "") {
  const requestedPath = String(pathValue || "").trim();

  if (!canUseProjectFs.value || !requestedPath) {
    return false;
  }

  nodeBusy.value = true;
  error.value = "";
  notice.value = "";

  try {
    const response = await request({
      method: "POST",
      path: `/projects/${selectedProjectId.value}/filesystem`,
      auth: true,
      body: {
        action: "create_folder",
        path: requestedPath,
      },
    });

    const createdPath = response.data?.path || requestedPath;
    expandParents(createdPath);
    await loadTree();
    selectTreeItem(createdPath, "folder");
    notice.value = t("editor.folderCreated");
    return true;
  } catch (createError) {
    error.value = readError(createError);
    return false;
  } finally {
    nodeBusy.value = false;
  }
}

async function confirmLoseChanges() {
  if (!dirty.value) {
    return true;
  }

  return window.confirm(t("editor.unsavedPrompt"));
}

async function openFile(path) {
  if (!canUseProjectFs.value || !path) {
    return;
  }

  const confirmed = await confirmLoseChanges();
  if (!confirmed) {
    return;
  }

  error.value = "";

  try {
    const response = await request({
      method: "GET",
      path: `/projects/${selectedProjectId.value}/filesystem/file`,
      auth: true,
      query: { path },
    });

    isProjectMode.value = true;
    activeProjectPath.value = path;
    selectTreeItem(path, "file");
    currentPath.value = path;
    currentText.value = response.data?.content || "";
    lastKnownFileUpdatedAt.value = String(response.data?.updated_at || "");
    dirty.value = false;
    expandParents(path);
    syncEditor();
    ensureEditorSyncPathState(path);
    if (liveSyncEnabled.value) {
      await bootstrapEditorRealtimeState(currentText.value);
    }
  } catch (openError) {
    error.value = readError(openError);
  }
}

function clickTreeItem(item) {
  selectTreeItem(item.path, item.type);

  if (item.type === "folder") {
    if (isExpanded(item.path)) {
      expanded.value = expanded.value.filter((path) => path !== item.path);
    } else {
      expanded.value = [...expanded.value, item.path];
    }
    return;
  }

  void openFile(item.path);
}

async function removeTreeItem(item) {
  if (!canUseProjectFs.value) {
    return;
  }

  const confirmed = window.confirm(
    t("editor.deleteConfirm", {
      type: localizeNodeType(item.type),
      path: item.path,
    }),
  );
  if (!confirmed) {
    return;
  }

  error.value = "";

  try {
    await request({
      method: "DELETE",
      path: `/projects/${selectedProjectId.value}/filesystem/item`,
      auth: true,
      query: { path: item.path },
    });

    if (selectedTreeType.value !== "root" && selectedTreePath.value) {
      if (selectedTreePath.value === item.path || selectedTreePath.value.startsWith(`${item.path}/`)) {
        selectedTreePath.value = "";
        selectedTreeType.value = "";
      }
    }

    if (isProjectMode.value && (activeProjectPath.value === item.path || activeProjectPath.value.startsWith(`${item.path}/`))) {
      clearProjectEditor();
    }

    await loadTree();
    notice.value = t("editor.deletedNotice", {
      type: localizeNodeType(item.type),
    });
  } catch (removeError) {
    error.value = readError(removeError);
  }
}

async function saveFile() {
  error.value = "";

  if (!dirty.value) {
    notice.value = t("editor.noChanges");
    return;
  }

  if (!isProjectRoute.value) {
    guest.path = currentPath.value;
    guest.content = currentText.value;
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

function downloadFile() {
  const blob = new Blob([currentText.value || ""], { type: "text/plain;charset=utf-8" });
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = currentPath.value.split("/").pop() || "code.txt";
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}

async function startForgejoConnect() {
  if (!isAuthenticated.value) {
    error.value = t("editor.loginRequiredForgejo");
    return;
  }

  forgejoBusy.value = true;
  error.value = "";

  try {
    const response = await request({
      method: "POST",
      path: "/forgejo/oauth/start",
      auth: true,
      body: { mode: "connect" },
    });

    const authUrl = response.data?.auth_url || "";
    if (!authUrl) {
      throw new Error(t("common.requestFailed"));
    }

    try {
      window.localStorage.setItem(FORGEJO_RETURN_KEY, route.fullPath || "/projects");
    } catch (_error) {
      // ignore storage limitations
    }

    window.location.assign(authUrl);
  } catch (connectError) {
    error.value = readError(connectError);
    forgejoBusy.value = false;
  }
}

async function connectProjectForgejo() {
  if (!selectedProjectId.value) {
    error.value = t("editor.selectProjectFirst");
    return;
  }

  forgejoBusy.value = true;
  error.value = "";

  try {
    const body = forgejoMode.value === "existing"
      ? { mode: "existing", repo_url: forgejoRepoUrl.value }
      : { mode: "create", repo_name: forgejoRepoName.value || "project", private: true };

    const response = await request({
      method: "POST",
      path: `/projects/${selectedProjectId.value}/forgejo/connect`,
      auth: true,
      body,
    });

    if (response.data && typeof response.data === "object") {
      projectMeta.value = response.data;
      if (typeof response.data?.name === "string" && response.data.name.trim() !== "") {
        projectName.value = response.data.name.trim();
      }
    }

    notice.value = t("editor.connectedRepo");
  } catch (connectError) {
    error.value = readError(connectError);
  } finally {
    forgejoBusy.value = false;
  }
}

async function pushToForgejo() {
  if (!selectedProjectId.value) {
    error.value = t("editor.selectProjectFirst");
    return;
  }

  forgejoBusy.value = true;
  error.value = "";

  try {
    const body = forgejoMessage.value ? { message: forgejoMessage.value } : {};

    const response = await request({
      method: "POST",
      path: `/projects/${selectedProjectId.value}/forgejo/save`,
      auth: true,
      body,
    });

    notice.value = response.data?.status === "nothing_to_commit"
      ? t("editor.nothingToCommit")
      : t("editor.pushed");
  } catch (pushError) {
    error.value = readError(pushError);
  } finally {
    forgejoBusy.value = false;
  }
}

async function syncProjectWorkspace() {
  if (!canUseProjectFs.value || !selectedProjectId.value || syncBusy.value) {
    return;
  }

  if (!canSyncFromForgejo.value) {
    await loadTree();
    return;
  }

  repoSyncBusy.value = true;
  error.value = "";
  notice.value = "";

  try {
    const response = await request({
      method: "POST",
      path: `/projects/${selectedProjectId.value}/forgejo/sync`,
      auth: true,
      body: {},
    });

    await loadTree();

    if (isProjectMode.value && activeProjectPath.value && !dirty.value && hasPath(activeProjectPath.value, tree.value)) {
      await openFile(activeProjectPath.value);
    }

    notice.value = response.data?.status === "up_to_date"
      ? t("editor.repoUpToDate")
      : t("editor.repoSynced");
  } catch (syncError) {
    error.value = readError(syncError);
  } finally {
    repoSyncBusy.value = false;
  }
}

function onAuthChanged() {
  session.value = getSession();

  if (!isAuthenticated.value) {
    selectedProjectId.value = "";
    projectName.value = "";
    projectMeta.value = null;
    resetProjectTreeState();
    resetProjectSettingsState();
    resetRealtimeState();
    switchToGuest();
    return;
  }

  if (!isProjectRoute.value) {
    selectedProjectId.value = "";
    projectName.value = "";
    projectMeta.value = null;
    resetProjectTreeState();
    resetProjectSettingsState();
    resetRealtimeState();
    return;
  }

  if (!selectedProjectId.value) {
    projectName.value = "";
    projectMeta.value = null;
    resetProjectTreeState();
    resetProjectSettingsState();
    resetRealtimeState();
    switchToGuest();
    return;
  }

  if (!isProjectMode.value || !activeProjectPath.value) {
    clearProjectEditor();
  }

  void loadProjectName();
  void loadTree();
  void acceptInviteFromQuery();
}

function parseDownloadFileName(disposition, fallbackName) {
  const header = String(disposition || "");
  const utf8Match = header.match(/filename\*=UTF-8''([^;]+)/i);
  if (utf8Match && utf8Match[1]) {
    try {
      return decodeURIComponent(utf8Match[1]);
    } catch (_error) {
      // ignore invalid header encoding
    }
  }

  const plainMatch = header.match(/filename="?([^";]+)"?/i);
  if (plainMatch && plainMatch[1]) {
    return plainMatch[1];
  }

  return fallbackName;
}

async function downloadArchive(pathValue = "") {
  if (!canUseProjectFs.value || !selectedProjectId.value || downloadBusy.value) {
    return;
  }

  downloadBusy.value = true;
  downloadingScope.value = pathValue ? "folder" : "project";
  error.value = "";
  notice.value = "";

  try {
    const url = buildApiUrl(
      `/projects/${selectedProjectId.value}/filesystem/download`,
      pathValue ? { path: pathValue } : undefined,
    );

    const headers = {
      Accept: "application/zip",
    };

    const token = getSession().accessToken || "";
    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }

    const response = await fetch(url, { method: "GET", headers });

    if (!response.ok) {
      const raw = await response.text();
      let data = null;

      try {
        data = JSON.parse(raw);
      } catch (_error) {
        data = { message: raw };
      }

      throw {
        status: response.status,
        data,
        message: typeof data?.message === "string" ? data.message : `HTTP ${response.status}`,
      };
    }

    const blob = await response.blob();
    const fallbackName = pathValue
      ? `${basename(pathValue) || "folder"}.zip`
      : `project-${selectedProjectId.value}.zip`;
    const downloadName = parseDownloadFileName(response.headers.get("content-disposition"), fallbackName);
    const objectUrl = window.URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = objectUrl;
    link.download = downloadName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(objectUrl);

    notice.value = pathValue
      ? t("editor.folderDownloaded", { path: pathValue })
      : t("editor.projectDownloaded");
  } catch (downloadError) {
    error.value = readError(downloadError);
  } finally {
    downloadBusy.value = false;
    downloadingScope.value = "";
  }
}

async function downloadProjectArchive() {
  await downloadArchive("");
}

async function downloadSelectedFolder() {
  if (!canDownloadSelectedFolder.value || !selectedTreePath.value) {
    error.value = t("editor.selectFolderToDownload");
    return;
  }

  await downloadArchive(selectedTreePath.value);
}

function initEditor() {
  if (!editorHost.value) {
    return;
  }

  editor = ace.edit(editorHost.value);
  editor.setOptions({
    fontSize: 14,
    tabSize: 2,
    useSoftTabs: true,
    showPrintMargin: false,
    enableBasicAutocompletion: true,
    enableLiveAutocompletion: true,
  });
  editor.session.setUseWrapMode(true);
  editor.session.setUseWorker(false);
  editor.setTheme(`ace/theme/${editorTheme.value}`);
  editor.session.setMode(`ace/mode/${editorLanguage.value}`);
  editor.session.setValue(currentText.value);

  editor.on("change", (delta) => {
    if (syncingEditor) {
      return;
    }

    currentText.value = editor.getValue();
    dirty.value = true;
    queueLocalEditorOperation(delta);
    scheduleLiveSyncPersist();
  });

  editor.selection.on("changeCursor", () => {
    schedulePresenceSync(500);
  });
}

watch(editorLanguage, (value) => {
  if (editor) {
    editor.session.setMode(`ace/mode/${value}`);
    editor.session.setUseWorker(false);
  }
});

watch(editorTheme, (value) => {
  if (editor) {
    editor.setTheme(`ace/theme/${value}`);
  }
});

watch(selectedProjectId, (value) => {
  if (!value || !isProjectRoute.value || !isAuthenticated.value) {
    projectName.value = "";
    projectMeta.value = null;
    resetProjectTreeState();
    resetProjectSettingsState();
    resetRealtimeState();
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
      projectName.value = "";
      projectMeta.value = null;
      resetProjectTreeState();
      resetProjectSettingsState();
      resetRealtimeState();
      switchToGuest();
      return;
    }

    if (!selectedProjectId.value) {
      projectName.value = "";
      projectMeta.value = null;
      resetProjectTreeState();
      resetProjectSettingsState();
      resetRealtimeState();
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

watch(activeProjectPath, () => {
  lastKnownFileUpdatedAt.value = "";
  if (!activeProjectPath.value) {
    resetEditorSyncState();
  } else {
    ensureEditorSyncPathState(activeProjectPath.value);
  }
  schedulePresenceSync(0);
});

watch(liveSyncEnabled, (enabled) => {
  if (!enabled) {
    return;
  }

  if (isProjectMode.value && activeProjectPath.value) {
    void bootstrapEditorRealtimeState(currentText.value, { preserveLocal: true });
    scheduleEditorSync();
  }
});

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

onMounted(() => {
  restoreEditorLayoutPrefs();
  restoreGuest();
  editorTheme.value = resolveDefaultEditorTheme();
  syncProjectIdFromRoute();

  if (route.query.forgejo === "connected") {
    notice.value = t("editor.authConnectedNotice");
    const query = { ...route.query };
    delete query.forgejo;
    router.replace({ query });
  }

  if (typeof window !== "undefined") {
    viewportWidth.value = window.innerWidth;
    window.addEventListener("resize", onViewportResize);
    normalizeTerminalDockHeight();
  }

  window.addEventListener("auth-changed", onAuthChanged);
  initEditor();
  onAuthChanged();
  nextTick(() => {
    normalizeEditorLayoutWidths();
    scheduleEditorResize();
  });
});

onUnmounted(() => {
  stopPaneResize();
  stopTerminalDockResize();

  if (typeof window !== "undefined") {
    window.removeEventListener("resize", onViewportResize);
  }

  window.removeEventListener("auth-changed", onAuthChanged);
  stopRealtimeSession();
  disconnectRealtimeClient();

  if (typeof window !== "undefined" && editorResizeFrameId !== null) {
    window.cancelAnimationFrame(editorResizeFrameId);
    editorResizeFrameId = null;
  }

  if (editor) {
    editor.destroy();
    editor.container.remove();
    editor = null;
  }
});
</script>
