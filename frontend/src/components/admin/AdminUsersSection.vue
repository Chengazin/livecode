<template>
  <section class="card admin-section">
    <div class="sidebar-head">
      <div>
        <h2>{{ t("admin.usersTitle") }}</h2>
        <p class="muted-text">{{ t("admin.usersLead") }}</p>
      </div>
      <div class="admin-head-actions">
        <button class="btn btn-sm btn-secondary" type="button" @click="toggleUserForm">
          {{ userFormOpen ? t("common.cancel") : t("admin.userCreateAction") }}
        </button>
        <button class="btn btn-sm btn-ghost" type="button" :disabled="usersLoading" @click="loadUsers(1)">
          {{ usersLoading ? t("common.loading") : t("common.refresh") }}
        </button>
        <button class="btn btn-sm btn-ghost" type="button" @click="isOpen = !isOpen">
          {{ isOpen ? t("admin.collapseSection") : t("admin.expandSection") }}
        </button>
      </div>
    </div>

    <div v-if="isOpen" class="admin-section-body">
      <form class="admin-filter-row" @submit.prevent="applyUserFilters">
        <label class="field">
          <span>{{ t("admin.searchLabel") }}</span>
          <input v-model.trim="userFilters.search" type="text" :placeholder="t('admin.userSearchPlaceholder')" />
        </label>
        <label class="field">
          <span>{{ t("admin.userStatusLabel") }}</span>
          <select v-model="userFilters.status">
            <option value="">{{ t("admin.filterAll") }}</option>
            <option value="active">{{ t("admin.userStatusActive") }}</option>
            <option value="blocked">{{ t("admin.userStatusBlocked") }}</option>
          </select>
        </label>
        <label class="field">
          <span>{{ t("admin.userRoleLabel") }}</span>
          <select v-model="userFilters.isAdmin">
            <option value="">{{ t("admin.filterAll") }}</option>
            <option value="true">{{ t("admin.roleAdmin") }}</option>
            <option value="false">{{ t("admin.roleUser") }}</option>
          </select>
        </label>
        <button class="btn btn-sm btn-secondary" type="submit">{{ t("admin.applyFilters") }}</button>
      </form>

      <form v-if="userFormOpen" class="admin-editor-grid" @submit.prevent="submitUserForm">
        <label class="field">
          <span>{{ t("admin.tableUser") }}</span>
          <input v-model.trim="userForm.name" type="text" maxlength="255" required />
        </label>
        <label class="field">
          <span>{{ t("admin.tableEmail") }}</span>
          <input v-model.trim="userForm.email" type="email" maxlength="255" required />
        </label>
        <label class="field">
          <span>{{ t("admin.userPasswordLabel") }}</span>
          <input
            v-model="userForm.password"
            type="password"
            maxlength="255"
            :required="userForm.mode === 'create'"
            :placeholder="userForm.mode === 'edit' ? t('admin.userPasswordOptional') : ''"
          />
        </label>
        <label class="field">
          <span>{{ t("admin.userStatusLabel") }}</span>
          <select v-model="userForm.status">
            <option value="active">{{ t("admin.userStatusActive") }}</option>
            <option value="blocked" :disabled="userForm.mode === 'edit' && userForm.isAdmin">{{ t("admin.userStatusBlocked") }}</option>
          </select>
        </label>
        <label class="field">
          <span>{{ t("common.language") }}</span>
          <select v-model="userForm.language">
            <option value="rus">{{ t("common.languages.rus") }}</option>
            <option value="eng">{{ t("common.languages.eng") }}</option>
          </select>
        </label>
        <div class="admin-editor-actions">
          <button class="btn" type="submit" :disabled="usersSaving">
            {{ usersSaving ? t("common.saving") : (userForm.mode === "create" ? t("admin.userCreateSubmit") : t("admin.userUpdateSubmit")) }}
          </button>
          <button class="btn btn-ghost" type="button" :disabled="usersSaving" @click="closeUserForm">
            {{ t("common.cancel") }}
          </button>
        </div>
      </form>

      <p v-if="usersError" class="error-banner">{{ usersError }}</p>
      <p v-if="usersNotice" class="notice-banner">{{ usersNotice }}</p>

      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>{{ t("admin.tableUserId") }}</th>
              <th>{{ t("admin.tableUser") }}</th>
              <th>{{ t("admin.tableEmail") }}</th>
              <th>{{ t("admin.userStatusLabel") }}</th>
              <th>{{ t("admin.userRoleLabel") }}</th>
              <th>{{ t("admin.userLanguageLabel") }}</th>
              <th>{{ t("admin.actionsLabel") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="usersLoading">
              <td colspan="7">{{ t("admin.loadingUsers") }}</td>
            </tr>
            <tr v-else-if="users.length === 0">
              <td colspan="7">{{ t("admin.noUsers") }}</td>
            </tr>
            <tr v-for="user in users" :key="user.user_id">
              <td>{{ user.user_id }}</td>
              <td>{{ user.name || "-" }}</td>
              <td>{{ user.email || "-" }}</td>
              <td>{{ user.status || "-" }}</td>
              <td>
                <span class="admin-role-chip" :class="{ 'is-admin': isAdminUser(user) }">
                  {{ isAdminUser(user) ? t("admin.roleAdmin") : t("admin.roleUser") }}
                </span>
              </td>
              <td>{{ user.language || "-" }}</td>
              <td>
                <div class="admin-row-actions">
                  <button class="btn btn-sm btn-ghost" type="button" :disabled="usersSaving" @click="startEditUser(user)">
                    {{ t("admin.editAction") }}
                  </button>
                  <button
                    class="btn btn-sm btn-secondary"
                    type="button"
                    :disabled="usersSaving || usersDeletingId === Number(user.user_id || 0) || isAdminUser(user)"
                    @click="toggleUserStatus(user)"
                  >
                    {{ user.status === "active" ? t("admin.banAction") : t("admin.unbanAction") }}
                  </button>
                  <button
                    class="btn btn-sm btn-danger"
                    type="button"
                    :disabled="usersSaving || usersDeletingId === Number(user.user_id || 0) || isAdminUser(user)"
                    @click="deleteUser(user)"
                  >
                    {{ usersDeletingId === Number(user.user_id || 0) ? t("common.saving") : t("admin.deleteAction") }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="admin-pagination">
        <button class="btn btn-sm btn-ghost" type="button" :disabled="usersPage <= 1 || usersLoading" @click="loadUsers(usersPage - 1)">
          {{ t("projects.prevPage") }}
        </button>
        <span class="admin-pagination-status">{{ t("projects.pageStatus", { current: usersPage, total: usersLastPage }) }}</span>
        <button class="btn btn-sm btn-ghost" type="button" :disabled="usersPage >= usersLastPage || usersLoading" @click="loadUsers(usersPage + 1)">
          {{ t("projects.nextPage") }}
        </button>
      </div>
    </div>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { request } from "../../services/api";

const { t } = useI18n();

const isOpen = ref(true);
const users = ref([]);
const usersLoading = ref(false);
const usersSaving = ref(false);
const usersDeletingId = ref(0);
const usersError = ref("");
const usersNotice = ref("");
const usersPage = ref(1);
const usersLastPage = ref(1);
const userFormOpen = ref(false);

const userFilters = reactive({
  search: "",
  status: "",
  isAdmin: "",
});

const userForm = reactive({
  mode: "create",
  userId: 0,
  name: "",
  email: "",
  password: "",
  status: "active",
  language: "rus",
  isAdmin: false,
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

function normalizeUser(rawUser) {
  return {
    ...rawUser,
    is_admin: Boolean(rawUser?.is_admin === true || rawUser?.is_admin === 1 || rawUser?.is_admin === "1" || rawUser?.admin),
  };
}

function isAdminUser(user) {
  return Boolean(user?.is_admin);
}

function resetUserForm() {
  userForm.mode = "create";
  userForm.userId = 0;
  userForm.name = "";
  userForm.email = "";
  userForm.password = "";
  userForm.status = "active";
  userForm.language = "rus";
  userForm.isAdmin = false;
}

function toggleUserForm() {
  isOpen.value = true;
  userFormOpen.value = !userFormOpen.value;
  if (userFormOpen.value) {
    resetUserForm();
  }
}

function closeUserForm() {
  userFormOpen.value = false;
  resetUserForm();
}

function startEditUser(user) {
  isOpen.value = true;
  userFormOpen.value = true;
  userForm.mode = "edit";
  userForm.userId = Number(user?.user_id || 0);
  userForm.name = String(user?.name || "");
  userForm.email = String(user?.email || "");
  userForm.password = "";
  userForm.status = String(user?.status || "active");
  userForm.language = String(user?.language || "rus");
  userForm.isAdmin = isAdminUser(user);
}

function replaceUser(updated) {
  const updatedId = Number(updated?.user_id || 0);
  if (!updatedId) {
    return;
  }

  const normalized = normalizeUser(updated);
  users.value = users.value.map((user) => (Number(user?.user_id || 0) === updatedId ? normalized : user));
}

async function loadUsers(page = 1) {
  usersLoading.value = true;
  usersError.value = "";

  try {
    const response = await request({
      method: "GET",
      path: "/users",
      auth: true,
      query: {
        page,
        per_page: 12,
        search: userFilters.search,
        status: userFilters.status,
        is_admin: userFilters.isAdmin,
      },
    });

    users.value = Array.isArray(response.data?.data) ? response.data.data.map(normalizeUser) : [];
    usersPage.value = Number(response.data?.current_page || page || 1);
    usersLastPage.value = Math.max(1, Number(response.data?.last_page || 1));
  } catch (requestError) {
    usersError.value = readError(requestError);
  } finally {
    usersLoading.value = false;
  }
}

function applyUserFilters() {
  void loadUsers(1);
}

async function submitUserForm() {
  const name = userForm.name.trim();
  const email = userForm.email.trim();
  if (!name || !email) {
    usersError.value = t("common.requestFailed");
    return;
  }

  usersSaving.value = true;
  usersError.value = "";
  usersNotice.value = "";

  try {
    if (userForm.mode === "create") {
      if (!userForm.password || userForm.password.length < 8) {
        throw new Error(t("admin.userPasswordMin"));
      }

      await request({
        method: "POST",
        path: "/users",
        auth: true,
        body: {
          name,
          email,
          password: userForm.password,
          status: userForm.status,
          language: userForm.language,
        },
      });

      usersNotice.value = t("admin.userCreated");
      closeUserForm();
      await loadUsers(1);
      return;
    }

    const payload = {
      name,
      email,
      language: userForm.language,
    };

    if (!userForm.isAdmin) {
      payload.status = userForm.status;
    }

    if (userForm.password.trim() !== "") {
      payload.password = userForm.password;
    }

    const response = await request({
      method: "PATCH",
      path: `/users/${userForm.userId}`,
      auth: true,
      body: payload,
    });

    replaceUser(response.data || payload);
    usersNotice.value = t("admin.userUpdated");
    closeUserForm();
    await loadUsers(usersPage.value);
  } catch (requestError) {
    usersError.value = readError(requestError);
  } finally {
    usersSaving.value = false;
  }
}

async function toggleUserStatus(user) {
  const userId = Number(user?.user_id || 0);
  if (!userId || isAdminUser(user)) {
    return;
  }

  usersSaving.value = true;
  usersError.value = "";
  usersNotice.value = "";

  try {
    const nextStatus = String(user.status || "") === "active" ? "blocked" : "active";
    const response = await request({
      method: "PATCH",
      path: `/users/${userId}`,
      auth: true,
      body: {
        status: nextStatus,
      },
    });

    replaceUser(response.data || { user_id: userId, status: nextStatus });
    usersNotice.value = nextStatus === "blocked" ? t("admin.userBanned") : t("admin.userUnbanned");
    await loadUsers(usersPage.value);
  } catch (requestError) {
    usersError.value = readError(requestError);
  } finally {
    usersSaving.value = false;
  }
}

async function deleteUser(user) {
  const userId = Number(user?.user_id || 0);
  if (!userId || isAdminUser(user)) {
    return;
  }

  const firstConfirm = window.confirm(t("admin.userDeleteConfirmStepOne", { id: userId }));
  if (!firstConfirm) {
    return;
  }

  const secondConfirm = window.confirm(t("admin.userDeleteConfirmStepTwo", { email: user.email || "-" }));
  if (!secondConfirm) {
    return;
  }

  usersDeletingId.value = userId;
  usersError.value = "";
  usersNotice.value = "";

  try {
    await request({
      method: "DELETE",
      path: `/users/${userId}`,
      auth: true,
    });

    usersNotice.value = t("admin.userDeleted");
    await loadUsers(usersPage.value);
  } catch (requestError) {
    usersError.value = readError(requestError);
  } finally {
    usersDeletingId.value = 0;
  }
}

onMounted(() => {
  void loadUsers();
});
</script>
