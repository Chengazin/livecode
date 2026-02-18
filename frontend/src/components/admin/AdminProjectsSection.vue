<template>
  <section class="card admin-section">
    <div class="sidebar-head">
      <div>
        <h2>{{ t("admin.projectsTitle") }}</h2>
        <p class="muted-text">{{ t("admin.projectsLead") }}</p>
      </div>
      <div class="admin-head-actions">
        <button class="btn btn-sm btn-secondary" type="button" @click="toggleProjectForm">
          {{ projectFormOpen ? t("common.cancel") : t("admin.projectCreateAction") }}
        </button>
        <button class="btn btn-sm btn-ghost" type="button" :disabled="projectsLoading" @click="loadProjects(1)">
          {{ projectsLoading ? t("common.loading") : t("common.refresh") }}
        </button>
        <button class="btn btn-sm btn-ghost" type="button" @click="isOpen = !isOpen">
          {{ isOpen ? t("admin.collapseSection") : t("admin.expandSection") }}
        </button>
      </div>
    </div>

    <div v-if="isOpen" class="admin-section-body">
      <form class="admin-filter-row" @submit.prevent="applyProjectFilters">
        <label class="field">
          <span>{{ t("admin.searchLabel") }}</span>
          <input v-model.trim="projectFilters.search" type="text" :placeholder="t('admin.projectSearchPlaceholder')" />
        </label>
        <label class="field">
          <span>{{ t("admin.projectOwnerFilterLabel") }}</span>
          <input v-model.trim="projectFilters.owner" type="text" :placeholder="t('admin.projectOwnerFilterPlaceholder')" />
        </label>
        <label class="field">
          <span>{{ t("editor.projectVisibility") }}</span>
          <select v-model="projectFilters.visibility">
            <option value="">{{ t("admin.filterAll") }}</option>
            <option value="true">{{ t("editor.visibilityPublic") }}</option>
            <option value="false">{{ t("editor.visibilityPrivate") }}</option>
          </select>
        </label>
        <button class="btn btn-sm btn-secondary" type="submit">{{ t("admin.applyFilters") }}</button>
      </form>

      <form v-if="projectFormOpen" class="admin-editor-grid" @submit.prevent="submitProjectForm">
        <label class="field">
          <span>{{ t("admin.projectOwnerLabel") }}</span>
          <input v-model.number="projectForm.ownerId" type="number" min="1" step="1" :disabled="projectForm.mode === 'edit'" required />
        </label>
        <label class="field">
          <span>{{ t("editor.projectName") }}</span>
          <input v-model.trim="projectForm.name" type="text" maxlength="255" required />
        </label>
        <label class="field">
          <span>{{ t("editor.projectDescription") }}</span>
          <input v-model.trim="projectForm.description" type="text" maxlength="500" />
        </label>
        <label class="field">
          <span>{{ t("editor.projectVisibility") }}</span>
          <select v-model="projectForm.visibility">
            <option value="private">{{ t("editor.visibilityPrivate") }}</option>
            <option value="public">{{ t("editor.visibilityPublic") }}</option>
          </select>
        </label>
        <div class="admin-editor-actions">
          <button class="btn" type="submit" :disabled="projectsSaving">
            {{ projectsSaving ? t("common.saving") : (projectForm.mode === "create" ? t("admin.projectCreateSubmit") : t("admin.projectUpdateSubmit")) }}
          </button>
          <button class="btn btn-ghost" type="button" :disabled="projectsSaving" @click="closeProjectForm">
            {{ t("common.cancel") }}
          </button>
        </div>
      </form>

      <p v-if="projectsError" class="error-banner">{{ projectsError }}</p>
      <p v-if="projectsNotice" class="notice-banner">{{ projectsNotice }}</p>

      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>{{ t("admin.tableProjectId") }}</th>
              <th>{{ t("editor.projectName") }}</th>
              <th>{{ t("admin.projectOwnerLabel") }}</th>
              <th>{{ t("editor.projectVisibility") }}</th>
              <th>{{ t("admin.tableCreated") }}</th>
              <th>{{ t("admin.actionsLabel") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="projectsLoading">
              <td colspan="6">{{ t("admin.loadingProjects") }}</td>
            </tr>
            <tr v-else-if="projects.length === 0">
              <td colspan="6">{{ t("admin.noProjects") }}</td>
            </tr>
            <tr v-for="project in projects" :key="project.project_id">
              <td>{{ project.project_id }}</td>
              <td>
                <strong>{{ project.name || "-" }}</strong>
                <br />
                <small class="muted-text">{{ project.description || t("projects.noDescription") }}</small>
              </td>
              <td>
                <strong>#{{ project.owner_id }}</strong>
                <br />
                <small class="muted-text">{{ project.owner?.email || project.owner?.name || "-" }}</small>
              </td>
              <td>{{ project.is_public ? t("editor.visibilityPublic") : t("editor.visibilityPrivate") }}</td>
              <td>{{ formatDate(project.created_at) }}</td>
              <td>
                <div class="admin-row-actions">
                  <button class="btn btn-sm btn-ghost" type="button" :disabled="projectsSaving" @click="startEditProject(project)">
                    {{ t("admin.editAction") }}
                  </button>
                  <button
                    class="btn btn-sm btn-danger"
                    type="button"
                    :disabled="projectsSaving || projectsDeletingId === Number(project.project_id || 0)"
                    @click="deleteProject(project)"
                  >
                    {{ projectsDeletingId === Number(project.project_id || 0) ? t("common.saving") : t("admin.deleteAction") }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="admin-pagination">
        <button class="btn btn-sm btn-ghost" type="button" :disabled="projectsPage <= 1 || projectsLoading" @click="loadProjects(projectsPage - 1)">
          {{ t("projects.prevPage") }}
        </button>
        <span class="admin-pagination-status">{{ t("projects.pageStatus", { current: projectsPage, total: projectsLastPage }) }}</span>
        <button class="btn btn-sm btn-ghost" type="button" :disabled="projectsPage >= projectsLastPage || projectsLoading" @click="loadProjects(projectsPage + 1)">
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

const { t, locale } = useI18n();

const isOpen = ref(true);
const projects = ref([]);
const projectsLoading = ref(false);
const projectsSaving = ref(false);
const projectsDeletingId = ref(0);
const projectsError = ref("");
const projectsNotice = ref("");
const projectsPage = ref(1);
const projectsLastPage = ref(1);
const projectFormOpen = ref(false);

const projectFilters = reactive({
  search: "",
  owner: "",
  visibility: "",
});

const projectForm = reactive({
  mode: "create",
  projectId: 0,
  ownerId: 1,
  name: "",
  description: "",
  visibility: "private",
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

function resetProjectForm() {
  projectForm.mode = "create";
  projectForm.projectId = 0;
  projectForm.ownerId = 1;
  projectForm.name = "";
  projectForm.description = "";
  projectForm.visibility = "private";
}

function toggleProjectForm() {
  isOpen.value = true;
  projectFormOpen.value = !projectFormOpen.value;
  if (projectFormOpen.value) {
    resetProjectForm();
  }
}

function closeProjectForm() {
  projectFormOpen.value = false;
  resetProjectForm();
}

function startEditProject(project) {
  isOpen.value = true;
  projectFormOpen.value = true;
  projectForm.mode = "edit";
  projectForm.projectId = Number(project?.project_id || 0);
  projectForm.ownerId = Number(project?.owner_id || 0);
  projectForm.name = String(project?.name || "");
  projectForm.description = String(project?.description || "");
  projectForm.visibility = project?.is_public ? "public" : "private";
}

async function loadProjects(page = 1) {
  projectsLoading.value = true;
  projectsError.value = "";

  try {
    const response = await request({
      method: "GET",
      path: "/admin-projects",
      auth: true,
      query: {
        page,
        per_page: 12,
        search: projectFilters.search,
        owner: projectFilters.owner,
        is_public: projectFilters.visibility,
      },
    });

    projects.value = Array.isArray(response.data?.data) ? response.data.data : [];
    projectsPage.value = Number(response.data?.current_page || page || 1);
    projectsLastPage.value = Math.max(1, Number(response.data?.last_page || 1));
  } catch (requestError) {
    projectsError.value = readError(requestError);
  } finally {
    projectsLoading.value = false;
  }
}

function applyProjectFilters() {
  void loadProjects(1);
}

async function submitProjectForm() {
  const name = projectForm.name.trim();
  if (!name) {
    projectsError.value = t("editor.projectNameRequired");
    return;
  }

  projectsSaving.value = true;
  projectsError.value = "";
  projectsNotice.value = "";

  try {
    if (projectForm.mode === "create") {
      await request({
        method: "POST",
        path: "/admin-projects",
        auth: true,
        body: {
          owner_id: Number(projectForm.ownerId || 0),
          name,
          description: projectForm.description.trim() || null,
          is_public: projectForm.visibility === "public",
        },
      });

      projectsNotice.value = t("admin.projectCreated");
      closeProjectForm();
      await loadProjects(1);
      return;
    }

    await request({
      method: "PATCH",
      path: `/admin-projects/${projectForm.projectId}`,
      auth: true,
      body: {
        name,
        description: projectForm.description.trim() || null,
        is_public: projectForm.visibility === "public",
      },
    });

    projectsNotice.value = t("admin.projectUpdated");
    closeProjectForm();
    await loadProjects(projectsPage.value);
  } catch (requestError) {
    projectsError.value = readError(requestError);
  } finally {
    projectsSaving.value = false;
  }
}

async function deleteProject(project) {
  const projectId = Number(project?.project_id || 0);
  if (!projectId) {
    return;
  }

  const firstConfirm = window.confirm(t("admin.projectDeleteConfirmStepOne", { id: projectId }));
  if (!firstConfirm) {
    return;
  }

  const secondConfirm = window.confirm(t("admin.projectDeleteConfirmStepTwo", { name: project?.name || "-" }));
  if (!secondConfirm) {
    return;
  }

  projectsDeletingId.value = projectId;
  projectsError.value = "";
  projectsNotice.value = "";

  try {
    await request({
      method: "DELETE",
      path: `/admin-projects/${projectId}`,
      auth: true,
    });

    projectsNotice.value = t("admin.projectDeleted");
    await loadProjects(projectsPage.value);
  } catch (requestError) {
    projectsError.value = readError(requestError);
  } finally {
    projectsDeletingId.value = 0;
  }
}

onMounted(() => {
  void loadProjects();
});
</script>
