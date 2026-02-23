<template>
  <div class="page projects-page">
    <section class="card projects-head">
      <div class="projects-head-main">
        <h1>{{ t("projects.title") }}</h1>
        <p class="muted-text">{{ t("projects.subtitle") }}</p>
      </div>
      <div class="projects-head-actions">
        <button class="btn btn-sm btn-secondary" type="button" :disabled="creating" @click="toggleCreateForm">
          {{ showCreateForm ? t("common.cancel") : t("projects.newProject") }}
        </button>
        <button class="btn btn-sm btn-ghost" type="button" :disabled="loading" @click="loadProjects">
          {{ loading ? t("common.loading") : t("common.refresh") }}
        </button>
      </div>

      <form v-if="showCreateForm" class="projects-create-inline" @submit.prevent="createProject">
        <input
          ref="nameInput"
          v-model.trim="form.name"
          type="text"
          maxlength="255"
          required
          :placeholder="t('projects.projectName')"
          :aria-label="t('projects.projectName')"
        />
        <input
          v-model.trim="form.description"
          type="text"
          maxlength="500"
          :placeholder="t('projects.projectDescription')"
          :aria-label="t('projects.projectDescription')"
        />
        <button class="btn" type="submit" :disabled="creating">
          {{ creating ? t("projects.creatingProject") : t("projects.createProject") }}
        </button>
      </form>
    </section>

    <p v-if="notice" class="notice-banner">{{ notice }}</p>
    <p v-if="error" class="error-banner">{{ error }}</p>

    <section class="card projects-list-card">
      <div class="sidebar-head">
        <h2>{{ t("projects.yourProjects") }}</h2>
      </div>

      <p v-if="loading" class="muted-text">{{ t("projects.loadingProjects") }}</p>
      <template v-else>
        <p v-if="ownedProjects.length === 0" class="muted-text">{{ t("projects.emptyProjects") }}</p>
        <template v-else>
          <div class="projects-grid">
            <article
              v-for="project in pagedOwnedProjects"
              :key="project.project_id"
              class="project-tile project-tile--clickable"
              role="button"
              tabindex="0"
              @click="openProjectInfo(project.project_id)"
              @keydown.enter.prevent="openProjectInfo(project.project_id)"
              @keydown.space.prevent="openProjectInfo(project.project_id)"
            >
              <div class="project-tile-head">
                <h3>{{ project.name }}</h3>
              </div>
              <div class="project-tile-main">
                <p v-if="project.description" class="muted-text">{{ project.description }}</p>
                <p v-else class="muted-text">{{ t("projects.noDescription") }}</p>
              </div>
              <div class="project-tile-actions">
                <button class="btn btn-ghost" type="button" @click.stop="openProjectInfo(project.project_id)">
                  {{ t("projects.openProjectInfo") }}
                </button>
                <button class="btn btn-secondary" type="button" @click.stop="openProject(project.project_id)">
                  {{ t("projects.openProject") }}
                </button>
              </div>
            </article>
          </div>

          <div v-if="ownedTotalPages > 1" class="projects-pagination">
            <button class="btn btn-sm btn-ghost" type="button" :disabled="ownedPage <= 1" @click="ownedPage -= 1">
              {{ t("projects.prevPage") }}
            </button>
            <span class="projects-pagination-status">{{ t("projects.pageStatus", { current: ownedPage, total: ownedTotalPages }) }}</span>
            <button class="btn btn-sm btn-ghost" type="button" :disabled="ownedPage >= ownedTotalPages" @click="ownedPage += 1">
              {{ t("projects.nextPage") }}
            </button>
          </div>
        </template>

        <div class="projects-subsection">
          <div class="sidebar-head">
            <h3>{{ t("projects.collaboratorProjects") }}</h3>
          </div>

          <p v-if="collaboratorProjects.length === 0" class="muted-text">{{ t("projects.emptyCollaboratorProjects") }}</p>
          <template v-else>
            <div class="projects-grid">
              <article
                v-for="project in pagedCollaboratorProjects"
                :key="project.project_id"
                class="project-tile project-tile--clickable"
                role="button"
                tabindex="0"
                @click="openProjectInfo(project.project_id)"
                @keydown.enter.prevent="openProjectInfo(project.project_id)"
                @keydown.space.prevent="openProjectInfo(project.project_id)"
              >
                <div class="project-tile-head">
                  <h3>{{ project.name }}</h3>
                </div>
                <div class="project-tile-main">
                  <p v-if="project.description" class="muted-text">{{ project.description }}</p>
                  <p v-else class="muted-text">{{ t("projects.noDescription") }}</p>
                </div>
                <div class="project-tile-actions">
                  <button class="btn btn-ghost" type="button" @click.stop="openProjectInfo(project.project_id)">
                    {{ t("projects.openProjectInfo") }}
                  </button>
                  <button class="btn btn-secondary" type="button" @click.stop="openProject(project.project_id)">
                    {{ t("projects.openProject") }}
                  </button>
                </div>
              </article>
            </div>

            <div v-if="collaboratorTotalPages > 1" class="projects-pagination">
              <button class="btn btn-sm btn-ghost" type="button" :disabled="collaboratorPage <= 1" @click="collaboratorPage -= 1">
                {{ t("projects.prevPage") }}
              </button>
              <span class="projects-pagination-status">
                {{ t("projects.pageStatus", { current: collaboratorPage, total: collaboratorTotalPages }) }}
              </span>
              <button
                class="btn btn-sm btn-ghost"
                type="button"
                :disabled="collaboratorPage >= collaboratorTotalPages"
                @click="collaboratorPage += 1"
              >
                {{ t("projects.nextPage") }}
              </button>
            </div>
          </template>
        </div>
      </template>
    </section>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter } from "vue-router";
