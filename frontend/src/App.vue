<template>
  <div class="shell">
    <header class="topbar">
      <div class="brand">
        <span class="brand-accent">LiveCode</span>
        <span>{{ t("nav.workspace") }}</span>
      </div>

      <nav class="topnav">
        <RouterLink to="/" class="nav-link" :class="{ active: isRouteActive('/') }">
          {{ t("nav.home") }}
        </RouterLink>
        <RouterLink to="/projects" class="nav-link" :class="{ active: isRouteActive('/projects') }">
          {{ t("nav.projects") }}
        </RouterLink>
        <RouterLink to="/editor" class="nav-link" :class="{ active: isRouteActive('/editor') }">
          {{ t("nav.editorSandbox") }}
        </RouterLink>
        <RouterLink v-if="isAdmin" to="/admin" class="nav-link" :class="{ active: isRouteActive('/admin') }">
          {{ t("nav.admin") }}
        </RouterLink>
        <RouterLink
          v-if="isAuthenticated"
          to="/profile"
          class="nav-link"
          :class="{ active: isRouteActive('/profile') }"
        >
          {{ t("nav.profile") }}
        </RouterLink>
        <RouterLink
          v-if="!isAuthenticated"
          to="/register"
          class="nav-link"
          :class="{ active: isRouteActive('/register') }"
        >
          {{ t("nav.register") }}
        </RouterLink>
        <RouterLink
          v-if="!isAuthenticated"
          to="/login"
          class="nav-link"
          :class="{ active: isRouteActive('/login') }"
        >
          {{ t("nav.login") }}
        </RouterLink>
        <a
          v-if="forgejoUrl"
          :href="forgejoUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="nav-link forgejo-link"
        >
          {{ t("nav.versionControl") }}
        </a>
      </nav>

      <div class="session-meta">
        <div v-if="isAuthenticated" ref="notificationsRef" class="notifications-shell">
          <button
            type="button"
            class="notification-toggle"
            :class="{ 'is-open': notificationsOpen }"
            :title="t('nav.notificationsOpen')"
            :aria-label="t('nav.notificationsOpen')"
            :aria-expanded="notificationsOpen ? 'true' : 'false'"
            @click="toggleNotifications"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 4.5a5.5 5.5 0 0 0-5.5 5.5v2.8c0 .8-.28 1.58-.8 2.2l-1.2 1.45h14.9l-1.2-1.45a3.4 3.4 0 0 1-.8-2.2V10A5.5 5.5 0 0 0 12 4.5Z" />
              <path d="M9.5 17.5a2.5 2.5 0 0 0 5 0" />
            </svg>
            <span v-if="unreadNotificationCount > 0" class="notification-badge">
              {{ notificationBadgeText }}
            </span>
          </button>

          <div v-if="notificationsOpen" class="notifications-popover" role="dialog" aria-live="polite">
            <div class="notifications-head">
              <p class="notifications-title">{{ t("nav.notifications") }}</p>
              <button
                v-if="hasUnreadNotifications"
                type="button"
                class="notifications-mark-all"
                :disabled="notificationsLoading"
                @click="markAllAsRead"
              >
                {{ t("nav.notificationsMarkAllRead") }}
              </button>
            </div>

            <p v-if="notificationsLoading" class="notifications-state">
              {{ t("nav.notificationsLoading") }}
            </p>
            <p v-else-if="notifications.length === 0" class="notifications-state">
              {{ t("nav.notificationsEmpty") }}
            </p>
            <ul v-else class="notifications-list">
              <li v-for="notification in notifications" :key="notification.notificationId">
                <button
                  type="button"
                  class="notification-item"
                  :class="{ 'is-unread': !notification.isRead }"
                  @click="handleNotificationSelect(notification)"
                >
                  <span class="notification-item-head">
                    <strong class="notification-item-title">{{ notification.title }}</strong>
                    <span class="notification-item-time">{{ formatNotificationTime(notification.createdAt) }}</span>
                  </span>
                  <span v-if="notification.message" class="notification-item-message">{{ notification.message }}</span>
                </button>
              </li>
            </ul>
          </div>
        </div>

        <RouterLink
          v-if="isAuthenticated"
          to="/profile"
          class="profile-link"
          :title="t('nav.profileTitle', { name: userLabel })"
        >
          <img
            v-if="avatarImageUrl && !avatarImgBroken"
            :src="avatarImageUrl"
            :alt="t('profile.avatarAlt')"
            class="profile-avatar-image"
            @error="avatarImgBroken = true"
          />
          <span v-else class="profile-avatar-fallback" :style="{ background: avatarPresetStyle.background }">
            {{ avatarPresetStyle.symbol }}
          </span>
        </RouterLink>
        <span v-if="loadingProfile" class="sync-label">{{ t("nav.syncing") }}</span>
      </div>
    </header>

    <main class="content">
      <p v-if="statusMessage" class="notice-banner">{{ statusMessage }}</p>
      <RouterView />
    </main>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter } from "vue-router";
