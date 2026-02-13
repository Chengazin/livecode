<template>
  <section class="page admin-overview-page">
    <div class="admin-metrics-grid">
      <article class="card admin-metric-card">
        <h3>{{ t("admin.adminsCount") }}</h3>
        <p class="admin-metric-value">{{ adminsTotal }}</p>
      </article>
      <article class="card admin-metric-card">
        <h3>{{ t("admin.currentRole") }}</h3>
        <p class="admin-metric-value">{{ t("admin.roleAdmin") }}</p>
      </article>
      <article class="card admin-metric-card">
        <h3>{{ t("admin.explorerAccess") }}</h3>
        <p class="muted-text">{{ t("admin.explorerAccessHint") }}</p>
        <RouterLink to="/admin/explorer" class="btn btn-secondary btn-sm admin-metric-action">
          {{ t("admin.openExplorer") }}
        </RouterLink>
      </article>
    </div>

    <section class="card">
      <div class="sidebar-head">
        <div>
          <h2>{{ t("admin.adminsTitle") }}</h2>
          <p class="muted-text">{{ t("admin.adminsLead") }}</p>
        </div>
        <button class="btn btn-sm btn-ghost" type="button" :disabled="loading" @click="loadAdmins">
          {{ loading ? t("common.loading") : t("admin.refreshAdmins") }}
        </button>
      </div>

      <p v-if="loading" class="muted-text">{{ t("admin.loadingAdmins") }}</p>
      <p v-if="error" class="error-banner">{{ error }}</p>
      <p v-if="!loading && !error && admins.length === 0" class="muted-text">{{ t("admin.noAdmins") }}</p>

      <div v-if="admins.length > 0" class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>{{ t("admin.tableAdminId") }}</th>
              <th>{{ t("admin.tableUser") }}</th>
              <th>{{ t("admin.tableEmail") }}</th>
              <th>{{ t("admin.tableUserId") }}</th>
              <th>{{ t("admin.tableCreated") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="admin in admins" :key="admin.admin_id">
              <td>{{ admin.admin_id }}</td>
              <td>{{ admin.user?.name || "-" }}</td>
              <td>{{ admin.user?.email || "-" }}</td>
              <td>{{ admin.user_id }}</td>
              <td>{{ formatDate(admin.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { request } from "../services/api";

const { t, locale } = useI18n();

const admins = ref([]);
const adminsTotalRaw = ref(0);
const loading = ref(false);
const error = ref("");

const adminsTotal = computed(() => {
  return adminsTotalRaw.value > 0 ? adminsTotalRaw.value : admins.value.length;
});

function resolveErrorMessage(errorInput) {
  if (errorInput?.data?.errors && typeof errorInput.data.errors === "object") {
    const mergedErrors = Object.values(errorInput.data.errors).flat().join(" ");
    if (mergedErrors) {
      return mergedErrors;
    }
  }

  if (typeof errorInput?.data?.message === "string" && errorInput.data.message.trim() !== "") {
    return errorInput.data.message;
  }

  if (typeof errorInput?.message === "string" && errorInput.message.trim() !== "") {
    return errorInput.message;
  }

  return t("common.requestFailed");
}

function formatDate(value) {
  if (!value) {
    return "-";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return "-";
  }

  return new Intl.DateTimeFormat(locale.value === "ru" ? "ru-RU" : "en-US", {
    year: "numeric",
    month: "short",
    day: "2-digit",
  }).format(date);
}

async function loadAdmins() {
  loading.value = true;
  error.value = "";

  try {
    const response = await request({
      method: "GET",
      path: "/admins",
      query: { per_page: 200 },
      auth: true,
    });

    const payload = response.data || {};
    admins.value = Array.isArray(payload.data) ? payload.data : [];
    adminsTotalRaw.value = Number(payload.total) || admins.value.length;
  } catch (loadError) {
    error.value = resolveErrorMessage(loadError);
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  void loadAdmins();
});
</script>
