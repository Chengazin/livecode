<template>
  <section class="card admin-section">
    <div class="sidebar-head">
      <div>
        <h2>{{ t("admin.snapshotsTitle") }}</h2>
        <p class="muted-text">{{ t("admin.snapshotsLead") }}</p>
      </div>
      <div class="admin-head-actions">
        <button class="btn btn-sm btn-secondary" type="button" @click="toggleForm">
          {{ formOpen ? t("common.cancel") : t("admin.snapshotCreateAction") }}
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
          <input v-model.trim="filters.search" type="text" :placeholder="t('admin.snapshotSearchPlaceholder')" />
        </label>
        <label class="field">
          <span>{{ t("admin.tableProjectId") }}</span>
          <input v-model.trim="filters.projectId" type="number" min="1" step="1" />
        </label>
        <label class="field">
          <span>{{ t("admin.snapshotAuthorLabel") }}</span>
          <input v-model.trim="filters.authorUserId" type="number" min="1" step="1" />
        </label>
        <button class="btn btn-sm btn-secondary" type="submit">{{ t("admin.applyFilters") }}</button>
      </form>

      <form v-if="formOpen" class="admin-editor-grid" @submit.prevent="submitForm">
        <label class="field">
          <span>{{ t("admin.tableProjectId") }}</span>
          <input v-model.number="form.projectId" type="number" min="1" step="1" required />
        </label>
        <label class="field">
          <span>{{ t("admin.snapshotAuthorLabel") }}</span>
          <input v-model.trim="form.authorUserId" type="number" min="1" step="1" />
        </label>
        <label class="field">
          <span>{{ t("admin.snapshotHashLabel") }}</span>
          <input v-model.trim="form.snapshotHash" type="text" maxlength="64" required />
        </label>
        <label class="field">
          <span>{{ t("admin.snapshotPathLabel") }}</span>
          <input v-model.trim="form.snapshotPath" type="text" maxlength="2048" required />
        </label>
        <label class="field">
          <span>{{ t("admin.snapshotSizeLabel") }}</span>
          <input v-model.trim="form.sizeBytes" type="number" min="0" step="1" />
        </label>
        <label class="field">
          <span>{{ t("admin.snapshotMessageLabel") }}</span>
          <input v-model.trim="form.message" type="text" maxlength="1000" />
        </label>
        <div class="admin-editor-actions">
          <button class="btn" type="submit" :disabled="saving">
            {{ saving ? t("common.saving") : (form.mode === "create" ? t("admin.snapshotCreateSubmit") : t("admin.snapshotUpdateSubmit")) }}
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
              <th>{{ t("admin.tableSnapshotId") }}</th>
              <th>{{ t("admin.tableProjectId") }}</th>
              <th>{{ t("admin.snapshotAuthorLabel") }}</th>
              <th>{{ t("admin.snapshotHashLabel") }}</th>
              <th>{{ t("admin.snapshotPathLabel") }}</th>
              <th>{{ t("admin.snapshotSizeLabel") }}</th>
              <th>{{ t("admin.tableCreated") }}</th>
              <th>{{ t("admin.actionsLabel") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="8">{{ t("admin.loadingSnapshots") }}</td>
            </tr>
            <tr v-else-if="items.length === 0">
              <td colspan="8">{{ t("admin.noSnapshots") }}</td>
            </tr>
            <tr v-for="item in items" :key="item.snapshot_id">
              <td>{{ item.snapshot_id }}</td>
              <td>
                <strong>#{{ item.project_id }}</strong>
                <br />
                <small class="muted-text">{{ item.project?.name || "-" }}</small>
              </td>
              <td>
                <strong>#{{ item.author_user_id || "-" }}</strong>
                <br />
                <small class="muted-text">{{ item.author?.email || item.author?.name || "-" }}</small>
              </td>
              <td><code class="admin-code">{{ item.snapshot_hash || "-" }}</code></td>
              <td>
                <span class="admin-path">{{ item.snapshot_path || "-" }}</span>
                <br />
                <small class="muted-text">{{ item.message || "-" }}</small>
              </td>
              <td>{{ item.size_bytes ?? "-" }}</td>
              <td>{{ formatDateTime(item.created_at) }}</td>
              <td>
                <div class="admin-row-actions">
                  <button class="btn btn-sm btn-ghost" type="button" :disabled="saving" @click="startEdit(item)">
                    {{ t("admin.editAction") }}
                  </button>
                  <button class="btn btn-sm btn-danger" type="button" :disabled="saving || deletingId === Number(item.snapshot_id || 0)" @click="deleteItem(item)">
                    {{ deletingId === Number(item.snapshot_id || 0) ? t("common.saving") : t("admin.deleteAction") }}
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
  authorUserId: "",
});

