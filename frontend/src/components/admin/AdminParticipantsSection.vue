<template>
  <section class="card admin-section">
    <div class="sidebar-head">
      <div>
        <h2>{{ t("admin.participantsTitle") }}</h2>
        <p class="muted-text">{{ t("admin.participantsLead") }}</p>
      </div>
      <div class="admin-head-actions">
        <button class="btn btn-sm btn-secondary" type="button" @click="toggleForm">
          {{ formOpen ? t("common.cancel") : t("admin.participantCreateAction") }}
        </button>
        <button class="btn btn-sm btn-ghost" type="button" :disabled="loading" @click="loadItems(1)">
          {{ loading ? t("common.loading") : t("common.refresh") }}
        </button>
        <button class="btn btn-sm btn-ghost" type="button" @click="isOpen = !isOpen">
          {{ isOpen ? t("admin.collapseSection") : t("admin.expandSection") }}
        </button>
      </div>
    </div>

    <div v-if="isOpen" class="admin-section-body">
      <form class="admin-filter-row" @submit.prevent="applyFilters">
        <label class="field">
          <span>{{ t("admin.searchLabel") }}</span>
          <input v-model.trim="filters.search" type="text" :placeholder="t('admin.participantSearchPlaceholder')" />
        </label>
        <label class="field">
          <span>{{ t("admin.tableProjectId") }}</span>
          <input v-model.trim="filters.projectId" type="number" min="1" step="1" />
        </label>
        <label class="field">
          <span>{{ t("admin.tableUserId") }}</span>
          <input v-model.trim="filters.userId" type="number" min="1" step="1" />
        </label>
        <button class="btn btn-sm btn-secondary" type="submit">{{ t("admin.applyFilters") }}</button>
      </form>

      <form v-if="formOpen" class="admin-editor-grid" @submit.prevent="submitForm">
        <label class="field">
          <span>{{ t("admin.tableProjectId") }}</span>
          <input v-model.number="form.projectId" type="number" min="1" step="1" required />
        </label>
        <label class="field">
          <span>{{ t("admin.tableUserId") }}</span>
          <input v-model.number="form.userId" type="number" min="1" step="1" required />
        </label>
        <label class="field">
          <span>{{ t("admin.participantJoinedAt") }}</span>
          <input v-model="form.joinedAt" type="datetime-local" />
        </label>
        <div class="admin-editor-actions">
          <button class="btn" type="submit" :disabled="saving">
            {{ saving ? t("common.saving") : (form.mode === "create" ? t("admin.participantCreateSubmit") : t("admin.participantUpdateSubmit")) }}
          </button>
          <button class="btn btn-ghost" type="button" :disabled="saving" @click="closeForm">
            {{ t("common.cancel") }}
          </button>
        </div>
      </form>

      <p v-if="error" class="error-banner">{{ error }}</p>
      <p v-if="notice" class="notice-banner">{{ notice }}</p>

      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>{{ t("admin.tableParticipantId") }}</th>
              <th>{{ t("admin.tableProjectId") }}</th>
              <th>{{ t("admin.tableUser") }}</th>
              <th>{{ t("admin.participantJoinedAt") }}</th>
              <th>{{ t("admin.actionsLabel") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="5">{{ t("admin.loadingParticipants") }}</td>
            </tr>
            <tr v-else-if="items.length === 0">
              <td colspan="5">{{ t("admin.noParticipants") }}</td>
            </tr>
            <tr v-for="item in items" :key="item.participant_id">
              <td>{{ item.participant_id }}</td>
              <td>
                <strong>#{{ item.project_id }}</strong>
                <br />
                <small class="muted-text">{{ item.project?.name || "-" }}</small>
              </td>
              <td>
                <strong>#{{ item.user_id }}</strong>
                <br />
                <small class="muted-text">{{ item.user?.email || item.user?.name || "-" }}</small>
              </td>
              <td>{{ formatDateTime(item.joined_at) }}</td>
              <td>
                <div class="admin-row-actions">
                  <button class="btn btn-sm btn-ghost" type="button" :disabled="saving" @click="startEdit(item)">
                    {{ t("admin.editAction") }}
                  </button>
                  <button class="btn btn-sm btn-danger" type="button" :disabled="saving || deletingId === Number(item.participant_id || 0)" @click="deleteItem(item)">
                    {{ deletingId === Number(item.participant_id || 0) ? t("common.saving") : t("admin.deleteAction") }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="admin-pagination">
        <button class="btn btn-sm btn-ghost" type="button" :disabled="page <= 1 || loading" @click="loadItems(page - 1)">
          {{ t("projects.prevPage") }}
        </button>
        <span class="admin-pagination-status">{{ t("projects.pageStatus", { current: page, total: lastPage }) }}</span>
        <button class="btn btn-sm btn-ghost" type="button" :disabled="page >= lastPage || loading" @click="loadItems(page + 1)">
          {{ t("projects.nextPage") }}
        </button>
      </div>
    </div>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { request } from "../../services/api";

const { t, locale } = useI18n();

const isOpen = ref(false);
const items = ref([]);
const loading = ref(false);
const saving = ref(false);
const deletingId = ref(0);
const error = ref("");
const notice = ref("");
const page = ref(1);
const lastPage = ref(1);
const loaded = ref(false);
const formOpen = ref(false);

const filters = reactive({
  search: "",
  projectId: "",
  userId: "",
});

const form = reactive({
  mode: "create",
  participantId: 0,
  projectId: 1,
  userId: 1,
  joinedAt: "",
});

function readError(errorInput) {
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

function toIsoOrNull(value) {
  if (!value || String(value).trim() === "") {
    return null;
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return null;
  }

  return date.toISOString();
}

function toLocalDateTimeInput(value) {
  if (!value) {
    return "";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return "";
  }

  const pad = (input) => String(input).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function formatDateTime(value) {
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
    hour: "2-digit",
    minute: "2-digit",
  }).format(date);
}

function resetForm() {
  form.mode = "create";
  form.participantId = 0;
  form.projectId = 1;
  form.userId = 1;
  form.joinedAt = "";
}

function toggleForm() {
  isOpen.value = true;
  formOpen.value = !formOpen.value;
  if (formOpen.value) {
    resetForm();
  }
}

function closeForm() {
  formOpen.value = false;
  resetForm();
}

function startEdit(item) {
  isOpen.value = true;
  formOpen.value = true;
  form.mode = "edit";
  form.participantId = Number(item?.participant_id || 0);
  form.projectId = Number(item?.project_id || 1);
  form.userId = Number(item?.user_id || 1);
  form.joinedAt = toLocalDateTimeInput(item?.joined_at);
}

async function loadItems(nextPage = 1) {
  loading.value = true;
  error.value = "";

  try {
    const response = await request({
      method: "GET",
      path: "/admin-project-participants",
      auth: true,
      query: {
        page: nextPage,
        per_page: 12,
        search: filters.search,
        project_id: filters.projectId,
        user_id: filters.userId,
      },
    });

    items.value = Array.isArray(response.data?.data) ? response.data.data : [];
    page.value = Number(response.data?.current_page || nextPage || 1);
    lastPage.value = Math.max(1, Number(response.data?.last_page || 1));
    loaded.value = true;
  } catch (requestError) {
    error.value = readError(requestError);
  } finally {
    loading.value = false;
  }
}

function applyFilters() {
  void loadItems(1);
}

async function submitForm() {
  const projectId = Number(form.projectId || 0);
  const userId = Number(form.userId || 0);
  if (projectId <= 0 || userId <= 0) {
    error.value = t("common.requestFailed");
    return;
  }

  saving.value = true;
  error.value = "";
  notice.value = "";

  const payload = {
    project_id: projectId,
    user_id: userId,
    joined_at: toIsoOrNull(form.joinedAt),
  };

  try {
    if (form.mode === "create") {
      await request({
        method: "POST",
        path: "/admin-project-participants",
        auth: true,
        body: payload,
      });

      notice.value = t("admin.participantCreated");
      closeForm();
      await loadItems(1);
      return;
    }

    await request({
      method: "PATCH",
      path: `/admin-project-participants/${form.participantId}`,
      auth: true,
      body: payload,
    });

    notice.value = t("admin.participantUpdated");
    closeForm();
    await loadItems(page.value);
  } catch (requestError) {
    error.value = readError(requestError);
  } finally {
    saving.value = false;
  }
}

async function deleteItem(item) {
  const participantId = Number(item?.participant_id || 0);
  if (!participantId) {
    return;
  }

  const firstConfirm = window.confirm(t("admin.participantDeleteConfirmStepOne", { id: participantId }));
  if (!firstConfirm) {
    return;
  }

  const secondConfirm = window.confirm(t("admin.participantDeleteConfirmStepTwo"));
  if (!secondConfirm) {
    return;
  }

  deletingId.value = participantId;
  error.value = "";
  notice.value = "";

  try {
    await request({
      method: "DELETE",
      path: `/admin-project-participants/${participantId}`,
      auth: true,
    });

    notice.value = t("admin.participantDeleted");
    await loadItems(page.value);
  } catch (requestError) {
    error.value = readError(requestError);
  } finally {
    deletingId.value = 0;
  }
}

onMounted(() => {
  if (isOpen.value && !loaded.value) {
    void loadItems();
  }
});

watch(isOpen, (nextValue) => {
  if (nextValue && !loaded.value) {
    void loadItems();
  }
});
</script>
