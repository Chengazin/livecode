<template>
  <div class="page project-info-page">
    <section class="card project-info-head">
      <div class="project-info-head-main">
        <h1>{{ project?.name || t("projectInfo.titleFallback", { id: projectId || "?" }) }}</h1>
        <p class="muted-text">{{ project?.description || t("projects.noDescription") }}</p>
        <div class="project-info-chips">
          <span class="project-info-chip">{{ t("projectInfo.roleLabel") }}: {{ roleLabel(permissions.effective_role) }}</span>
          <span class="project-info-chip">{{ t("projectInfo.visibilityLabel") }}: {{ project?.is_public ? t("editor.visibilityPublic") : t("editor.visibilityPrivate") }}</span>
          <span class="project-info-chip">{{ t("projectInfo.branchLabel") }}: <code>{{ project?.forgejo_default_branch || "main" }}</code></span>
        </div>
      </div>
      <div class="project-info-head-actions">
        <button class="btn btn-sm btn-ghost" type="button" @click="goToProjects">
          {{ t("projectInfo.backToProjects") }}
        </button>
        <button class="btn btn-sm btn-secondary" type="button" :disabled="!projectId" @click="openEditor">
          {{ t("projectInfo.openEditor") }}
        </button>
        <button class="btn btn-sm btn-ghost" type="button" :disabled="loading" @click="loadInfo">
          {{ loading ? t("common.loading") : t("common.refresh") }}
        </button>
      </div>
    </section>

    <p v-if="notice" class="notice-banner">{{ notice }}</p>
    <p v-if="error" class="error-banner">{{ error }}</p>

    <section v-if="loading" class="card">
      <p class="muted-text">{{ t("projectInfo.loading") }}</p>
    </section>

    <template v-else>
      <section class="card project-info-grid">
        <div class="project-info-card">
          <h2>{{ t("projectInfo.repositoryTitle") }}</h2>
          <p class="muted-text">{{ t("projectInfo.repositoryHint") }}</p>
          <dl class="project-info-metadata">
            <div>
              <dt>{{ t("projectInfo.repositoryStatus") }}</dt>
              <dd>
                <span v-if="project?.git_enabled">{{ t("projectInfo.repositoryConnected") }}</span>
                <span v-else>{{ t("projectInfo.repositoryNotConnected") }}</span>
              </dd>
            </div>
            <div v-if="project?.forgejo_repo_full_name">
              <dt>{{ t("projectInfo.repositoryName") }}</dt>
              <dd><code>{{ project.forgejo_repo_full_name }}</code></dd>
            </div>
            <div v-if="project?.forgejo_repo_html_url">
              <dt>Forgejo</dt>
              <dd><a :href="project.forgejo_repo_html_url" target="_blank" rel="noopener noreferrer">{{ project.forgejo_repo_html_url }}</a></dd>
            </div>
            <div v-if="project?.forgejo_last_push_at">
              <dt>{{ t("projectInfo.lastPushAt") }}</dt>
              <dd>{{ formatDateTime(project.forgejo_last_push_at) }}</dd>
            </div>
          </dl>
          <p v-if="permissions.can_create_pull_request" class="muted-text">
            {{ t("projectInfo.pullRequestHint") }}
          </p>
        </div>

        <div v-if="canManageSettings" class="project-info-card">
          <h2>{{ t("projectInfo.settingsTitle") }}</h2>
          <form class="form-grid compact-form" @submit.prevent="saveProjectSettings">
            <label class="field field-row">
              <span>{{ t("editor.projectName") }}</span>
              <input v-model.trim="settingsForm.name" type="text" maxlength="255" required />
            </label>
            <label class="field">
              <span>{{ t("editor.projectDescription") }}</span>
              <textarea v-model.trim="settingsForm.description" rows="3" maxlength="2000" />
            </label>
            <label class="field field-row">
              <span>{{ t("editor.projectVisibility") }}</span>
              <select v-model="settingsForm.visibility">
                <option value="private">{{ t("editor.visibilityPrivate") }}</option>
                <option value="public">{{ t("editor.visibilityPublic") }}</option>
              </select>
            </label>
            <button class="btn btn-secondary" type="submit" :disabled="settingsBusy">
              {{ settingsBusy ? t("common.saving") : t("editor.saveProjectSettings") }}
            </button>
          </form>
        </div>
      </section>

      <section class="card project-info-card">
        <div class="sidebar-head">
          <h2>{{ t("projectInfo.collaboratorsTitle") }}</h2>
        </div>

        <form v-if="canManageParticipants" class="form-grid compact-form project-info-inline-form" @submit.prevent="addCollaborator">
          <label class="field field-row">
            <span>{{ t("editor.collaboratorUserId") }}</span>
            <input
              v-model.trim="newCollaboratorUserId"
              type="number"
              min="1"
              step="1"
              inputmode="numeric"
              :placeholder="t('editor.collaboratorUserIdPlaceholder')"
            />
          </label>
          <label class="field field-row">
            <span>{{ t("projectInfo.accessRole") }}</span>
            <select v-model="newCollaboratorRole">
              <option v-for="role in assignableRoles" :key="`add-role-${role}`" :value="role">{{ roleLabel(role) }}</option>
            </select>
          </label>
          <button class="btn btn-secondary" type="submit" :disabled="collaboratorBusy || !newCollaboratorUserId">
            {{ collaboratorBusy ? t("common.saving") : t("editor.addCollaborator") }}
          </button>
        </form>

        <div class="project-info-collaborator-list">
          <article class="project-info-collaborator-row project-info-collaborator-row--owner">
            <div>
              <strong>{{ project?.owner?.name || `#${project?.owner_id || ""}` }}</strong>
              <small>#{{ project?.owner_id }} - {{ project?.owner?.email || "" }}</small>
            </div>
            <span class="project-info-role-badge">{{ roleLabel("owner") }}</span>
          </article>

          <article v-for="participant in participants" :key="participant.participant_id" class="project-info-collaborator-row">
            <div>
              <strong>{{ participant.user?.name || `#${participant.user_id}` }}</strong>
              <small>#{{ participant.user_id }} - {{ participant.user?.email || "" }}</small>
            </div>
            <div class="project-info-collaborator-actions">
              <select
                v-if="canManageParticipants"
                v-model="participant.role"
                :disabled="collaboratorBusy || !canEditParticipantRole(participant)"
                @change="updateParticipantRole(participant)"
              >
                <option
                  v-for="role in participantRoleOptions(participant)"
                  :key="`${participant.participant_id}-${role}`"
                  :value="role"
                >
                  {{ roleLabel(role) }}
                </option>
              </select>
              <span v-else class="project-info-role-badge">{{ roleLabel(participant.role) }}</span>
              <button
                v-if="canManageParticipants"
                class="btn btn-sm btn-ghost"
                type="button"
                :disabled="collaboratorBusy || !canRemoveParticipant(participant)"
                @click="removeCollaborator(participant)"
              >
                {{ t("editor.removeCollaborator") }}
              </button>
            </div>
          </article>
        </div>
      </section>

      <section class="card project-info-card">
        <div class="sidebar-head">
          <h2>{{ t("projectInfo.statsTitle") }}</h2>
          <label class="field field-row project-info-period-field">
            <span>{{ t("projectInfo.periodLabel") }}</span>
            <select v-model.number="periodDays" @change="loadInfo">
              <option :value="7">7d</option>
              <option :value="30">30d</option>
              <option :value="90">90d</option>
            </select>
          </label>
        </div>
        <p class="muted-text">
          {{ t("projectInfo.statsSummary", { commits: stats.total_commits, snapshots: stats.total_snapshots }) }}
        </p>
        <div v-if="statsTimeline.length > 0" class="project-info-timeline">
          <div v-for="point in statsTimeline" :key="point.date" class="project-info-timeline-item">
            <div class="project-info-timeline-bars">
              <span class="project-info-timeline-bar project-info-timeline-bar--commits" :style="{ height: `${point.commitsHeight}%` }" />
              <span class="project-info-timeline-bar project-info-timeline-bar--snapshots" :style="{ height: `${point.snapshotsHeight}%` }" />
            </div>
            <small>{{ formatShortDate(point.date) }}</small>
          </div>
        </div>
        <p v-if="statsTimeline.length > 0" class="muted-text project-info-timeline-legend">
          <span><i class="project-info-legend-dot project-info-legend-dot--commits" />{{ t("projectInfo.legendCommits") }}</span>
          <span><i class="project-info-legend-dot project-info-legend-dot--snapshots" />{{ t("projectInfo.legendSnapshots") }}</span>
        </p>
        <div v-if="stats.contributors.length === 0" class="muted-text">{{ t("projectInfo.noStats") }}</div>
        <div v-else class="project-info-stats-table">
          <div v-for="entry in stats.contributors" :key="entry.key" class="project-info-stats-row">
            <div>
              <strong>{{ entry.name }}</strong>
              <small>{{ entry.email || t("projectInfo.noEmail") }}</small>
            </div>
            <div class="project-info-stats-values">
              <span>{{ t("projectInfo.commitCount", { count: entry.commit_count }) }}</span>
              <span>{{ t("projectInfo.snapshotCount", { count: entry.snapshot_count }) }}</span>
              <span v-if="entry.last_activity_at">{{ formatDateTime(entry.last_activity_at) }}</span>
            </div>
          </div>
        </div>
      </section>

      <section class="card project-info-card">
        <h2>{{ t("projectInfo.historyTitle") }}</h2>
        <div class="project-info-history-filters">
          <label class="field field-row">
            <span>{{ t("projectInfo.authorFilter") }}</span>
            <select v-model="historyAuthorFilter">
              <option value="all">{{ t("projectInfo.authorAll") }}</option>
              <option v-for="entry in stats.contributors" :key="`author-${entry.key}`" :value="entry.key">
                {{ entry.name }}
              </option>
            </select>
          </label>
        </div>

        <div class="project-info-history-grid">
          <div>
            <h3>{{ t("projectInfo.gitHistoryTitle") }}</h3>
            <p v-if="history.git?.error" class="muted-text">{{ history.git.error }}</p>
            <p v-else-if="!history.git?.available" class="muted-text">{{ t("projectInfo.gitHistoryUnavailable") }}</p>
            <p v-else-if="filteredGitCommits.length === 0" class="muted-text">{{ t("projectInfo.gitHistoryEmpty") }}</p>
            <div v-else class="project-info-history-list">
              <article v-for="commit in filteredGitCommits" :key="commit.hash" class="project-info-history-row">
                <header>
                  <strong><code>{{ commit.short_hash }}</code> {{ commit.subject }}</strong>
                </header>
                <small>
                  {{ commit.author_name }} - {{ formatDateTime(commit.authored_at) }}
                </small>
              </article>
            </div>
          </div>

          <div>
            <h3>{{ t("projectInfo.snapshotHistoryTitle") }}</h3>
            <p v-if="filteredSnapshots.length === 0" class="muted-text">{{ t("projectInfo.snapshotHistoryEmpty") }}</p>
            <div v-else class="project-info-history-list">
              <article v-for="snapshot in filteredSnapshots" :key="snapshot.snapshot_id" class="project-info-history-row">
                <header>
                  <strong>#{{ snapshot.snapshot_id }} {{ snapshot.message || t("projectInfo.snapshotNoMessage") }}</strong>
                </header>
                <small>
                  {{ snapshot.author?.name || t("projectInfo.unknownAuthor") }} - {{ formatDateTime(snapshot.created_at) }}
                </small>
                <small><code>{{ snapshot.snapshot_path }}</code></small>
              </article>
            </div>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter } from "vue-router";
