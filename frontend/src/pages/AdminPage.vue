<template>
  <div class="page admin-page">
    <section class="card admin-header-card">
      <h1>{{ t("admin.title") }}</h1>
      <p class="muted-text">{{ t("admin.subtitle") }}</p>
    </section>

    <RouterView />
  </div>
</template>

<script setup>
import { useI18n } from "vue-i18n";
const { t } = useI18n();
</script>