import { clearSession, getSession, setUser } from "./services/auth";
import { request } from "./services/api";
import { avatarPresetStyles, defaultAvatarPreset } from "./config/avatarPresets";
import {
  fetchNotifications,
  fetchUnreadNotificationsCount,
  markAllNotificationsRead,
  markNotificationRead,
} from "./services/notifications";

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const session = ref(getSession());
const loadingProfile = ref(false);
const statusMessage = ref("");
const notificationsRef = ref(null);
const notificationsOpen = ref(false);
const notificationsLoading = ref(false);
const notifications = ref([]);
const unreadNotificationCount = ref(0);

let notificationsPollTimer = null;
const avatarImgBroken = ref(false);

function hasAdminRole(user) {
  if (!user || typeof user !== "object") {
    return false;
  }

  const adminFlag = user.is_admin;
  if (adminFlag === true || adminFlag === 1 || adminFlag === "1") {
    return true;
  }

  if (typeof adminFlag === "string" && adminFlag.trim().toLowerCase() === "true") {
    return true;
  }

  return Boolean(user.admin);
}

const isAuthenticated = computed(() => Boolean(session.value.accessToken));
const isAdmin = computed(() => {
  return Boolean(session.value.accessToken) && hasAdminRole(session.value.user);
});
const userLabel = computed(() => {
  return session.value.user?.name || session.value.user?.email || t("nav.signedIn");
});
const avatarImageUrl = computed(() => {
  return session.value.user?.avatar_url || "";
});
const forgejoUrl = computed(() => {
  return String(process.env.VUE_APP_FORGEJO_PUBLIC_URL || "").trim() || null;
});
const avatarPresetStyle = computed(() => {
  const key = session.value.user?.avatar_preset || defaultAvatarPreset;
  return avatarPresetStyles[key] || avatarPresetStyles[defaultAvatarPreset];
});
const hasUnreadNotifications = computed(() => unreadNotificationCount.value > 0);
const notificationBadgeText = computed(() => {
  if (unreadNotificationCount.value > 99) {
    return "99+";
  }

  return String(unreadNotificationCount.value);
});

function isRouteActive(path) {
  if (path === "/admin") {
    return route.path.startsWith("/admin");
  }

  if (path === "/projects") {
    return route.path.startsWith("/projects");
  }

  return route.path === path;
}

function normalizeNotification(entry) {
  if (!entry || typeof entry !== "object") {
    return null;
  }

  const notificationId = Number(entry.notification_id || 0);
  if (!Number.isFinite(notificationId) || notificationId <= 0) {
    return null;
  }

  const data = entry.data && typeof entry.data === "object" ? entry.data : {};

  return {
    notificationId,
    title: String(entry.title || t("nav.notificationDefaultTitle")),
    message: String(entry.message || ""),
    createdAt: String(entry.created_at || ""),
    isRead: Boolean(entry.is_read),
    data,
  };
}

function formatNotificationTime(value) {
  const raw = String(value || "").trim();
  if (!raw) {
    return "";
  }

  const date = new Date(raw);
  if (Number.isNaN(date.getTime())) {
    return "";
  }

  return date.toLocaleString();
}

function resolveNotificationProjectId(notification) {
  const projectId = Number(notification?.data?.project_id || 0);

  if (!Number.isFinite(projectId) || projectId <= 0) {
    return null;
  }

  return projectId;
}

function stopNotificationsPolling() {
  if (notificationsPollTimer !== null) {
    window.clearInterval(notificationsPollTimer);
    notificationsPollTimer = null;
  }
}

function resetNotificationsState() {
  notificationsOpen.value = false;
  notificationsLoading.value = false;
  notifications.value = [];
  unreadNotificationCount.value = 0;
}

