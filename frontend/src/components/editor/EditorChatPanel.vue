<template>
  <section
    ref="editorChatPane"
    class="card editor-chat-column"
    :class="{ 'editor-chat-column--collapsed': !chatContentVisible }"
  >
    <div class="editor-chat-head">
      <h2 v-if="chatContentVisible">{{ t("editor.liveSession") }}</h2>
      <div class="editor-chat-head-actions">
        <label v-if="chatContentVisible" class="realtime-toggle">
          <input v-model="liveSyncEnabled" type="checkbox" />
          <span>{{ t("editor.liveSyncEnabled") }}</span>
        </label>
        <button
          class="icon-btn editor-chat-toggle-btn"
          type="button"
          :title="chatContentVisible ? t('editor.chatPanelCollapse') : t('editor.chatPanelExpand')"
          :aria-label="chatContentVisible ? t('editor.chatPanelCollapse') : t('editor.chatPanelExpand')"
          @click="toggleChatContentVisibility"
        >
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path v-if="chatContentVisible" d="M9 6l6 6-6 6" />
            <path v-else d="M15 6l-6 6 6 6" />
          </svg>
        </button>
      </div>
    </div>

    <div v-if="chatContentVisible" class="editor-chat-content">
      <p class="muted-text">{{ t("editor.liveSyncHint") }}</p>

      <div class="realtime-presence">
        <h3>{{ t("editor.onlineCollaborators") }}</h3>
        <p v-if="realtimePeers.length === 0" class="muted-text">{{ t("editor.onlyYouOnline") }}</p>
        <div v-else class="presence-list">
          <div v-for="peer in realtimePeers" :key="peer.user_id" class="presence-row">
            <div class="presence-row-head">
              <img
                v-if="resolvePresenceAvatarUrl(peer)"
                :src="resolvePresenceAvatarUrl(peer)"
                :alt="t('profile.avatarAlt')"
                class="mini-avatar-image"
              />
              <span
                v-else
                class="mini-avatar-fallback"
                :style="{ background: resolvePresenceAvatarStyle(peer).background }"
              >
                {{ resolvePresenceAvatarStyle(peer).symbol }}
              </span>
              <strong>{{ peer.name }}</strong>
            </div>
            <small v-if="peer.path">
              {{ peer.path }}<span v-if="peer.cursor_row !== null"> @ {{ peer.cursor_row + 1 }}:{{ (peer.cursor_column ?? 0) + 1 }}</span>
            </small>
            <small v-else>{{ t("editor.presenceNoFile") }}</small>
          </div>
        </div>
      </div>

      <div class="chat-log">
        <p v-if="chatMessages.length === 0" class="muted-text">{{ t("editor.chatEmpty") }}</p>
        <div
          v-for="message in chatMessages"
          :key="message.id"
          class="chat-row"
          :class="{ self: message.user_id === currentUserId, editing: message.id === chatEditingMessageId }"
        >
          <template v-if="message.user_id === currentUserId">
            <div class="chat-copy">
              <div class="chat-copy-head">
                <strong>{{ message.user_name || `#${message.user_id}` }}</strong>
                <div class="chat-message-tools">
                  <button
                    class="chat-icon-btn"
                    type="button"
                    :title="t('editor.chatEdit')"
                    :aria-label="t('editor.chatEdit')"
                    :disabled="chatSending || chatDeletingMessageId === message.id"
                    @click="startChatMessageEdit(message)"
                  >
                    <svg viewBox="0 0 24 24" class="chat-action-icon" aria-hidden="true">
                      <path d="M3 17.25V21h3.75l11-11-3.75-3.75-11 11zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83L18.88 8.88l1.83-1.84z" />
                    </svg>
                  </button>
                  <button
                    class="chat-icon-btn is-danger"
                    type="button"
                    :title="t('editor.chatDelete')"
                    :aria-label="t('editor.chatDelete')"
                    :disabled="chatSending || chatDeletingMessageId === message.id"
                    @click="deleteChatMessage(message)"
                  >
                    <svg viewBox="0 0 24 24" class="chat-action-icon" aria-hidden="true">
                      <path d="M9 3a1 1 0 0 0-1 1v1H5a1 1 0 1 0 0 2h.77l1.02 12.2A2 2 0 0 0 8.78 21h6.44a2 2 0 0 0 1.99-1.8L18.23 7H19a1 1 0 1 0 0-2h-3V4a1 1 0 0 0-1-1H9zm1 2V5h4V5h-4zm-1.2 2h6.4l-.98 11.72a.5.5 0 0 1-.5.45H10.28a.5.5 0 0 1-.5-.45L8.8 7zm2.2 2a1 1 0 0 1 1 1v6a1 1 0 1 1-2 0v-6a1 1 0 0 1 1-1zm4 0a1 1 0 0 1 1 1v6a1 1 0 1 1-2 0v-6a1 1 0 0 1 1-1z" />
                    </svg>
                  </button>
                </div>
              </div>
              <small>{{ message.message }}</small>
              <small v-if="message.updated_at" class="chat-copy-meta">{{ t("editor.chatEdited") }}</small>
            </div>
            <img
              v-if="resolveMessageAvatarUrl(message)"
              :src="resolveMessageAvatarUrl(message)"
              :alt="t('profile.avatarAlt')"
              class="mini-avatar-image chat-avatar"
            />
            <span
              v-else
              class="mini-avatar-fallback chat-avatar"
              :style="{ background: resolveMessageAvatarStyle(message).background }"
            >
              {{ resolveMessageAvatarStyle(message).symbol }}
            </span>
          </template>
          <template v-else>
            <img
              v-if="resolveMessageAvatarUrl(message)"
              :src="resolveMessageAvatarUrl(message)"
              :alt="t('profile.avatarAlt')"
              class="mini-avatar-image chat-avatar"
            />
            <span
              v-else
              class="mini-avatar-fallback chat-avatar"
              :style="{ background: resolveMessageAvatarStyle(message).background }"
            >
              {{ resolveMessageAvatarStyle(message).symbol }}
            </span>
            <div class="chat-copy">
              <strong>{{ message.user_name || `#${message.user_id}` }}</strong>
              <small>{{ message.message }}</small>
              <small v-if="message.updated_at" class="chat-copy-meta">{{ t("editor.chatEdited") }}</small>
            </div>
          </template>
        </div>
      </div>

      <form class="chat-form" @submit.prevent="sendChatMessage">
        <div class="chat-input-shell">
          <input
            ref="chatInputRef"
            v-model="chatDraft"
            type="text"
            maxlength="1000"
            :placeholder="t('editor.chatPlaceholder')"
          />
          <button
            class="btn btn-sm btn-ghost voice-icon-btn"
            :class="{ 'is-listening': chatVoiceListening }"
            type="button"
            :title="chatVoiceListening ? t('editor.chatVoiceStop') : t('editor.chatVoiceStart')"
            :aria-label="chatVoiceListening ? t('editor.chatVoiceStop') : t('editor.chatVoiceStart')"
            @click="toggleChatVoiceInput"
          >
            <svg viewBox="0 0 24 24" class="voice-icon" aria-hidden="true">
              <path d="M12 14a3 3 0 0 0 3-3V7a3 3 0 0 0-6 0v4a3 3 0 0 0 3 3zm5-3a1 1 0 1 1 2 0 7 7 0 0 1-6 6.93V21h2a1 1 0 1 1 0 2H9a1 1 0 1 1 0-2h2v-3.07A7 7 0 0 1 5 11a1 1 0 1 1 2 0 5 5 0 1 0 10 0z" />
            </svg>
          </button>
          <button
            class="btn btn-sm chat-send-btn"
            type="submit"
            :title="chatEditingMessageId > 0 ? t('editor.chatSave') : t('editor.chatSend')"
            :aria-label="chatEditingMessageId > 0 ? t('editor.chatSave') : t('editor.chatSend')"
            :disabled="chatSending || !chatDraftTrimmed"
          >
            {{ chatSending ? t("common.saving") : (chatEditingMessageId > 0 ? t("editor.chatSave") : t("editor.chatSend")) }}
          </button>
          <button
            v-if="chatEditingMessageId > 0"
            class="btn btn-sm btn-ghost"
            type="button"
            :disabled="chatSending"
            @click="cancelChatMessageEdit"
          >
            {{ t("editor.chatCancelEdit") }}
          </button>
        </div>
      </form>
    </div>
  </section>
</template>

<script setup>
import {
  useEditorCollaborationContext,
  useEditorLayoutContext,
} from "../../composables/useEditorPageContext";

const {
  t,
  editorChatPane,
  chatContentVisible,
  toggleChatContentVisibility,
} = useEditorLayoutContext();

const {
  liveSyncEnabled,
  realtimePeers,
  chatMessages,
  currentUserId,
  chatInputRef,
  chatDraft,
  chatDraftTrimmed,
  chatSending,
  chatEditingMessageId,
  chatDeletingMessageId,
  chatVoiceListening,
  resolvePresenceAvatarUrl,
  resolvePresenceAvatarStyle,
  resolveMessageAvatarUrl,
  resolveMessageAvatarStyle,
  startChatMessageEdit,
  deleteChatMessage,
  sendChatMessage,
  toggleChatVoiceInput,
  cancelChatMessageEdit,
} = useEditorCollaborationContext();
</script>