const form = reactive({
  mode: "create",
  snapshotId: 0,
  projectId: 1,
  authorUserId: "",
  snapshotHash: "",
  snapshotPath: "",
  sizeBytes: "",
  message: "",
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
  form.snapshotId = 0;
  form.projectId = 1;
  form.authorUserId = "";
  form.snapshotHash = "";
  form.snapshotPath = "";
  form.sizeBytes = "";
  form.message = "";
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
  form.snapshotId = Number(item?.snapshot_id || 0);
  form.projectId = Number(item?.project_id || 1);
  form.authorUserId = item?.author_user_id ? String(item.author_user_id) : "";
  form.snapshotHash = String(item?.snapshot_hash || "");
  form.snapshotPath = String(item?.snapshot_path || "");
  form.sizeBytes = item?.size_bytes != null ? String(item.size_bytes) : "";
  form.message = String(item?.message || "");
}

async function loadItems(nextPage = 1) {
  loading.value = true;
  error.value = "";

  try {
    const response = await request({
      method: "GET",
      path: "/admin-project-snapshots",
      auth: true,
      query: {
        page: nextPage,
        per_page: 12,
        search: filters.search,
        project_id: filters.projectId,
        author_user_id: filters.authorUserId,
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
  const snapshotHash = form.snapshotHash.trim();
  const snapshotPath = form.snapshotPath.trim();
  if (projectId <= 0 || snapshotHash.length !== 64 || !snapshotPath) {
    error.value = t("admin.snapshotValidationError");
    return;
  }

  saving.value = true;
  error.value = "";
  notice.value = "";

  const authorUserId = form.authorUserId === "" ? null : Number(form.authorUserId || 0);
  const sizeBytes = form.sizeBytes === "" ? null : Number(form.sizeBytes || 0);

  const payload = {
    project_id: projectId,
    author_user_id: authorUserId && authorUserId > 0 ? authorUserId : null,
    snapshot_hash: snapshotHash,
    snapshot_path: snapshotPath,
    size_bytes: sizeBytes != null && sizeBytes >= 0 ? Math.trunc(sizeBytes) : null,
    message: form.message.trim() || null,
  };

  try {
    if (form.mode === "create") {
      await request({
        method: "POST",
        path: "/admin-project-snapshots",
        auth: true,
        body: payload,
      });

      notice.value = t("admin.snapshotCreated");
      closeForm();
      await loadItems(1);
      return;
    }

    await request({
      method: "PATCH",
      path: `/admin-project-snapshots/${form.snapshotId}`,
      auth: true,
      body: payload,
    });

    notice.value = t("admin.snapshotUpdated");
    closeForm();
    await loadItems(page.value);
  } catch (requestError) {
    error.value = readError(requestError);
  } finally {
    saving.value = false;
  }
}

async function deleteItem(item) {
  const snapshotId = Number(item?.snapshot_id || 0);
  if (!snapshotId) {
    return;
  }

  const firstConfirm = window.confirm(t("admin.snapshotDeleteConfirmStepOne", { id: snapshotId }));
  if (!firstConfirm) {
    return;
  }

  const secondConfirm = window.confirm(t("admin.snapshotDeleteConfirmStepTwo"));
  if (!secondConfirm) {
    return;
  }

  deletingId.value = snapshotId;
  error.value = "";
  notice.value = "";

  try {
    await request({
      method: "DELETE",
      path: `/admin-project-snapshots/${snapshotId}`,
      auth: true,
    });

    notice.value = t("admin.snapshotDeleted");
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