async function loadUnreadNotificationsCount() {
  if (!session.value.accessToken) {
    return;
  }

  try {
    const response = await fetchUnreadNotificationsCount();
    const unreadCount = Number(response?.data?.unread_count || 0);
    unreadNotificationCount.value = Number.isFinite(unreadCount) && unreadCount > 0 ? unreadCount : 0;
  } catch (_error) {
    // Ignore notification counter errors to keep topbar responsive.
  }
}

async function loadNotifications() {
  if (!session.value.accessToken || notificationsLoading.value) {
    return;
  }

  notificationsLoading.value = true;

  try {
    const response = await fetchNotifications({ perPage: 10 });
    const rows = Array.isArray(response?.data?.data) ? response.data.data : [];
    notifications.value = rows
      .map((entry) => normalizeNotification(entry))
      .filter((entry) => entry !== null);
  } catch (_error) {
    notifications.value = [];
  } finally {
    notificationsLoading.value = false;
  }
}

function startNotificationsPolling() {
  stopNotificationsPolling();

  if (!session.value.accessToken) {
    return;
  }

  notificationsPollTimer = window.setInterval(() => {
    void loadUnreadNotificationsCount();

    if (notificationsOpen.value) {
      void loadNotifications();
    }
  }, 30000);
}

async function toggleNotifications() {
  if (!isAuthenticated.value) {
    return;
  }

  if (notificationsOpen.value) {
    notificationsOpen.value = false;
    return;
  }

  notificationsOpen.value = true;
  await Promise.all([
    loadUnreadNotificationsCount(),
    loadNotifications(),
  ]);
}

async function markAsRead(notification) {
  if (!notification || notification.isRead) {
    return;
  }

  notification.isRead = true;
  unreadNotificationCount.value = Math.max(0, unreadNotificationCount.value - 1);

  try {
    await markNotificationRead(notification.notificationId);
  } catch (_error) {
    notification.isRead = false;
    unreadNotificationCount.value += 1;
  }
}

async function markAllAsRead() {
  if (!hasUnreadNotifications.value) {
    return;
  }

  try {
    await markAllNotificationsRead();
  } catch (_error) {
    // Ignore and fallback to refresh.
  } finally {
    await Promise.all([
      loadUnreadNotificationsCount(),
      loadNotifications(),
    ]);
  }
}

async function handleNotificationSelect(notification) {
  await markAsRead(notification);
  notificationsOpen.value = false;

  const projectId = resolveNotificationProjectId(notification);
  if (projectId !== null) {
    router.push(`/projects/${projectId}/info`);
    return;
  }

  router.push("/profile");
}

function handleGlobalPointerDown(event) {
  if (!notificationsOpen.value || !notificationsRef.value) {
    return;
  }

  const target = event.target;
  if (target instanceof Node && !notificationsRef.value.contains(target)) {
    notificationsOpen.value = false;
  }
}

function handleGlobalKeydown(event) {
  if (event.key === "Escape") {
    notificationsOpen.value = false;
  }
}

async function refreshProfile() {
  if (!session.value.accessToken || loadingProfile.value) {
    return;
  }

  loadingProfile.value = true;

  try {
    const response = await request({
      method: "GET",
      path: "/me",
      auth: true,
    });

    setUser(response.data, false);
    session.value = getSession();
  } catch (_error) {
    clearSession();
    statusMessage.value = t("nav.sessionExpired");

    if (route.path !== "/login") {
      router.push("/login");
    }
  } finally {
    loadingProfile.value = false;
  }
}

function handleAuthChanged() {
  session.value = getSession();

  if (session.value.accessToken) {
    void refreshProfile();
    void loadUnreadNotificationsCount();
    startNotificationsPolling();
    return;
  }

  stopNotificationsPolling();
  resetNotificationsState();
}

onMounted(() => {
  window.addEventListener("auth-changed", handleAuthChanged);
  document.addEventListener("pointerdown", handleGlobalPointerDown);
  window.addEventListener("keydown", handleGlobalKeydown);
  handleAuthChanged();
});

onUnmounted(() => {
  window.removeEventListener("auth-changed", handleAuthChanged);
  document.removeEventListener("pointerdown", handleGlobalPointerDown);
  window.removeEventListener("keydown", handleGlobalKeydown);
  stopNotificationsPolling();
});

watch(
  () => route.fullPath,
  () => {
    notificationsOpen.value = false;
  },
);
</script>
