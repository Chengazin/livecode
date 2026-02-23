<template>
  <section v-if="canUseProjectFs" class="sidebar-block">
    <div class="sidebar-head">
      <h2>{{ t("editor.files") }}</h2>
      <button class="btn btn-sm btn-ghost" type="button" :disabled="syncBusy" @click="emit('sync-project-workspace')">
        {{ syncBusy ? t("editor.syncing") : t("editor.sync") }}
      </button>
    </div>
    <div class="tree-utility-actions">
      <button
        class="btn btn-sm btn-ghost"
        type="button"
        :disabled="!currentPath"
        @click="emit('download-file')"
      >
        {{ t("editor.downloadFile") }}
      </button>
      <button
        class="btn btn-sm btn-secondary"
        type="button"
        :disabled="downloadBusy || !canUseProjectFs"
        @click="emit('download-project-archive')"
      >
        {{ downloadBusy && downloadingScope === "project" ? t("editor.downloading") : t("editor.downloadProject") }}
      </button>
      <button
        class="btn btn-sm btn-ghost"
        type="button"
        :disabled="downloadBusy || !canDownloadSelectedFolder"
        @click="emit('download-selected-folder')"
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
          @click="emit('start-create-node', 'file')"
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
          @click="emit('start-create-node', 'folder')"
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
          @click="emit('move-selected-to-root')"
        >
          <svg viewBox="0 0 24 24" class="tree-action-svg" aria-hidden="true">
            <path d="M12 18V7" />
            <path d="M8 11l4-4 4 4" />
            <path d="M5 19h14" />
          </svg>
        </button>
      </div>

      <form v-if="newNodeKind" class="tree-inline-create" @submit.prevent="emit('submit-create-node')">
        <span class="tree-inline-kind" :class="`kind-${newNodeKind}`" />
        <input
          :ref="newNodeInputRef"
          :value="newNodePath"
          class="tree-inline-input"
          type="text"
          maxlength="2048"
          :placeholder="newNodeKind === 'folder' ? t('editor.createFolderPlaceholder') : t('editor.createFilePlaceholder')"
          @input="emit('update:newNodePath', $event.target.value)"
          @keydown.esc.prevent="emit('cancel-create-node')"
        />
        <button class="btn btn-sm btn-secondary" type="submit" :disabled="nodeBusy || !newNodePath">
          {{ t("editor.create") }}
        </button>
        <button class="btn btn-sm btn-ghost" type="button" :disabled="nodeBusy" @click="emit('cancel-create-node')">
          {{ t("editor.cancel") }}
        </button>
      </form>

      <div
        class="tree-root-drop"
        :class="{ 'drop-target': isRootDropTarget, selected: selectedTreeType === 'root' }"
        @click="emit('select-project-root')"
        @dragover.prevent="emit('root-drag-over', $event)"
        @dragleave="emit('root-drag-leave')"
        @drop.prevent="emit('root-drop')"
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
        @click="emit('select-tree-item', item.path, item.type)"
        @dragover.prevent="emit('tree-row-drag-over', item, $event)"
        @dragleave="emit('tree-row-drag-leave', item)"
        @drop.prevent="emit('tree-row-drop', item)"
      >
        <button
          class="tree-drag-handle"
          type="button"
          :disabled="moveBusy"
          draggable="true"
          @dragstart="emit('tree-drag-start', item, $event)"
          @dragend="emit('reset-drag-state')"
          @click.stop="emit('select-tree-item', item.path, item.type)"
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
          @click="emit('click-tree-item', item)"
          @dblclick.stop.prevent="emit('rename-tree-item', item)"
        >
          <span class="tree-indent" :style="{ width: `${item.depth * 14}px` }" />
          <span class="tree-prefix">{{ item.type === "folder" ? (isExpanded(item.path) ? "v" : ">") : "-" }}</span>
          <span class="tree-name">{{ item.name }}</span>
        </button>
        <div class="tree-row-actions">
          <button
            class="tree-rename"
            type="button"
            :title="t('editor.renameTitle')"
            :disabled="moveBusy || nodeBusy"
            @click.stop="emit('rename-tree-item', item)"
          >
            <svg viewBox="0 0 24 24" class="tree-action-svg" aria-hidden="true">
              <path d="M4 20l4.5-1 9-9-3.5-3.5-9 9L4 20z" />
              <path d="M13.5 6.5l3.5 3.5" />
            </svg>
          </button>
          <button
            class="tree-delete"
            type="button"
            :title="t('editor.deleteTitle')"
            :disabled="moveBusy || nodeBusy"
            @click.stop="emit('remove-tree-item', item)"
          >
            x
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { defineEmits, defineProps } from "vue";

defineProps({
  t: {
    type: Function,
    required: true,
  },
  canUseProjectFs: {
    type: Boolean,
    default: false,
  },
  syncBusy: {
    type: Boolean,
    default: false,
  },
  currentPath: {
    type: String,
    default: "",
  },
  downloadBusy: {
    type: Boolean,
    default: false,
  },
  canDownloadSelectedFolder: {
    type: Boolean,
    default: false,
  },
  downloadingScope: {
    type: String,
    default: "",
  },
  treeLoading: {
    type: Boolean,
    default: false,
  },
  flatTree: {
    type: Array,
    default: () => [],
  },
  nodeBusy: {
    type: Boolean,
    default: false,
  },
  moveBusy: {
    type: Boolean,
    default: false,
  },
  canMoveSelectedToRoot: {
    type: Boolean,
    default: false,
  },
  newNodeKind: {
    type: String,
    default: "",
  },
  newNodePath: {
    type: String,
    default: "",
  },
  newNodeInputRef: {
    type: [Function, Object],
    default: null,
  },
  isRootDropTarget: {
    type: Boolean,
    default: false,
  },
  selectedTreeType: {
    type: String,
    default: "",
  },
  isProjectMode: {
    type: Boolean,
    default: false,
  },
  activeProjectPath: {
    type: String,
    default: "",
  },
  selectedTreePath: {
    type: String,
    default: "",
  },
  draggedPath: {
    type: String,
    default: "",
  },
  dropTargetPath: {
    type: String,
    default: "",
  },
  isExpanded: {
    type: Function,
    required: true,
  },
});

const emit = defineEmits([
  "sync-project-workspace",
  "download-file",
  "download-project-archive",
  "download-selected-folder",
  "start-create-node",
  "move-selected-to-root",
  "update:newNodePath",
  "cancel-create-node",
  "submit-create-node",
  "select-project-root",
  "root-drag-over",
  "root-drag-leave",
  "root-drop",
  "select-tree-item",
  "tree-row-drag-over",
  "tree-row-drag-leave",
  "tree-row-drop",
  "tree-drag-start",
  "reset-drag-state",
  "click-tree-item",
  "rename-tree-item",
  "remove-tree-item",
]);
</script>
