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
        <li>
          <strong>Browser Code Editor</strong>
          <p class="home-feature-desc">{{ t("home.featureEditor") }}</p>
        </li>
        <li>
          <strong>Real-time Collaboration</strong>
          <p class="home-feature-desc">Operational Transformation, presence, chat — edit together in real time.</p>
        </li>
        <li>
          <strong>In-browser Terminal</strong>
          <p class="home-feature-desc">{{ t("home.featureTerminal") }}</p>
        </li>
        <li>
          <strong>{{ t("home.featureGit") }}</strong>
          <p class="home-feature-desc">Branch, commit, push, pull requests — all from within the editor.</p>
        </li>
        <li>
          <strong>Task Kanban</strong>
          <p class="home-feature-desc">Manage project tasks with a built-in Kanban workflow board.</p>
        </li>
        <li>
          <strong>Code Annotations</strong>
          <p class="home-feature-desc">Inline code comments for review and collaboration.</p>
        </li>
      </ul>
      <div class="home-tech-stack">
        <span class="home-tech-chip">Laravel 12</span>
        <span class="home-tech-chip">Vue 3</span>
        <span class="home-tech-chip">PostgreSQL</span>
        <span class="home-tech-chip">Redis</span>
        <span class="home-tech-chip">WebSocket</span>
        <span class="home-tech-chip">Docker</span>
        <span class="home-tech-chip">Forgejo</span>
      </div>
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
