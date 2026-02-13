<template>
  <div class="page admin-page">
    <section class="card admin-header-card">
      <h1>{{ t("admin.title") }}</h1>
      <p class="muted-text">{{ t("admin.subtitle") }}</p>
    </section>

    <section class="card admin-nav-card">
      <nav class="admin-subnav">
        <RouterLink to="/admin" class="admin-subnav-link" :class="{ active: isTabActive('/admin') }">
          {{ t("admin.overviewTab") }}
        </RouterLink>
        <RouterLink
          to="/admin/explorer"
          class="admin-subnav-link"
          :class="{ active: isTabActive('/admin/explorer') }"
        >
          {{ t("admin.explorerTab") }}
        </RouterLink>
      </nav>
    </section>

    <RouterView />
  </div>
</template>

<script setup>
import { useI18n } from "vue-i18n";
import { useRoute } from "vue-router";

const route = useRoute();
const { t } = useI18n();

function isTabActive(path) {
  if (path === "/admin") {
    return route.path === "/admin";
  }

  return route.path.startsWith(path);
}
</script>
