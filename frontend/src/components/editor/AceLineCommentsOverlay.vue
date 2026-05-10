<template>
  <div ref="aceCommentOverlayHost" class="ace-comment-overlay-host">
    <div ref="editorHost" class="ace-editor-host" />

    <button
      v-if="aceLineCommentTrigger.visible"
      ref="aceLineCommentTriggerRef"
      class="ace-line-comment-trigger"
      :class="{ 'has-comments': aceLineCommentTrigger.has_comments }"
      type="button"
      :style="{
        top: `${aceLineCommentTrigger.top}px`,
        left: `${aceLineCommentTrigger.left}px`,
      }"
      :title="t('editor.openLineComments')"
      :aria-label="t('editor.openLineComments')"
      @click.stop="openAceLineCommentsFromTrigger"
    >
      <span v-if="aceLineCommentTrigger.has_comments">{{ aceLineCommentTrigger.count }}</span>
      <span v-else>+</span>
    </button>

    <div
      v-if="aceLineCommentPopover.open"
      ref="aceLineCommentPopoverRef"
      class="ace-line-comment-popover"
      :class="{ closing: aceLineCommentPopover.isClosing }"
      :style="{
        top: `${aceLineCommentPopover.top}px`,
        left: `${aceLineCommentPopover.left}px`,
      }"
      @animationend="handleCommentPopoverAnimationEnd"
    >
      <div class="ace-line-comment-popover-head">
        <strong>{{ t("editor.commentLine", { line: aceLineCommentPopover.line_number }) }}</strong>
        <button
          class="btn btn-sm btn-ghost"
          type="button"
          :title="t('editor.closeLineComments')"
          :aria-label="t('editor.closeLineComments')"
          @click.stop="startCloseAceLineComments"
        >
          x
        </button>
      </div>

      <div class="ace-line-comment-popover-list">
        <p v-if="aceLineComments.length === 0" class="muted-text">{{ t("editor.lineCommentsEmpty") }}</p>
        <div v-for="comment in aceLineComments" :key="`ace-${comment.comment_id}`" class="ace-line-comment-item">
          <div class="ace-line-comment-item-head">
            <div class="ace-line-comment-author">
              <img
                v-if="comment.author_avatar_url"
                :src="comment.author_avatar_url"
                :alt="t('profile.avatarAlt')"
                class="mini-avatar-image"
              />
              <span
                v-else
                class="mini-avatar-fallback"
                :style="{ background: resolveAvatarStyle(comment.author_avatar_preset).background }"
              >
                {{ resolveAvatarStyle(comment.author_avatar_preset).symbol }}
              </span>
              <strong>{{ comment.author_name }}</strong>
            </div>
            <button
              class="btn btn-sm btn-ghost"
              type="button"
              :disabled="codeCommentDeletingId === comment.comment_id"
              @click="deleteCodeComment(comment)"
            >
              {{ t("editor.removeComment") }}
            </button>
          </div>
          <small>{{ comment.body }}</small>
        </div>
      </div>

      <form class="ace-line-comment-form" @submit.prevent="submitAceLineComment">
        <div class="ace-line-comment-form-row">
          <input
            ref="aceLineCommentInputRef"
            v-model.trim="aceLineCommentDraft"
            type="text"
            maxlength="3000"
            :placeholder="t('editor.commentPlaceholder')"
          />
          <button
            class="btn btn-sm btn-ghost voice-icon-btn"
            :class="{ 'is-listening': aceLineCommentVoiceListening }"
            type="button"
            :title="aceLineCommentVoiceListening ? t('editor.chatVoiceStop') : t('editor.chatVoiceStart')"
            :aria-label="aceLineCommentVoiceListening ? t('editor.chatVoiceStop') : t('editor.chatVoiceStart')"
            :disabled="aceLineCommentSubmitting"
            @click="toggleAceLineCommentVoiceInput"
          >
            <svg viewBox="0 0 24 24" class="voice-icon" aria-hidden="true">
              <path d="M12 14a3 3 0 0 0 3-3V7a3 3 0 0 0-6 0v4a3 3 0 0 0 3 3zm5-3a1 1 0 1 1 2 0 7 7 0 0 1-6 6.93V21h2a1 1 0 1 1 0 2H9a1 1 0 1 1 0-2h2v-3.07A7 7 0 0 1 5 11a1 1 0 1 1 2 0 5 5 0 1 0 10 0z" />
            </svg>
          </button>
          <button
            class="btn btn-sm"
            type="submit"
            :disabled="aceLineCommentSubmitting || !aceLineCommentDraft"
          >
            {{ aceLineCommentSubmitting ? t("editor.addingComment") : t("editor.addComment") }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import {
  useEditorCollaborationContext,
  useEditorLayoutContext,
} from "../../composables/useEditorPageContext";

const {
  t,
  editorHost,
} = useEditorLayoutContext();

const {
  aceCommentOverlayHost,
  aceLineCommentTriggerRef,
  aceLineCommentPopoverRef,
  aceLineCommentInputRef,
  aceLineCommentTrigger,
  aceLineCommentPopover,
  aceLineComments,
  codeCommentDeletingId,
  aceLineCommentDraft,
  aceLineCommentSubmitting,
  aceLineCommentVoiceListening,
  resolveAvatarStyle,
  openAceLineCommentsFromTrigger,
  startCloseAceLineComments,
  handleCommentPopoverAnimationEnd,
  deleteCodeComment,
  submitAceLineComment,
  toggleAceLineCommentVoiceInput,
} = useEditorCollaborationContext();
</script>
