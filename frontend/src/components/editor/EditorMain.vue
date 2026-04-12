<template>
  <p v-if="notice" class="notice-banner">{{ notice }}</p>
  <p v-if="error" class="error-banner">{{ error }}</p>

  <div class="editor-stage">
    <slot name="editor-stage" />
  </div>

  <TerminalDock
    :t="t"
    :can-use-project-fs="canUseProjectFs"
    :terminal-dock-open="terminalDockOpen"
    :terminal-dock-style="terminalDockStyle"
    :selected-project-id="selectedProjectId"
    :active-project-path="activeProjectPath"
    :before-run-active-file="beforeRunActiveFile"
    @open-terminal-dock="emit('open-terminal-dock')"
    @start-terminal-dock-pull="emit('start-terminal-dock-pull', $event)"
    @start-terminal-dock-resize="emit('start-terminal-dock-resize', $event)"
    @close-terminal-dock="emit('close-terminal-dock')"
  />

  <div v-if="!showSidebar" class="editor-main-standalone-actions">
    <label class="field-inline">
      <span>{{ t("common.language") }}</span>
      <select :value="editorLanguage" @change="emit('update:editorLanguage', $event.target.value)">
        <option v-for="item in languageOptions" :key="item.value" :value="item.value">{{ item.label }}</option>
      </select>
    </label>
    <button class="btn btn-secondary" type="button" @click="emit('download-file')">{{ t("editor.downloadFile") }}</button>
    <button class="btn" type="button" :disabled="saving" @click="emit('save-file')">
      {{ saving ? t("common.saving") : isProjectRoute ? t("editor.saveToProject") : t("editor.saveLocal") }}
    </button>
  </div>
</template>

<script setup>
import { defineEmits, defineProps } from "vue";
import TerminalDock from "./TerminalDock.vue";

defineProps({
  t: {
    type: Function,
    required: true,
  },
  notice: {
    type: String,
    default: "",
  },
  error: {
    type: String,
    default: "",
  },
  canUseProjectFs: {
    type: Boolean,
    default: false,
  },
  terminalDockOpen: {
    type: Boolean,
    default: true,
  },
  terminalDockStyle: {
    type: Object,
    default: () => ({}),
  },
  selectedProjectId: {
    type: [String, Number],
    default: "",
  },
  activeProjectPath: {
    type: String,
    default: "",
  },
  beforeRunActiveFile: {
    type: Function,
    default: null,
  },
  showSidebar: {
    type: Boolean,
    default: false,
  },
  editorLanguage: {
    type: String,
    default: "javascript",
  },
  languageOptions: {
    type: Array,
    default: () => [],
  },
  saving: {
    type: Boolean,
    default: false,
  },
  isProjectRoute: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits([
  "update:editorLanguage",
  "download-file",
  "save-file",
  "open-terminal-dock",
  "start-terminal-dock-pull",
  "start-terminal-dock-resize",
  "close-terminal-dock",
]);
</script>
