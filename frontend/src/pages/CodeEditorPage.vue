<template>
  <div class="page editor-page">
    <section class="card editor-intro">
      <div>
        <h1>{{ t("editor.title") }}</h1>
        <p class="muted-text">
          {{ t("editor.subtitle") }}
        </p>
      </div>
      <p class="mode-chip">{{ isAuthenticated ? t("editor.modeAuthorized") : t("editor.modeGuest") }}</p>
    </section>

    <div class="editor-layout">
      <aside class="card editor-sidebar">
        <section class="sidebar-block">
          <div class="sidebar-head">
            <h2>{{ workspaceTitle }}</h2>
            <button
              v-if="isProjectRoute"
              class="btn btn-sm btn-ghost"
              type="button"
              @click="goToProjects"
            >
              {{ t("editor.backToProjects") }}
            </button>
            <button v-else class="btn btn-sm btn-secondary" type="button" @click="switchToGuest">{{ t("editor.guestFile") }}</button>
          </div>
          <p class="muted-text">{{ t("editor.currentFile") }} <code>{{ currentPath }}</code></p>
          <p v-if="!isAuthenticated" class="muted-text">
            <RouterLink to="/register">{{ t("editor.registerPrompt") }}</RouterLink>
            {{ t("common.or") }}
            <RouterLink to="/login">{{ t("editor.loginPrompt") }}</RouterLink>
            {{ t("editor.authPromptSuffix") }}
          </p>
        </section>

        <section v-if="canUseProjectFs" class="sidebar-block">
          <div class="sidebar-head">
            <h2>{{ t("editor.files") }}</h2>
            <button class="btn btn-sm btn-ghost" type="button" :disabled="treeLoading" @click="loadTree">
              {{ treeLoading ? t("editor.syncing") : t("editor.sync") }}
            </button>
          </div>
          <div class="tree-utility-actions">
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
                <span>{{ t("editor.owner") }}</span>
                <input v-model.trim="forgejoOwner" type="text" maxlength="255" placeholder="team-or-user" />
              </label>

              <label v-if="forgejoMode === 'existing'" class="field">
                <span>{{ t("editor.repo") }}</span>
                <input v-model.trim="forgejoRepo" type="text" maxlength="255" placeholder="repo-name" />
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

      <section class="card editor-main">
        <div class="editor-toolbar">
          <div class="editor-toolbar-main">
            <strong>{{ currentPath }}</strong>
            <span class="muted-text">
              {{ toolbarContextLabel }}
            </span>
          </div>

          <div class="editor-toolbar-actions">
            <label class="field-inline">
              <span>{{ t("common.language") }}</span>
              <select v-model="editorLanguage">
                <option v-for="item in languageOptions" :key="item.value" :value="item.value">{{ item.label }}</option>
              </select>
            </label>

            <label class="field-inline">
              <span>{{ t("common.theme") }}</span>
              <select v-model="editorTheme">
                <option v-for="item in themeOptions" :key="item.value" :value="item.value">{{ item.label }}</option>
              </select>
            </label>

            <button class="btn btn-secondary" type="button" @click="downloadFile">{{ t("editor.downloadFile") }}</button>
            <button class="btn" type="button" :disabled="saving" @click="saveFile">
              {{ saving ? t("common.saving") : isProjectRoute ? t("editor.saveToProject") : t("editor.saveLocal") }}
            </button>
          </div>
        </div>

        <p v-if="notice" class="notice-banner">{{ notice }}</p>
        <p v-if="error" class="error-banner">{{ error }}</p>

        <div ref="editorHost" class="ace-editor-host" />
      </section>
    </div>
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

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const GUEST_KEY = "livecode.editor.guest";
const FORGEJO_RETURN_KEY = "livecode.forgejo.return_path";

const session = ref(getSession());
const isAuthenticated = computed(() => Boolean(session.value.accessToken));
const isProjectRoute = computed(() => Boolean(route.params.projectId));

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
let editor = null;
let syncingEditor = false;

const editorLanguage = ref("javascript");
const editorTheme = ref("github");

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

