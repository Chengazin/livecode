<template>
  <div class="editor-sidebar-root">
  <section class="sidebar-block">
    <div class="sidebar-head">
      <h2>{{ workspaceTitle }}</h2>
      <div class="sidebar-head-actions">
        <button class="btn btn-sm btn-ghost" type="button" @click="emit('go-to-projects')">
          {{ t("editor.backToProjects") }}
        </button>
        <button
          v-if="canManageProjectSettings"
          class="icon-btn"
          type="button"
          :title="t('editor.projectSettings')"
          :aria-label="t('editor.projectSettings')"
          @click="emit('open-project-settings-modal')"
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
        <select :value="editorLanguage" @change="emit('update:editorLanguage', $event.target.value)">
          <option v-for="item in languageOptions" :key="item.value" :value="item.value">{{ item.label }}</option>
        </select>
      </label>
      <button class="btn" type="button" :disabled="saving" @click="emit('save-file')">
        {{ saving ? t("common.saving") : isProjectRoute ? t("editor.saveToProject") : t("editor.saveLocal") }}
      </button>
    </div>
  </section>

  <FileTree
    :t="t"
    :can-use-project-fs="canUseProjectFs"
    :sync-busy="syncBusy"
    :current-path="currentPath"
    :download-busy="downloadBusy"
    :can-download-selected-folder="canDownloadSelectedFolder"
    :downloading-scope="downloadingScope"
    :tree-loading="treeLoading"
    :flat-tree="flatTree"
    :node-busy="nodeBusy"
    :move-busy="moveBusy"
    :can-move-selected-to-root="canMoveSelectedToRoot"
    :new-node-kind="newNodeKind"
    :new-node-path="newNodePath"
    :new-node-input-ref="newNodeInputRef"
    :is-root-drop-target="isRootDropTarget"
    :selected-tree-type="selectedTreeType"
    :is-project-mode="isProjectMode"
    :active-project-path="activeProjectPath"
    :selected-tree-path="selectedTreePath"
    :dragged-path="draggedPath"
    :drop-target-path="dropTargetPath"
    :is-expanded="isExpanded"
    @sync-project-workspace="emit('sync-project-workspace')"
    @download-file="emit('download-file')"
    @download-project-archive="emit('download-project-archive')"
    @download-selected-folder="emit('download-selected-folder')"
    @start-create-node="emit('start-create-node', $event)"
    @move-selected-to-root="emit('move-selected-to-root')"
    @update:newNodePath="emit('update:newNodePath', $event)"
    @cancel-create-node="emit('cancel-create-node')"
    @submit-create-node="emit('submit-create-node')"
    @select-project-root="emit('select-project-root')"
    @root-drag-over="emit('root-drag-over', $event)"
    @root-drag-leave="emit('root-drag-leave')"
    @root-drop="emit('root-drop')"
    @select-tree-item="(path, type) => emit('select-tree-item', path, type)"
    @tree-row-drag-over="(item, event) => emit('tree-row-drag-over', item, event)"
    @tree-row-drag-leave="emit('tree-row-drag-leave', $event)"
    @tree-row-drop="emit('tree-row-drop', $event)"
    @tree-drag-start="(item, event) => emit('tree-drag-start', item, event)"
    @reset-drag-state="emit('reset-drag-state')"
    @click-tree-item="emit('click-tree-item', $event)"
    @rename-tree-item="emit('rename-tree-item', $event)"
    @remove-tree-item="emit('remove-tree-item', $event)"
  />

  <section v-if="canManageProjectSettings" class="sidebar-block">
    <div class="project-settings-block">
      <div class="sidebar-head">
        <h2>{{ t("editor.collaborators") }}</h2>
      </div>

      <form class="form-grid compact-form" @submit.prevent="emit('add-collaborator-by-id')">
        <label class="field field-row">
          <span>{{ t("editor.collaboratorUserId") }}</span>
          <input
            :value="collaboratorUserId"
            type="number"
            min="1"
            step="1"
            inputmode="numeric"
            :placeholder="t('editor.collaboratorUserIdPlaceholder')"
            @input="emit('update:collaboratorUserId', $event.target.value)"
          />
        </label>
        <button class="btn btn-secondary" type="submit" :disabled="collaboratorBusy || !collaboratorUserId">
          {{ collaboratorBusy ? t("common.saving") : t("editor.addCollaborator") }}
        </button>
      </form>

      <div class="project-invite-actions">
        <button class="btn btn-sm btn-secondary" type="button" :disabled="inviteBusy" @click="emit('create-invite-link')">
          {{ inviteBusy ? t("editor.generatingInvite") : t("editor.generateInviteLink") }}
        </button>
        <button class="btn btn-sm btn-ghost" type="button" :disabled="!inviteLink" @click="emit('copy-invite-link')">
          {{ t("editor.copyInviteLink") }}
        </button>
      </div>
      <p v-if="inviteLink" class="muted-text project-invite-link"><code>{{ inviteLink }}</code></p>

      <p v-if="participantsLoading" class="muted-text">{{ t("editor.loadingCollaborators") }}</p>
      <p v-else-if="projectParticipants.length === 0" class="muted-text">{{ t("editor.noCollaborators") }}</p>

      <div v-else class="collaborator-list">
        <div v-for="participant in projectParticipants" :key="participant.participant_id" class="collaborator-row">
          <div class="collaborator-main">
            <img
              v-if="resolveParticipantAvatarUrl(participant)"
              :src="resolveParticipantAvatarUrl(participant)"
              :alt="t('profile.avatarAlt')"
              class="mini-avatar-image"
            />
            <span
              v-else
              class="mini-avatar-fallback"
              :style="{ background: resolveParticipantAvatarStyle(participant).background }"
            >
              {{ resolveParticipantAvatarStyle(participant).symbol }}
            </span>
            <div class="collaborator-copy">
              <strong>#{{ participant.user_id }}</strong>
              <small v-if="participant.user?.name || participant.user?.email">
                {{ participant.user?.name || participant.user?.email }}
              </small>
            </div>
          </div>
          <button
            class="btn btn-sm btn-ghost"
            type="button"
            :disabled="collaboratorBusy"
            @click="emit('remove-collaborator', participant)"
          >
            {{ t("editor.removeCollaborator") }}
          </button>
        </div>
      </div>
    </div>
  </section>

  <section v-if="isAuthenticated && isProjectRoute" class="sidebar-block">
    <details class="git-accordion">
      <summary>{{ t("editor.gitWork") }}</summary>
      <p class="muted-text">{{ t("editor.gitPanelHint") }}</p>


      <p v-if="!isProjectOwner" class="muted-text">{{ t("editor.ownerGitControlsHint") }}</p>

      <div v-if="isProjectOwner" class="git-flow">
        <details class="git-step" :open="!forgejoAccountConnected">
          <summary>
            <span class="git-step-title">
              <span class="git-step-index">1</span>
              <span>{{ t("editor.gitStepAccount") }}</span>
            </span>
            <small class="git-step-status">
              {{ forgejoAccountConnected ? t("editor.gitStepDone") : t("editor.gitStepPending") }}
            </small>
          </summary>
          <p class="muted-text">{{ t("editor.gitStepAccountHint") }}</p>
          <button class="btn btn-sm btn-secondary" type="button" :disabled="forgejoBusy" @click="emit('start-forgejo-connect')">
            {{ forgejoAccountConnected ? t("editor.reconnectAccount") : t("editor.connectAccount") }}
          </button>
        </details>

        <details v-if="forgejoAccountConnected" class="git-step" :open="!hasForgejoRepo">
          <summary>
            <span class="git-step-title">
              <span class="git-step-index">2</span>
              <span>{{ t("editor.gitStepRepo") }}</span>
            </span>
            <small class="git-step-status">
              {{ hasForgejoRepo ? t("editor.gitStepDone") : t("editor.gitStepPending") }}
            </small>
          </summary>
          <p class="muted-text">{{ t("editor.gitStepRepoHint") }}</p>
          <p v-if="hasForgejoRepo" class="muted-text">
            {{ t("editor.gitRepoConnected", { repo: forgejoRepoFullName || t("editor.repositoryUnknown") }) }}
          </p>

          <form class="form-grid compact-form" @submit.prevent="emit('connect-project-forgejo')">
            <label class="field field-row">
              <span>{{ t("editor.mode") }}</span>
              <select :value="forgejoMode" @change="emit('update:forgejoMode', $event.target.value)">
                <option value="create">{{ t("editor.createRepo") }}</option>
                <option value="existing">{{ t("editor.existingRepo") }}</option>
              </select>
            </label>

            <label v-if="forgejoMode === 'create'" class="field field-row">
              <span>{{ t("editor.repositoryName") }}</span>
              <input :value="forgejoRepoName" type="text" maxlength="255" placeholder="my-project" @input="emit('update:forgejoRepoName', $event.target.value)" />
            </label>

            <label v-if="forgejoMode === 'existing'" class="field">
              <span>{{ t("editor.repositoryUrl") }}</span>
              <input
                :value="forgejoRepoUrl"
                type="text"
                maxlength="2048"
                :placeholder="t('editor.repositoryUrlPlaceholder')"
                @input="emit('update:forgejoRepoUrl', $event.target.value)"
              />
            </label>

            <button class="btn" type="submit" :disabled="forgejoBusy || !selectedProjectId">
              {{ hasForgejoRepo ? t("editor.rebindProjectRepo") : t("editor.connectProjectRepo") }}
            </button>
          </form>
        </details>

        <details v-if="forgejoAccountConnected && hasForgejoRepo" class="git-step" open>
          <summary>
            <span class="git-step-title">
              <span class="git-step-index">3</span>
              <span>{{ t("editor.gitStepActions") }}</span>
            </span>
            <small class="git-step-status">{{ t("editor.gitStepReady") }}</small>
          </summary>
          <p class="muted-text">{{ t("editor.gitStepActionsHint") }}</p>

          <details class="git-step-action">
            <summary>{{ t("editor.pushToForgejo") }}</summary>
            <form class="form-grid compact-form" @submit.prevent="emit('push-to-forgejo')">
              <label class="field field-row">
                <span>{{ t("editor.commitMessage") }}</span>
                <input :value="forgejoMessage" type="text" maxlength="255" :placeholder="t('editor.manualSavePlaceholder')" @input="emit('update:forgejoMessage', $event.target.value)" />
              </label>
              <button class="btn btn-secondary" type="submit" :disabled="forgejoBusy || !selectedProjectId">{{ t("editor.pushToForgejo") }}</button>
            </form>
          </details>

          <details class="git-step-action" :open="canCreatePullRequest">
            <summary>{{ t("editor.createPullRequest") }}</summary>
            <form v-if="canCreatePullRequest" class="form-grid compact-form" @submit.prevent="emit('create-pull-request-in-forgejo')">
              <label class="field field-row">
                <span>{{ t("editor.pullRequestTitle") }}</span>
                <input :value="forgejoPrTitle" type="text" maxlength="255" :placeholder="t('editor.pullRequestTitlePlaceholder')" @input="emit('update:forgejoPrTitle', $event.target.value)" />
              </label>
              <label class="field field-row">
                <span>{{ t("editor.pullRequestCommitMessage") }}</span>
                <input :value="forgejoPrMessage" type="text" maxlength="255" :placeholder="t('editor.manualSavePlaceholder')" @input="emit('update:forgejoPrMessage', $event.target.value)" />
              </label>
              <label class="field">
                <span>{{ t("editor.pullRequestDescription") }}</span>
                <textarea
                  :value="forgejoPrBody"
                  rows="3"
                  maxlength="5000"
                  :placeholder="t('editor.pullRequestDescriptionPlaceholder')"
                  @input="emit('update:forgejoPrBody', $event.target.value)"
                />
              </label>
              <button class="btn btn-secondary" type="submit" :disabled="forgejoBusy || !selectedProjectId">
                {{ forgejoBusy ? t("editor.creatingPullRequest") : t("editor.createPullRequest") }}
              </button>
            </form>
            <p v-else class="muted-text">{{ t("editor.pullRequestUnavailable") }}</p>
          </details>
        </details>
      </div>
    </details>
  </section>
  </div>
