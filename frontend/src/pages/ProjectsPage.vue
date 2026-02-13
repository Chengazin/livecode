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
      <p v-else-if="projects.length === 0" class="muted-text">{{ t("projects.emptyProjects") }}</p>

      <div v-else class="projects-grid">
        <article v-for="project in projects" :key="project.project_id" class="project-tile">
          <div class="project-tile-main">
            <h3>{{ project.name }}</h3>
            <p v-if="project.description" class="muted-text">{{ project.description }}</p>
          </div>
          <button class="btn btn-secondary" type="button" @click="openProject(project.project_id)">
            {{ t("projects.openProject") }}
          </button>
        </article>
      </div>
    </section>
  </div>
</template>

<script setup>
import { nextTick, onMounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter } from "vue-router";
import { request } from "../services/api";
import { getSession } from "../services/auth";

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

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

function readError(errorInput) {
  if (typeof errorInput?.data?.message === "string" && errorInput.data.message.trim() !== "") {
    return errorInput.data.message;
  }

  if (typeof errorInput?.message === "string" && errorInput.message.trim() !== "") {
    return errorInput.message;
  }

  return t("common.requestFailed");
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

onMounted(() => {
  if (!getSession().accessToken) {
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