import { request } from "../services/api";
import { getSession } from "../services/auth";

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const session = ref(getSession());
const loading = ref(false);
const settingsBusy = ref(false);
const collaboratorBusy = ref(false);
const notice = ref("");
const error = ref("");

const payload = ref(null);
const participants = ref([]);
const periodDays = ref(30);
const commitLimit = ref(200);

const newCollaboratorUserId = ref("");
const newCollaboratorRole = ref("developer");

const settingsForm = reactive({
  name: "",
  description: "",
  visibility: "private",
});

const projectId = computed(() => String(route.params.projectId || "").trim());
const project = computed(() => payload.value?.project || null);
const permissions = computed(() => payload.value?.permissions || {});
const roles = computed(() => {
  const list = Array.isArray(payload.value?.roles) ? payload.value.roles : [];
  if (list.length > 0) {
    return list;
  }

  return ["viewer", "developer", "maintainer"];
});
const stats = computed(() => payload.value?.stats || {
  total_commits: 0,
  total_snapshots: 0,
  contributors: [],
});
const history = computed(() => payload.value?.history || {
  git: {
    available: false,
    commits: [],
    error: "",
  },
  snapshots: [],
});
const canManageSettings = computed(() => Boolean(permissions.value?.can_manage_settings));
const canManageParticipants = computed(() => Boolean(permissions.value?.can_manage_participants));
const historyAuthorFilter = ref("all");
const rolePriority = ["viewer", "developer", "maintainer"];