</template>

<script setup>
import { defineEmits, defineProps } from "vue";
import FileTree from "./FileTree.vue";
import { avatarPresetStyles, defaultAvatarPreset } from "../../config/avatarPresets";

defineProps({
  t: { type: Function, required: true },
  workspaceTitle: { type: String, default: "" },
  canManageProjectSettings: { type: Boolean, default: false },
  currentPath: { type: String, default: "" },
  editorLanguage: { type: String, default: "javascript" },
  languageOptions: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
  isProjectRoute: { type: Boolean, default: false },
  canUseProjectFs: { type: Boolean, default: false },
  syncBusy: { type: Boolean, default: false },
  downloadBusy: { type: Boolean, default: false },
  canDownloadSelectedFolder: { type: Boolean, default: false },
  downloadingScope: { type: String, default: "" },
  treeLoading: { type: Boolean, default: false },
  flatTree: { type: Array, default: () => [] },
  nodeBusy: { type: Boolean, default: false },
  moveBusy: { type: Boolean, default: false },
  canMoveSelectedToRoot: { type: Boolean, default: false },
  newNodeKind: { type: String, default: "" },
  newNodePath: { type: String, default: "" },
  newNodeInputRef: { type: [Function, Object], default: null },
  isRootDropTarget: { type: Boolean, default: false },
  selectedTreeType: { type: String, default: "" },
  isProjectMode: { type: Boolean, default: false },
  activeProjectPath: { type: String, default: "" },
  selectedTreePath: { type: String, default: "" },
  draggedPath: { type: String, default: "" },
  dropTargetPath: { type: String, default: "" },
  isExpanded: { type: Function, required: true },
  projectParticipants: { type: Array, default: () => [] },
  participantsLoading: { type: Boolean, default: false },
  collaboratorBusy: { type: Boolean, default: false },
  collaboratorUserId: { type: [String, Number], default: "" },
  inviteBusy: { type: Boolean, default: false },
  inviteLink: { type: String, default: "" },
  isAuthenticated: { type: Boolean, default: false },
  isProjectOwner: { type: Boolean, default: false },
  forgejoBusy: { type: Boolean, default: false },
  forgejoMode: { type: String, default: "create" },
  forgejoRepoName: { type: String, default: "" },
  forgejoRepoUrl: { type: String, default: "" },
  forgejoMessage: { type: String, default: "" },
  forgejoPrTitle: { type: String, default: "" },
  forgejoPrBody: { type: String, default: "" },
  forgejoPrMessage: { type: String, default: "" },
  forgejoAccountConnected: { type: Boolean, default: false },
  hasForgejoRepo: { type: Boolean, default: false },
  forgejoRepoFullName: { type: String, default: "" },
  canCreatePullRequest: { type: Boolean, default: false },
  selectedProjectId: { type: [String, Number], default: "" },
});