const forgejoMode = ref("create");
const forgejoRepoName = ref("");
const forgejoOwner = ref("");
const forgejoRepo = ref("");
const forgejoMessage = ref("");

const notice = ref("");
const error = ref("");

const canUseProjectFs = computed(() => isAuthenticated.value && isProjectRoute.value && Boolean(selectedProjectId.value));
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
const toolbarContextLabel = computed(() => {
  if (!isProjectRoute.value) {
    return t("editor.toolbarGuest");
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
  dirty.value = false;
  syncEditor();
}

function clearProjectEditor() {
  isProjectMode.value = false;
  activeProjectPath.value = "";
  currentPath.value = "";
  currentText.value = "";
  dirty.value = false;
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
    return;
  }

  const requestedId = selectedProjectId.value;

  try {
    const response = await request({
      method: "GET",
      path: `/projects/${requestedId}`,
      auth: true,
    });

    const name = typeof response.data?.name === "string" ? response.data.name.trim() : "";
    if (selectedProjectId.value === requestedId) {
      projectName.value = name;
    }
  } catch (_error) {
    if (selectedProjectId.value === requestedId) {
      projectName.value = "";
    }
  }
}

async function goToProjects() {
  await router.push("/projects");
}

function resetProjectTreeState() {
  tree.value = [];
  expanded.value = [];
  selectedTreePath.value = "";
  selectedTreeType.value = "";
  cancelCreateNode();
  resetDragState();
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
    dirty.value = false;
    expandParents(path);
    syncEditor();
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
    await request({
      method: "PUT",
      path: `/projects/${selectedProjectId.value}/filesystem/file`,
      auth: true,
      body: {
        path: activeProjectPath.value,
        content: currentText.value,
      },
    });

    dirty.value = false;
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
      ? { mode: "existing", owner: forgejoOwner.value, repo: forgejoRepo.value }
      : { mode: "create", repo_name: forgejoRepoName.value || "project", private: true };

    await request({
      method: "POST",
      path: `/projects/${selectedProjectId.value}/forgejo/connect`,
      auth: true,
      body,
    });

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

function onAuthChanged() {
  session.value = getSession();

  if (!isAuthenticated.value) {
    selectedProjectId.value = "";
    projectName.value = "";
    resetProjectTreeState();
    switchToGuest();
    return;
  }

  if (!isProjectRoute.value) {
    selectedProjectId.value = "";
    projectName.value = "";
    resetProjectTreeState();
    return;
  }

  if (!selectedProjectId.value) {
    projectName.value = "";
    resetProjectTreeState();
    switchToGuest();
    return;
  }

  if (!isProjectMode.value || !activeProjectPath.value) {
    clearProjectEditor();
  }

  void loadProjectName();
  void loadTree();
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

  editor.on("change", () => {
    if (syncingEditor) {
      return;
    }

    currentText.value = editor.getValue();
    dirty.value = true;
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
    resetProjectTreeState();
    return;
  }

  if (!isProjectMode.value || !activeProjectPath.value) {
    clearProjectEditor();
  }

  selectProjectRoot();
  void loadProjectName();
  void loadTree();
});

watch(
  () => route.params.projectId,
  () => {
    syncProjectIdFromRoute();

    if (!isProjectRoute.value) {
      selectedProjectId.value = "";
      projectName.value = "";
      resetProjectTreeState();
      switchToGuest();
      return;
    }

    if (!selectedProjectId.value) {
      projectName.value = "";
      resetProjectTreeState();
      switchToGuest();
    }
  },
);

onMounted(() => {
  restoreGuest();
  editorTheme.value = resolveDefaultEditorTheme();
  syncProjectIdFromRoute();

  if (route.query.forgejo === "connected") {
    notice.value = t("editor.authConnectedNotice");
    const query = { ...route.query };
    delete query.forgejo;
    router.replace({ query });
  }

  window.addEventListener("auth-changed", onAuthChanged);
  initEditor();
  onAuthChanged();
});

onUnmounted(() => {
  window.removeEventListener("auth-changed", onAuthChanged);

  if (editor) {
    editor.destroy();
    editor.container.remove();
    editor = null;
  }
});
</script>