const assignableRoles = computed(() => {
  if (!canManageParticipants.value) {
    return [];
  }

  const available = roles.value.filter((role) => rolePriority.includes(role));
  if (permissions.value?.is_owner) {
    return available;
  }

  return available.filter((role) => role !== "maintainer");
});

const statsTimeline = computed(() => {
  const rows = Array.isArray(stats.value?.timeline) ? stats.value.timeline : [];
  const points = rows
    .map((row) => ({
      date: String(row?.date || "").trim(),
      commits: Math.max(0, Number(row?.commits || 0)),
      snapshots: Math.max(0, Number(row?.snapshots || 0)),
    }))
    .filter((point) => point.date !== "");

  if (points.length === 0) {
    return [];
  }

  const maxValue = points.reduce((max, point) => Math.max(max, point.commits, point.snapshots), 0);
  const normalizedMax = maxValue > 0 ? maxValue : 1;

  return points.map((point) => ({
    ...point,
    commitsHeight: point.commits > 0
      ? Math.max(8, Math.round((point.commits / normalizedMax) * 100))
      : 0,
    snapshotsHeight: point.snapshots > 0
      ? Math.max(8, Math.round((point.snapshots / normalizedMax) * 100))
      : 0,
  }));
});

const filteredGitCommits = computed(() => {
  const commits = Array.isArray(history.value?.git?.commits) ? history.value.git.commits : [];
  const filterKey = String(historyAuthorFilter.value || "all");
  if (filterKey === "all") {
    return commits;
  }

  return commits.filter((commit) => resolveCommitContributorKey(commit) === filterKey);
});

