<template>
  <div class="page page-grid">
    <section class="card hero-card">
      <p class="eyebrow">{{ t("home.eyebrow") }}</p>
      <h1>{{ t("home.title") }}</h1>
      <p class="lead">
        {{ t("home.lead") }}
      </p>
      <div class="actions">
        <RouterLink to="/projects" class="btn">{{ t("home.openProjects") }}</RouterLink>
        <RouterLink to="/editor" class="btn btn-secondary">{{ t("home.openEditor") }}</RouterLink>
        <RouterLink v-if="!isAuthenticated" to="/register" class="btn btn-secondary">{{ t("home.createAccount") }}</RouterLink>
        <RouterLink v-if="!isAuthenticated" to="/login" class="btn btn-secondary">{{ t("home.login") }}</RouterLink>
        <RouterLink v-if="isAdmin" to="/admin" class="btn btn-ghost">{{ t("home.openAdmin") }}</RouterLink>
      </div>
    </section>

    <section class="card info-card">
      <h2>{{ t("home.includedTitle") }}</h2>
      <ul class="plain-list">
        <li>{{ t("home.itemRouter") }}</li>
        <li>{{ t("home.itemAuth") }}</li>
        <li>{{ t("home.itemGuestEditor") }}</li>
        <li>{{ t("home.itemProjects") }}</li>
        <li>{{ t("home.itemBearer") }}</li>
        <li>{{ t("home.itemCatalog") }}</li>
      </ul>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { getSession } from "../services/auth";

const { t } = useI18n();
const session = ref(getSession());

const isAuthenticated = computed(() => Boolean(session.value.accessToken));
const isAdmin = computed(() => Boolean(session.value.accessToken && session.value.user?.is_admin));

function handleAuthChanged() {
  session.value = getSession();
}

onMounted(() => {
  window.addEventListener("auth-changed", handleAuthChanged);
  handleAuthChanged();
});

onUnmounted(() => {
  window.removeEventListener("auth-changed", handleAuthChanged);
});
</script>