const emit = defineEmits([
  "go-to-projects",
  "open-project-settings-modal",
  "update:editorLanguage",
  "save-file",
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
  "add-collaborator-by-id",
  "update:collaboratorUserId",
  "create-invite-link",
  "copy-invite-link",
  "remove-collaborator",
  "start-forgejo-connect",
  "connect-project-forgejo",
  "push-to-forgejo",
  "create-pull-request-in-forgejo",
  "update:forgejoMode",
  "update:forgejoRepoName",
  "update:forgejoRepoUrl",
  "update:forgejoMessage",
  "update:forgejoPrTitle",
  "update:forgejoPrBody",
  "update:forgejoPrMessage",
]);

function normalizeAvatarUrl(urlInput) {
  return typeof urlInput === "string" && urlInput.trim() !== "" ? urlInput.trim() : "";
}

function resolveAvatarStyle(presetInput) {
  const key = typeof presetInput === "string" && presetInput.trim() !== ""
    ? presetInput.trim()
    : defaultAvatarPreset;

  return avatarPresetStyles[key] || avatarPresetStyles[defaultAvatarPreset];
}

function resolveParticipantAvatarUrl(participant) {
  const user = participant?.user || {};
  const direct = normalizeAvatarUrl(user.avatar_url);
  if (direct) {
    return direct;
  }

  if (String(user.avatar_type || "") !== "upload") {
    return "";
  }

  const path = normalizeAvatarUrl(user.avatar_path);
  if (!path) {
    return "";
  }

  return `/storage/${path.replace(/^\/+/, "")}`;
}

function resolveParticipantAvatarStyle(participant) {
  return resolveAvatarStyle(participant?.user?.avatar_preset);
}
</script>

<style scoped>
.editor-sidebar-root {
  display: contents;
}
</style>
