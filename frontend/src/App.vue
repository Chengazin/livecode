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
      </nav>

      <div class="session-meta">
        <RouterLink
          v-if="isAuthenticated"
          to="/profile"
          class="profile-link"
          :title="t('nav.profileTitle', { name: userLabel })"
        >
          <img
            v-if="avatarImageUrl"
            :src="avatarImageUrl"
            :alt="t('profile.avatarAlt')"
            class="profile-avatar-image"
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
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter } from "vue-router";
import { clearSession, getSession, setUser } from "./services/auth";
import { request } from "./services/api";
import { avatarPresetStyles, defaultAvatarPreset } from "./config/avatarPresets";

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const session = ref(getSession());
const loadingProfile = ref(false);
const statusMessage = ref("");

const isAuthenticated = computed(() => Boolean(session.value.accessToken));
const isAdmin = computed(() => Boolean(session.value.accessToken && session.value.user?.is_admin));
const userLabel = computed(() => {
  return session.value.user?.name || session.value.user?.email || t("nav.signedIn");
});
const avatarImageUrl = computed(() => {
  return session.value.user?.avatar_url || "";
});
const avatarPresetStyle = computed(() => {
  const key = session.value.user?.avatar_preset || defaultAvatarPreset;
  return avatarPresetStyles[key] || avatarPresetStyles[defaultAvatarPreset];
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

async function refreshProfile() {
  if (!session.value.accessToken) {
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
}

onMounted(() => {
  window.addEventListener("auth-changed", handleAuthChanged);
  handleAuthChanged();

  if (session.value.accessToken) {
    void refreshProfile();
  }
});

onUnmounted(() => {
  window.removeEventListener("auth-changed", handleAuthChanged);
});
</script>
