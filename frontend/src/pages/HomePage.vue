<template>
  <div class="page page-grid">
    <section class="card hero-card">
      <p class="eyebrow">{{ t("home.badge") }}</p>
      <h1>{{ t("home.title") }}</h1>
      <p class="lead">
        {{ t("home.lead") }}
      </p>
      <div class="actions">
        <RouterLink to="/projects" class="btn">{{ t("home.openProjects") }}</RouterLink>
        <RouterLink to="/editor" class="btn btn-secondary">{{ t("home.openEditor") }}</RouterLink>
        <RouterLink v-if="!isAuthenticated" to="/register" class="btn btn-secondary">{{ t("home.createAccount") }}</RouterLink>
        <RouterLink v-if="!isAuthenticated" to="/login" class="btn btn-secondary">{{ t("home.login") }}</RouterLink>
      </div>
    </section>

    <aside class="card info-card">
      <h2>{{ t("home.whatsInsideTitle") }}</h2>
      <ul class="plain-list">
        <li>{{ t("home.featureEditor") }}</li>
        <li>{{ t("home.featureTerminal") }}</li>
        <li>{{ t("home.featureGit") }}</li>
      </ul>
    </aside>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { getSession } from "../services/auth";

const { t } = useI18n();
const session = ref(getSession());

const isAuthenticated = computed(() => Boolean(session.value.accessToken));

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
