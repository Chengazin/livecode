<template>
  <section class="card admin-section">
    <div class="sidebar-head">
      <div>
        <h2>{{ t("admin.invitationsTitle") }}</h2>
        <p class="muted-text">{{ t("admin.invitationsLead") }}</p>
      </div>
      <div class="admin-head-actions">
        <button class="btn btn-sm btn-secondary" type="button" @click="toggleForm">
          {{ formOpen ? t("common.cancel") : t("admin.invitationCreateAction") }}
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
          <input v-model.trim="filters.search" type="text" :placeholder="t('admin.invitationSearchPlaceholder')" />
        </label>
        <label class="field">
          <span>{{ t("admin.tableProjectId") }}</span>
          <input v-model.trim="filters.projectId" type="number" min="1" step="1" />
        </label>
        <label class="field">
          <span>{{ t("admin.inviterUserLabel") }}</span>
          <input v-model.trim="filters.inviterUserId" type="number" min="1" step="1" />
        </label>
        <label class="field">
          <span>{{ t("admin.invitationStateLabel") }}</span>
          <select v-model="filters.state">
            <option value="">{{ t("admin.filterAll") }}</option>
            <option value="active">{{ t("admin.invitationStateActive") }}</option>
            <option value="expired">{{ t("admin.invitationStateExpired") }}</option>
            <option value="no_expiry">{{ t("admin.invitationStateNoExpiry") }}</option>
          </select>
        </label>
        <button class="btn btn-sm btn-secondary" type="submit">{{ t("admin.applyFilters") }}</button>
      </form>

      <form v-if="formOpen" class="admin-editor-grid" @submit.prevent="submitForm">
        <label class="field">
          <span>{{ t("admin.tableProjectId") }}</span>
          <input v-model.number="form.projectId" type="number" min="1" step="1" required />
        </label>
        <label class="field">
          <span>{{ t("admin.inviterUserLabel") }}</span>
          <input v-model.number="form.inviterUserId" type="number" min="1" step="1" required />
        </label>
        <label class="field">
          <span>{{ t("admin.invitationTokenLabel") }}</span>
          <input v-model.trim="form.inviteToken" type="text" maxlength="128" :placeholder="t('admin.invitationTokenOptional')" />
        </label>
        <label class="field">
          <span>{{ t("admin.invitationExpiresAt") }}</span>
          <input v-model="form.expiresAt" type="datetime-local" />
        </label>
        <div class="admin-editor-actions">
          <button class="btn" type="submit" :disabled="saving">
            {{ saving ? t("common.saving") : (form.mode === "create" ? t("admin.invitationCreateSubmit") : t("admin.invitationUpdateSubmit")) }}
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
              <th>{{ t("admin.tableInvitationId") }}</th>
              <th>{{ t("admin.tableProjectId") }}</th>
              <th>{{ t("admin.inviterUserLabel") }}</th>
              <th>{{ t("admin.invitationTokenLabel") }}</th>
              <th>{{ t("admin.invitationExpiresAt") }}</th>
              <th>{{ t("admin.tableCreated") }}</th>
              <th>{{ t("admin.actionsLabel") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="7">{{ t("admin.loadingInvitations") }}</td>
            </tr>
            <tr v-else-if="items.length === 0">
              <td colspan="7">{{ t("admin.noInvitations") }}</td>
            </tr>
            <tr v-for="item in items" :key="item.invitation_id">
              <td>{{ item.invitation_id }}</td>
              <td>
                <strong>#{{ item.project_id }}</strong>
                <br />
                <small class="muted-text">{{ item.project?.name || "-" }}</small>
              </td>
              <td>
                <strong>#{{ item.inviter_user_id }}</strong>
                <br />
                <small class="muted-text">{{ item.inviter?.email || item.inviter?.name || "-" }}</small>
              </td>
              <td><code class="admin-code">{{ item.invite_token || "-" }}</code></td>
              <td>{{ formatDateTime(item.expires_at) }}</td>
              <td>{{ formatDateTime(item.created_at) }}</td>
              <td>
                <div class="admin-row-actions">
                  <button class="btn btn-sm btn-ghost" type="button" :disabled="saving" @click="startEdit(item)">
                    {{ t("admin.editAction") }}
                  </button>
                  <button class="btn btn-sm btn-danger" type="button" :disabled="saving || deletingId === Number(item.invitation_id || 0)" @click="deleteItem(item)">
                    {{ deletingId === Number(item.invitation_id || 0) ? t("common.saving") : t("admin.deleteAction") }}
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
  inviterUserId: "",
  state: "",
});

const form = reactive({
  mode: "create",
  invitationId: 0,
  projectId: 1,
  inviterUserId: 1,
  inviteToken: "",
  expiresAt: "",
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
  form.invitationId = 0;
  form.projectId = 1;
  form.inviterUserId = 1;
  form.inviteToken = "";
  form.expiresAt = "";
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
  form.invitationId = Number(item?.invitation_id || 0);
  form.projectId = Number(item?.project_id || 1);
  form.inviterUserId = Number(item?.inviter_user_id || 1);
  form.inviteToken = String(item?.invite_token || "");
  form.expiresAt = toLocalDateTimeInput(item?.expires_at);
}

async function loadItems(nextPage = 1) {
  loading.value = true;
  error.value = "";

  try {
    const response = await request({
      method: "GET",
      path: "/admin-project-invitations",
      auth: true,
      query: {
        page: nextPage,
        per_page: 12,
        search: filters.search,
        project_id: filters.projectId,
        inviter_user_id: filters.inviterUserId,
        state: filters.state,
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
  const inviterUserId = Number(form.inviterUserId || 0);
  if (projectId <= 0 || inviterUserId <= 0) {
    error.value = t("common.requestFailed");
    return;
  }

  saving.value = true;
  error.value = "";
  notice.value = "";

  const payload = {
    project_id: projectId,
    inviter_user_id: inviterUserId,
    invite_token: form.inviteToken.trim() || undefined,
    expires_at: toIsoOrNull(form.expiresAt),
  };

  try {
    if (form.mode === "create") {
      await request({
        method: "POST",
        path: "/admin-project-invitations",
        auth: true,
        body: payload,
      });

      notice.value = t("admin.invitationCreated");
      closeForm();
      await loadItems(1);
      return;
    }

    await request({
      method: "PATCH",
      path: `/admin-project-invitations/${form.invitationId}`,
      auth: true,
      body: payload,
    });

    notice.value = t("admin.invitationUpdated");
    closeForm();
    await loadItems(page.value);
  } catch (requestError) {
    error.value = readError(requestError);
  } finally {
    saving.value = false;
  }
}

async function deleteItem(item) {
  const invitationId = Number(item?.invitation_id || 0);
  if (!invitationId) {
    return;
  }

  const firstConfirm = window.confirm(t("admin.invitationDeleteConfirmStepOne", { id: invitationId }));
  if (!firstConfirm) {
    return;
  }

  const secondConfirm = window.confirm(t("admin.invitationDeleteConfirmStepTwo"));
  if (!secondConfirm) {
    return;
  }

  deletingId.value = invitationId;
  error.value = "";
  notice.value = "";

  try {
    await request({
      method: "DELETE",
      path: `/admin-project-invitations/${invitationId}`,
      auth: true,
    });

    notice.value = t("admin.invitationDeleted");
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