import { request } from "../services/api";
import { getSession } from "../services/auth";

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const session = ref(getSession());

const loading = ref(false);
const creating = ref(false);
const projects = ref([]);
const notice = ref("");
const error = ref("");
const showCreateForm = ref(false);
const nameInput = ref(null);

const form = reactive({
  name: "",
  description: "",
});
const PROJECTS_PER_PAGE = 9;
const ownedPage = ref(1);
const collaboratorPage = ref(1);

const currentUserId = computed(() => Number(session.value?.user?.user_id || 0));
const ownedProjects = computed(() => {
  if (!currentUserId.value) {
    return projects.value;
  }

  return projects.value.filter((project) => Number(project?.owner_id || 0) === currentUserId.value);
});
const collaboratorProjects = computed(() => {
  if (!currentUserId.value) {
    return [];
  }

  return projects.value.filter((project) => Number(project?.owner_id || 0) !== currentUserId.value);
});
const ownedTotalPages = computed(() => {
  return Math.max(1, Math.ceil(ownedProjects.value.length / PROJECTS_PER_PAGE));
});
const collaboratorTotalPages = computed(() => {
  return Math.max(1, Math.ceil(collaboratorProjects.value.length / PROJECTS_PER_PAGE));
});
const pagedOwnedProjects = computed(() => {
  const start = (ownedPage.value - 1) * PROJECTS_PER_PAGE;
  return ownedProjects.value.slice(start, start + PROJECTS_PER_PAGE);
});
const pagedCollaboratorProjects = computed(() => {
  const start = (collaboratorPage.value - 1) * PROJECTS_PER_PAGE;
  return collaboratorProjects.value.slice(start, start + PROJECTS_PER_PAGE);
});

function readError(errorInput) {
  if (typeof errorInput?.data?.message === "string" && errorInput.data.message.trim() !== "") {
    return errorInput.data.message;
  }

  if (typeof errorInput?.message === "string" && errorInput.message.trim() !== "") {
    return errorInput.message;
  }

  return t("common.requestFailed");
}

function clampPaginationPages() {
  ownedPage.value = Math.min(Math.max(ownedPage.value, 1), ownedTotalPages.value);
  collaboratorPage.value = Math.min(Math.max(collaboratorPage.value, 1), collaboratorTotalPages.value);
}

async function loadProjects() {
  loading.value = true;
  error.value = "";

  try {
    const response = await request({
      method: "GET",
      path: "/projects",
      query: { per_page: 200 },
      auth: true,
    });

    projects.value = Array.isArray(response.data?.data) ? response.data.data : [];
    clampPaginationPages();
  } catch (loadError) {
    error.value = readError(loadError);
  } finally {
    loading.value = false;
  }
}

async function createProject() {
  const name = String(form.name || "").trim();
  if (!name) {
    return;
  }

  creating.value = true;
  error.value = "";
  notice.value = "";

  try {
    const response = await request({
      method: "POST",
      path: "/projects",
      auth: true,
      body: {
        name,
        description: form.description ? form.description : null,
        is_public: false,
      },
    });

    const projectId = response.data?.project_id;
    if (!projectId) {
      throw new Error(t("common.requestFailed"));
    }

    form.name = "";
    form.description = "";
    showCreateForm.value = false;
    notice.value = t("projects.projectCreated");
    await router.push(`/projects/${projectId}/editor`);
  } catch (createError) {
    error.value = readError(createError);
  } finally {
    creating.value = false;
  }
}

function toggleCreateForm() {
  showCreateForm.value = !showCreateForm.value;

  if (!showCreateForm.value) {
    return;
  }

  nextTick(() => {
    if (nameInput.value && typeof nameInput.value.focus === "function") {
      nameInput.value.focus();
    }
  });
}

async function openProject(projectId) {
  await router.push(`/projects/${projectId}/editor`);
}

async function openProjectInfo(projectId) {
  await router.push(`/projects/${projectId}/info`);
}

watch(
  () => ownedProjects.value.length,
  () => {
    clampPaginationPages();
  },
);

watch(
  () => collaboratorProjects.value.length,
  () => {
    clampPaginationPages();
  },
);

onMounted(() => {
  if (!session.value.accessToken) {
    void router.replace({ path: "/login", query: { redirect: route.fullPath } });
    return;
  }

  if (route.query.forgejo === "connected") {
    notice.value = t("editor.authConnectedNotice");
    const query = { ...route.query };
    delete query.forgejo;
    void router.replace({ query });
  }

  void loadProjects();
});
</script>
    