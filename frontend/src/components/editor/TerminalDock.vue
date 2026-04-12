<template>
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
      @click="emit('open-terminal-dock')"
      @pointerdown="emit('start-terminal-dock-pull', $event)"
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
        @pointerdown="emit('start-terminal-dock-resize', $event)"
      />
      <div class="editor-main-terminal-controls">
        <button class="btn btn-sm btn-ghost" type="button" @click="emit('close-terminal-dock')">
          {{ t("editor.terminalDockHide") }}
        </button>
      </div>
      <ProjectTerminalPanel
        :project-id="selectedProjectId"
        :active-file-path="activeProjectPath"
        :before-run-active-file="beforeRunActiveFile"
        :embedded="true"
      />
    </div>
  </div>
</template>

<script setup>
import { defineEmits, defineProps } from "vue";
import ProjectTerminalPanel from "../ProjectTerminalPanel.vue";

defineProps({
  t: {
    type: Function,
    required: true,
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
});

const emit = defineEmits([
  "open-terminal-dock",
  "start-terminal-dock-pull",
  "start-terminal-dock-resize",
  "close-terminal-dock",
]);
</script>