const filteredSnapshots = computed(() => {
  const snapshots = Array.isArray(history.value?.snapshots) ? history.value.snapshots : [];
  const filterKey = String(historyAuthorFilter.value || "all");
  if (filterKey === "all") {
    return snapshots;
  }

  return snapshots.filter((snapshot) => {
    const authorId = Number(snapshot?.author?.user_id || 0);
    return authorId > 0 && `user:${authorId}` === filterKey;
  });
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

function roleLabel(role) {
  const normalized = String(role || "").trim().toLowerCase();
  if (normalized === "owner") {
    return t("projectInfo.roles.owner");
  }

  if (normalized === "maintainer") {
    return t("projectInfo.roles.maintainer");
  }

  if (normalized === "viewer") {
    return t("projectInfo.roles.viewer");
  }

  return t("projectInfo.roles.developer");
}

function formatDateTime(value) {
  const raw = String(value || "").trim();
  if (!raw) {
    return "";
  }

  const date = new Date(raw);
  if (Number.isNaN(date.getTime())) {
    return raw;
  }

  return date.toLocaleString();
}

function formatShortDate(value) {
  const raw = String(value || "").trim();
  if (!raw) {
    return "";
  }

  const date = new Date(raw);
  if (Number.isNaN(date.getTime())) {
    return raw;
  }

  return date.toLocaleDateString(undefined, {
    month: "short",
    day: "numeric",
  });
}

function normalizeRole(value) {
  const role = String(value || "").trim().toLowerCase();
  if (rolePriority.includes(role)) {
    return role;
  }

  return "developer";
}

function canEditParticipantRole(participant) {
  if (!canManageParticipants.value || !participant) {
    return false;
  }

  if (!permissions.value?.is_owner && normalizeRole(participant.role) === "maintainer") {
    return false;
  }

  return true;
}

function participantRoleOptions(participant) {
  const currentRole = normalizeRole(participant?.role);
  const optionSet = new Set(assignableRoles.value);
  optionSet.add(currentRole);

  return rolePriority.filter((role) => optionSet.has(role));
}

function canRemoveParticipant(participant) {
  if (!canManageParticipants.value || !participant) {
    return false;
  }

  const participantUserId = Number(participant?.user_id || 0);
  const currentUserId = Number(session.value?.user?.user_id || 0);
  const isSelf = participantUserId > 0 && participantUserId === currentUserId;
  const isMaintainer = normalizeRole(participant.role) === "maintainer";

  if (!permissions.value?.is_owner && isMaintainer && !isSelf) {
    return false;
  }

  return true;
}

function resolveCommitContributorKey(commit) {
  const contributorKey = String(commit?.contributor_key || "").trim();
  if (contributorKey !== "") {
    return contributorKey;
  }

  const authorEmail = String(commit?.author_email || "").trim().toLowerCase();
  if (authorEmail !== "") {
    return `external:${authorEmail}`;
  }

  const authorName = String(commit?.author_name || "unknown").trim().toLowerCase();
  return `external:${authorName || "unknown"}`;
}

function syncSettingsForm() {
  settingsForm.name = String(project.value?.name || "");
  settingsForm.description = String(project.value?.description || "");
  settingsForm.visibility = project.value?.is_public ? "public" : "private";
}

function cloneParticipants(items) {
  const source = Array.isArray(items) ? items : [];

  return source.map((item) => ({
    ...item,
    user: item?.user ? { ...item.user } : null,
  }));
}

async function loadInfo() {
  if (!projectId.value) {
    return;
  }

  loading.value = true;
  error.value = "";

  try {
    const response = await request({
      method: "GET",
      path: `/projects/${projectId.value}/info`,
      auth: true,
      query: {
        period_days: periodDays.value,
        commit_limit: commitLimit.value,
      },
    });

    payload.value = response.data && typeof response.data === "object" ? response.data : null;
    participants.value = cloneParticipants(payload.value?.participants);
    syncSettingsForm();

    if (!assignableRoles.value.includes(newCollaboratorRole.value)) {
      newCollaboratorRole.value = assignableRoles.value[0] || "developer";
    }
  } catch (loadError) {
    error.value = readError(loadError);
  } finally {
    loading.value = false;
  }
}

async function saveProjectSettings() {
  if (!projectId.value || !canManageSettings.value || settingsBusy.value) {
    return;
  }

  const nextName = String(settingsForm.name || "").trim();
  if (!nextName) {
    error.value = t("editor.projectNameRequired");
    return;
  }

  settingsBusy.value = true;
  error.value = "";

  try {
    await request({
      method: "PATCH",
      path: `/projects/${projectId.value}`,
      auth: true,
      body: {
        name: nextName,
        description: String(settingsForm.description || "").trim() || null,
        is_public: settingsForm.visibility === "public",
      },
    });

    notice.value = t("editor.projectSettingsSaved");
    await loadInfo();
  } catch (settingsError) {
    error.value = readError(settingsError);
  } finally {
    settingsBusy.value = false;
  }
}

async function addCollaborator() {
  if (!projectId.value || !canManageParticipants.value || collaboratorBusy.value) {
    return;
  }

  const userId = Number.parseInt(String(newCollaboratorUserId.value || "").trim(), 10);
  if (!Number.isInteger(userId) || userId <= 0) {
    error.value = t("editor.invalidCollaboratorId");
    return;
  }

  collaboratorBusy.value = true;
  error.value = "";

  try {
    await request({
      method: "POST",
      path: "/project-participants",
      auth: true,
      body: {
        project_id: Number(projectId.value),
        user_id: userId,
        role: newCollaboratorRole.value,
      },
    });

    newCollaboratorUserId.value = "";
    notice.value = t("editor.collaboratorAdded");
    await loadInfo();
  } catch (addError) {
    error.value = readError(addError);
  } finally {
    collaboratorBusy.value = false;
  }
}

async function updateParticipantRole(participant) {
  if (
    !canManageParticipants.value
    || !participant?.participant_id
    || collaboratorBusy.value
    || !canEditParticipantRole(participant)
  ) {
    return;
  }

  collaboratorBusy.value = true;
  error.value = "";

  try {
    await request({
      method: "PATCH",
      path: `/project-participants/${participant.participant_id}`,
      auth: true,
      body: {
        role: participant.role,
      },
    });

    notice.value = t("projectInfo.accessUpdated");
    await loadInfo();
  } catch (updateError) {
    error.value = readError(updateError);
  } finally {
    collaboratorBusy.value = false;
  }
}

async function removeCollaborator(participant) {
  if (
    !canManageParticipants.value
    || !participant?.participant_id
    || collaboratorBusy.value
    || !canRemoveParticipant(participant)
  ) {
    return;
  }

  collaboratorBusy.value = true;
  error.value = "";

  try {
    await request({
      method: "DELETE",
      path: `/project-participants/${participant.participant_id}`,
      auth: true,
    });

    notice.value = t("editor.collaboratorRemoved");
    await loadInfo();
  } catch (removeError) {
    error.value = readError(removeError);
  } finally {
    collaboratorBusy.value = false;
  }
}

async function openEditor() {
  if (!projectId.value) {
    return;
  }

  await router.push(`/projects/${projectId.value}/editor`);
}

async function goToProjects() {
  await router.push("/projects");
}

watch(
  assignableRoles,
  (rolesList) => {
    if (!rolesList.includes(newCollaboratorRole.value)) {
      newCollaboratorRole.value = rolesList[0] || "developer";
    }
  },
  { immediate: true },
);

watch(
  () => stats.value?.contributors,
  () => {
    const availableKeys = new Set(
      (Array.isArray(stats.value?.contributors) ? stats.value.contributors : [])
        .map((item) => String(item?.key || "").trim())
        .filter((key) => key !== ""),
    );

    if (historyAuthorFilter.value !== "all" && !availableKeys.has(historyAuthorFilter.value)) {
      historyAuthorFilter.value = "all";
    }
  },
  { deep: true },
);

watch(
  () => route.params.projectId,
  async () => {
    session.value = getSession();
    if (!session.value.accessToken) {
      await router.replace({ path: "/login", query: { redirect: route.fullPath } });
      return;
    }

    if (!projectId.value) {
      await router.replace("/projects");
      return;
    }

    notice.value = "";
    error.value = "";
    await loadInfo();
  },
  { immediate: true },
);
</script>

