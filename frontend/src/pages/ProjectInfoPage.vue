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
          <label class="field">
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
          <label class="field">
            <span>{{ t("projectInfo.accessRole") }}</span>
            <select v-model="newCollaboratorRole">
              <option v-for="role in assignableRoles" :key="`add-role-${role}`" :value="role">{{ roleLabel(role) }}</option>
            </select>
          </label>
          <button class="btn btn-secondary project-info-inline-submit" type="submit" :disabled="collaboratorBusy || !newCollaboratorUserId">
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
          {{ t("projectInfo.statsSummary", { commits: stats.total_commits }) }}
        </p>
        <div v-if="statsTimeline.length > 0" class="project-info-timeline">
          <div v-for="point in statsTimeline" :key="point.date" class="project-info-timeline-item">
            <div class="project-info-timeline-bars">
              <span class="project-info-timeline-bar project-info-timeline-bar--commits" :style="{ height: `${point.commitsHeight}%` }" />
            </div>
            <small>{{ formatShortDate(point.date) }}</small>
          </div>
        </div>
        <p v-if="statsTimeline.length > 0" class="muted-text project-info-timeline-legend">
          <span><i class="project-info-legend-dot project-info-legend-dot--commits" />{{ t("projectInfo.legendCommits") }}</span>
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
              <span v-if="entry.last_activity_at">{{ formatDateTime(entry.last_activity_at) }}</span>
            </div>
          </div>
        </div>
      </section>

      <ProjectTasksKanban :project-id="projectId" :permissions="permissions" />

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

        <div class="project-info-history-single">
          <div>
            <h3>{{ t("projectInfo.gitHistoryTitle") }}</h3>
            <p v-if="history.git?.error" class="muted-text">{{ history.git.error }}</p>
            <p v-else-if="!history.git?.available" class="muted-text">{{ t("projectInfo.gitHistoryUnavailable") }}</p>
            <template v-else>
              <div class="project-info-git-tree-shell">
                <div>
                  <h4>{{ t("projectInfo.gitTreeTitle") }}</h4>
                  <p class="muted-text">{{ t("projectInfo.gitTreeHint") }}</p>
                  <p v-if="gitWorktreeDirty && canMutateGitState" class="muted-text">{{ t("projectInfo.gitActionDirtyHint") }}</p>
                  <p v-if="history.git?.tree_error" class="muted-text">{{ history.git.tree_error }}</p>
                  <p v-else-if="!history.git?.tree_available" class="muted-text">{{ t("projectInfo.gitTreeUnavailable") }}</p>
                  <p v-else-if="filteredGitBranchTree.length === 0" class="muted-text">{{ t("projectInfo.gitTreeEmpty") }}</p>
                  <div v-else class="project-info-git-tree-root">
                    <details
                      v-for="branch in filteredGitBranchTree"
                      :key="`tree-branch-${branch.name}`"
                      class="project-info-git-tree-branch"
                      :open="branch.is_current || branch.is_default"
                    >
                      <summary>
                        <div class="project-info-git-tree-branch-head">
                          <div class="project-info-git-tree-branch-title">
                            <strong><code>{{ branch.name }}</code></strong>
                            <small>{{ t("projectInfo.gitTreeCommitCount", { count: branch.commit_count }) }}</small>
                          </div>
                          <div class="project-info-git-tree-branch-badges">
                            <small v-if="branch.is_default">{{ t("projectInfo.gitTreeDefault") }}</small>
                            <small v-if="branch.is_current">{{ t("projectInfo.gitTreeCurrent") }}</small>
                            <button
                              v-if="canMutateGitState"
                              class="btn btn-sm btn-ghost"
                              type="button"
                              :disabled="gitActionBusy || gitWorktreeDirty"
                              @click.stop.prevent="openBranchInEditor(branch)"
                            >
                              {{ t("projectInfo.gitActionOpenBranch") }}
                            </button>
                          </div>
                        </div>
                      </summary>

                      <div class="project-info-git-tree-dates">
                        <details
                          v-for="dateGroup in branch.dates"
                          :key="`tree-date-${branch.name}-${dateGroup.date}`"
                          class="project-info-git-tree-date"
                        >
                          <summary>
                            <strong>{{ formatGitTreeDate(dateGroup.date) }}</strong>
                            <small>{{ t("projectInfo.gitTreeCommitCount", { count: dateGroup.count }) }}</small>
                          </summary>
                          <div class="project-info-history-list">
                            <article
                              v-for="commit in dateGroup.commits"
                              :key="`tree-commit-${branch.name}-${commit.hash}`"
                              class="project-info-history-row"
                            >
                              <div class="project-info-history-row-head">
                                <header>
                                  <strong><code>{{ commit.short_hash }}</code> {{ commit.subject }}</strong>
                                </header>
                                <div v-if="canMutateGitState" class="project-info-history-row-actions">
                                  <button
                                    class="btn btn-sm btn-ghost"
                                    type="button"
                                    :disabled="gitActionBusy || gitWorktreeDirty"
                                    @click="branchCommitAndOpen(commit)"
                                  >
                                    {{ t("projectInfo.gitActionBranchAndOpen") }}
                                  </button>
                                  <button
                                    class="btn btn-sm btn-ghost"
                                    type="button"
                                    :disabled="gitActionBusy || gitWorktreeDirty"
                                    @click="checkoutCommitAndOpen(commit)"
                                  >
                                    {{ t("projectInfo.gitActionCheckoutCommit") }}
                                  </button>
                                </div>
                              </div>
                              <small>
                                {{ commit.author_name }} - {{ formatDateTime(commit.authored_at) }}
                              </small>
                            </article>
                          </div>
                        </details>
                      </div>
                    </details>
                  </div>
                </div>

                <div v-if="history.git?.tree_available && !history.git?.tree_error">
                  <h4>{{ t("projectInfo.gitGraphTitle") }}</h4>
                  <p class="muted-text">{{ t("projectInfo.gitGraphHint") }}</p>
                  <p v-if="!gitVisualGraph.ready" class="muted-text">{{ t("projectInfo.gitGraphEmpty") }}</p>
                  <div v-else class="project-info-git-graph-root">
                    <div class="project-info-git-graph-legend">
                      <button
                        v-for="branch in gitGraphBranches"
                        :key="`graph-branch-${branch.name}`"
                        class="project-info-git-graph-legend-item"
                        :class="{
                          'is-active': gitGraphActiveBranchName === branch.name,
                          'is-dimmed': gitGraphActiveBranchName !== 'all' && gitGraphActiveBranchName !== branch.name,
                        }"
                        type="button"
                        :aria-pressed="gitGraphActiveBranchName === branch.name ? 'true' : 'false'"
                        @click="toggleGitGraphBranch(branch.name)"
                      >
                        <i class="project-info-git-graph-legend-color" :style="{ background: branch.color }" />
                        <code>{{ branch.name }}</code>
                        <small v-if="branch.is_default">{{ t("projectInfo.gitGraphRoot") }}</small>
                        <small v-if="branch.is_current">{{ t("projectInfo.gitTreeCurrent") }}</small>
                      </button>
                    </div>

                    <div
                      ref="gitGraphCanvasWrapRef"
                      class="project-info-git-graph-canvas-wrap"
                      @mouseleave="hideGitNodeTooltip"
                    >
                      <svg
                        class="project-info-git-graph-canvas"
                        :viewBox="`0 0 ${gitVisualGraph.width} ${gitVisualGraph.height}`"
                        preserveAspectRatio="xMidYMin meet"
                        :style="{ width: `${gitVisualGraph.renderWidth}px` }"
                        role="img"
                        :aria-label="t('projectInfo.gitGraphTitle')"
                      >
                        <g class="project-info-git-graph-lanes">
                          <line
                            v-for="branch in gitVisualGraph.branches"
                            :key="`graph-lane-${branch.name}`"
                            class="project-info-git-graph-lane"
                            :x1="branch.x"
                            :y1="gitVisualGraph.laneTop"
                            :x2="branch.x"
                            :y2="gitVisualGraph.laneBottom"
                            :style="{ stroke: branch.color }"
                          />
                        </g>

                        <g class="project-info-git-graph-edges">
                          <path
                            v-for="edge in gitVisualGraph.edges"
                            :key="edge.key"
                            class="project-info-git-graph-edge"
                            :class="{ 'is-cross-branch': edge.dashed }"
                            :d="edge.path"
                            :style="{ stroke: edge.color }"
                          />
                        </g>

                        <g class="project-info-git-graph-nodes">
                          <g
                            v-for="node in gitVisualGraph.nodes"
                            :key="`graph-node-${node.id}`"
                            :transform="`translate(${node.x} ${node.y})`"
                            @mouseenter="showGitNodeTooltip($event, node)"
                            @mousemove="moveGitNodeTooltip($event)"
                            @mouseleave="hideGitNodeTooltip"
                          >
                            <circle
                              class="project-info-git-graph-node"
                              r="4"
                              :style="{ fill: node.color }"
                            />
                            <circle class="project-info-git-graph-node-core" r="1.6" />
                            <title>{{ `${node.short_hash} | ${node.subject} | ${formatDateTime(node.authored_at)}` }}</title>
                          </g>
                        </g>
                      </svg>

                      <div
                        v-if="gitNodeTooltip.visible && gitNodeTooltip.node"
                        class="project-info-git-graph-tooltip"
                        :style="{ left: `${gitNodeTooltip.x}px`, top: `${gitNodeTooltip.y}px` }"
                      >
                        <strong><code>{{ gitNodeTooltip.node.short_hash }}</code> {{ gitNodeTooltip.node.subject || "-" }}</strong>
                        <small>{{ t("projectInfo.gitGraphTooltipAuthor") }}: {{ gitNodeTooltip.node.author_name || t("projectInfo.unknownAuthor") }}</small>
                        <small>{{ t("projectInfo.gitGraphTooltipDate") }}: {{ formatDateTime(gitNodeTooltip.node.authored_at) }}</small>
                        <small>{{ t("projectInfo.gitGraphTooltipBranch") }}: {{ resolveGraphNodeBranchLabel(gitNodeTooltip.node) }}</small>
                      </div>
                    </div>

                    <div class="project-info-git-graph-node-list">
                      <h5>{{ t("projectInfo.gitGraphNodeListTitle") }}</h5>
                      <div class="project-info-git-graph-node-rows">
                        <article
                          v-for="node in gitGraphNodePreview"
                          :key="`graph-row-${node.id}`"
                          class="project-info-git-graph-node-row"
                        >
                          <i class="project-info-git-graph-node-dot" :style="{ background: node.color }" />
                          <div>
                            <strong><code>{{ node.short_hash }}</code> {{ node.subject }}</strong>
                            <small>
                              {{ resolveGraphNodeBranchLabel(node) }} - {{ formatDateTime(node.authored_at) }}
                            </small>
                          </div>
                        </article>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <p v-if="filteredGitCommits.length === 0" class="muted-text">{{ t("projectInfo.gitHistoryEmpty") }}</p>
              <div v-else class="project-info-history-list">
                <article v-for="commit in filteredGitCommits" :key="commit.hash" class="project-info-history-row">
                  <div class="project-info-history-row-head">
                    <header>
                      <strong><code>{{ commit.short_hash }}</code> {{ commit.subject }}</strong>
                    </header>
                    <div v-if="canMutateGitState" class="project-info-history-row-actions">
                      <button
                        class="btn btn-sm btn-ghost"
                        type="button"
                        :disabled="gitActionBusy || gitWorktreeDirty"
                        @click="branchCommitAndOpen(commit)"
                      >
                        {{ t("projectInfo.gitActionBranchAndOpen") }}
                      </button>
                      <button
                        class="btn btn-sm btn-ghost"
                        type="button"
                        :disabled="gitActionBusy || gitWorktreeDirty"
                        @click="checkoutCommitAndOpen(commit)"
                      >
                        {{ t("projectInfo.gitActionCheckoutCommit") }}
                      </button>
                    </div>
                  </div>
                  <small>
                    {{ commit.author_name }} - {{ formatDateTime(commit.authored_at) }}
                  </small>
                </article>
              </div>
            </template>
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
import ProjectTasksKanban from "../components/ProjectTasksKanban.vue";

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const session = ref(getSession());
const loading = ref(false);
const settingsBusy = ref(false);
const collaboratorBusy = ref(false);
const gitActionBusy = ref(false);
const notice = ref("");
const error = ref("");

const payload = ref(null);
const participants = ref([]);
const periodDays = ref(30);
const commitLimit = ref(200);
const gitGraphCanvasWrapRef = ref(null);
const gitNodeTooltip = reactive({
  visible: false,
  x: 10,
  y: 10,
  node: null,
});

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
  contributors: [],
});
const history = computed(() => payload.value?.history || {
  git: {
    available: false,
    commits: [],
    error: "",
    has_changes: false,
    tree_available: false,
    tree_error: "",
    branch_tree: [],
  },
});
const canManageSettings = computed(() => Boolean(permissions.value?.can_manage_settings));
const canManageParticipants = computed(() => Boolean(permissions.value?.can_manage_participants));
const canMutateGitState = computed(() => Boolean(permissions.value?.can_write_project) && Boolean(history.value?.git?.available));
const gitWorktreeDirty = computed(() => Boolean(history.value?.git?.has_changes));
const historyAuthorFilter = ref("all");
const gitGraphBranchFilter = ref("all");
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
    }))
    .filter((point) => point.date !== "");

  if (points.length === 0) {
    return [];
  }

  const maxValue = points.reduce((max, point) => Math.max(max, point.commits), 0);
  const normalizedMax = maxValue > 0 ? maxValue : 1;

  return points.map((point) => ({
    ...point,
    commitsHeight: point.commits > 0
      ? Math.max(8, Math.round((point.commits / normalizedMax) * 100))
      : 0,
  }));
});

const hasGitTreeData = computed(() => Boolean(history.value?.git?.tree_available));

const gitBranchTree = computed(() => {
  return Array.isArray(history.value?.git?.branch_tree) ? history.value.git.branch_tree : [];
});

const filteredGitBranchTree = computed(() => {
  const filterKey = String(historyAuthorFilter.value || "all");

  return gitBranchTree.value
    .map((branch) => {
      const branchName = String(branch?.name || "").trim();
      const dateGroups = Array.isArray(branch?.dates) ? branch.dates : [];
      const filteredDates = dateGroups
        .map((dateGroup) => {
          const commits = Array.isArray(dateGroup?.commits) ? dateGroup.commits : [];
          const filteredCommits = filterKey === "all"
            ? commits
            : commits.filter((commit) => resolveCommitContributorKey(commit) === filterKey);

          return {
            date: String(dateGroup?.date || ""),
            count: filteredCommits.length,
            commits: filteredCommits,
          };
        })
        .filter((dateGroup) => dateGroup.count > 0);

      return {
        name: branchName,
        head_hash: String(branch?.head_hash || "").trim(),
        is_default: Boolean(branch?.is_default),
        is_current: Boolean(branch?.is_current),
        commit_count: filteredDates.reduce((sum, item) => sum + Number(item.count || 0), 0),
        dates: filteredDates,
      };
    })
    .filter((branch) => {
      if (filterKey === "all") {
        return branch.name !== "";
      }

      return branch.name !== "" && branch.commit_count > 0;
    });
});

const gitGraphPalette = [
  "#5a8bc6",
  "#d97706",
  "#0f766e",
  "#7c3aed",
  "#be123c",
  "#0e7490",
  "#65a30d",
  "#f97316",
];

const gitGraphBranches = computed(() => {
  if (!hasGitTreeData.value) {
    return [];
  }

  const branchInput = filteredGitBranchTree.value;
  if (!Array.isArray(branchInput) || branchInput.length === 0) {
    return [];
  }

  return branchInput
    .map((branch) => ({
      name: String(branch?.name || "").trim(),
      head_hash: String(branch?.head_hash || "").trim(),
      is_default: Boolean(branch?.is_default),
      is_current: Boolean(branch?.is_current),
      commit_count: Math.max(0, Number(branch?.commit_count || 0)),
      dates: Array.isArray(branch?.dates) ? branch.dates : [],
    }))
    .filter((branch) => branch.name !== "")
    .sort((left, right) => {
      const leftPriority = (left.is_default ? 4 : 0) + (left.is_current ? 2 : 0);
      const rightPriority = (right.is_default ? 4 : 0) + (right.is_current ? 2 : 0);
      if (leftPriority !== rightPriority) {
        return rightPriority - leftPriority;
      }

      if (left.commit_count !== right.commit_count) {
        return right.commit_count - left.commit_count;
      }

      return left.name.localeCompare(right.name);
    })
    .map((branch, index) => ({
      ...branch,
      index,
      color: gitGraphPalette[index % gitGraphPalette.length],
    }));
});

const gitGraphActiveBranchName = computed(() => {
  const selectedBranchName = String(gitGraphBranchFilter.value || "all").trim();
  if (selectedBranchName === "all") {
    return "all";
  }

  return gitGraphBranches.value.some((branch) => branch.name === selectedBranchName)
    ? selectedBranchName
    : "all";
});

const gitGraphVisibleBranches = computed(() => {
  const visibleBranches = gitGraphActiveBranchName.value === "all"
    ? gitGraphBranches.value
    : gitGraphBranches.value.filter((branch) => branch.name === gitGraphActiveBranchName.value);

  return visibleBranches.map((branch, index) => ({
    ...branch,
    index,
  }));
});

const gitVisualGraph = computed(() => {
  if (!hasGitTreeData.value) {
    return {
      ready: false,
      branches: [],
      nodes: [],
      edges: [],
      width: 0,
      height: 0,
      laneTop: 0,
      laneBottom: 0,
      renderWidth: 0,
    };
  }

  const branches = gitGraphVisibleBranches.value;

  if (branches.length === 0) {
    return {
      ready: false,
      branches: [],
      nodes: [],
      edges: [],
      width: 0,
      height: 0,
      laneTop: 0,
      laneBottom: 0,
      renderWidth: 0,
    };
  }

  const perBranchCommitLimit = 48;
  const totalNodeLimit = 260;
  const commitMap = new Map();

  branches.forEach((branch) => {
    const seenHashes = new Set();
    let branchCommitCount = 0;

    branch.dates.forEach((dateGroup) => {
      const commits = Array.isArray(dateGroup?.commits) ? dateGroup.commits : [];
      commits.forEach((commit) => {
        const hash = String(commit?.hash || "").trim();
        if (!hash || seenHashes.has(hash) || branchCommitCount >= perBranchCommitLimit) {
          return;
        }
        seenHashes.add(hash);
        branchCommitCount += 1;

        const authoredAt = String(commit?.authored_at || "").trim();
        const timestamp = Date.parse(authoredAt);
        const authoredTs = Number.isFinite(timestamp) ? timestamp : 0;
        const parents = Array.isArray(commit?.parents)
          ? commit.parents
            .map((parent) => String(parent || "").trim())
            .filter((parent) => parent !== "")
          : [];
        const existing = commitMap.get(hash);
        if (existing) {
          existing.branch_name_set.add(branch.name);
          if (branch.head_hash !== "" && branch.head_hash === hash) {
            existing.head_branch_name_set.add(branch.name);
          }
          return;
        }

        commitMap.set(hash, {
          hash,
          short_hash: String(commit?.short_hash || "").trim() || hash.slice(0, 7),
          subject: String(commit?.subject || "").trim(),
          author_name: String(commit?.author_name || "").trim(),
          authored_at: authoredAt,
          authored_ts: authoredTs,
          parents,
          branch_name_set: new Set([branch.name]),
          head_branch_name_set: branch.head_hash !== "" && branch.head_hash === hash
            ? new Set([branch.name])
            : new Set(),
        });
      });
    });
  });

  const branchOrder = branches.map((branch) => branch.name);
  const branchIndexByName = new Map(branches.map((branch) => [branch.name, branch.index]));
  const branchColorByName = new Map(branches.map((branch) => [branch.name, branch.color]));

  const nodes = Array.from(commitMap.values())
    .map((node) => {
      const orderedBranchNames = branchOrder.filter((branchName) => node.branch_name_set.has(branchName));
      const orderedHeadBranchNames = branchOrder.filter((branchName) => node.head_branch_name_set.has(branchName));
      const laneBranchName = orderedHeadBranchNames[0] || orderedBranchNames[0] || "";
      const laneIndex = Number(branchIndexByName.get(laneBranchName) ?? 0);
      const color = branchColorByName.get(laneBranchName) || branches[0]?.color || gitGraphPalette[0];

      return {
        ...node,
        id: node.hash,
        lane_branch_name: laneBranchName,
        lane_index: laneIndex,
        branch_names: orderedBranchNames,
        head_branch_names: orderedHeadBranchNames,
        color,
      };
    })
    .sort((left, right) => {
      if (left.authored_ts !== right.authored_ts) {
        return right.authored_ts - left.authored_ts;
      }
      if (left.lane_index !== right.lane_index) {
        return left.lane_index - right.lane_index;
      }
      return left.hash.localeCompare(right.hash);
    })
    .slice(0, totalNodeLimit);

  if (nodes.length === 0) {
    return {
      ready: false,
      branches,
      nodes: [],
      edges: [],
      width: 0,
      height: 0,
      laneTop: 0,
      laneBottom: 0,
      renderWidth: 0,
    };
  }

  const laneGap = 76;
  const rowGap = 36;
  const padX = 32;
  const padY = 20;
  const laneSpan = Math.max(0, (branches.length - 1) * laneGap);
  const width = Math.max(280, (padX * 2) + laneSpan + 32);
  const height = Math.max(180, (padY * 2) + ((nodes.length - 1) * rowGap) + 28);
  const laneStartX = (width / 2) - (laneSpan / 2);
  const laneTop = Math.max(8, padY - 10);
  const laneBottom = height - 14;

  const indexedNodes = nodes.map((node, rowIndex) => {
    return {
      ...node,
      x: laneStartX + (node.lane_index * laneGap),
      y: padY + (rowIndex * rowGap),
    };
  });

  const nodeByHash = new Map(indexedNodes.map((node) => [node.hash, node]));

  const edgeKeys = new Set();
  const edges = [];

  indexedNodes.forEach((node) => {
    const parentHashes = Array.isArray(node.parents) ? node.parents : [];
    parentHashes.forEach((parentHashRaw, parentIndex) => {
      const parentHash = String(parentHashRaw || "").trim();
      if (!parentHash) {
        return;
      }

      const parent = nodeByHash.get(parentHash) || null;
      if (!parent) {
        return;
      }

      const edgeKey = `${node.id}:${parent.id}:${parentIndex}`;
      if (edgeKeys.has(edgeKey)) {
        return;
      }
      edgeKeys.add(edgeKey);

      if (Math.abs(node.x - parent.x) <= 1) {
        edges.push({
          key: edgeKey,
          color: parentIndex === 0 ? node.color : parent.color,
          dashed: parentIndex > 0 || parent.lane_branch_name !== node.lane_branch_name,
          path: `M ${node.x} ${node.y} L ${parent.x} ${parent.y}`,
        });
        return;
      }

      const deltaY = parent.y - node.y;
      const cp1y = node.y + (deltaY * 0.36);
      const cp2y = node.y + (deltaY * 0.64);
      edges.push({
        key: edgeKey,
        color: parentIndex === 0 ? node.color : parent.color,
        dashed: parentIndex > 0 || parent.lane_branch_name !== node.lane_branch_name,
        path: `M ${node.x} ${node.y} C ${node.x} ${cp1y}, ${parent.x} ${cp2y}, ${parent.x} ${parent.y}`,
      });
    });
  });

  return {
    ready: true,
    branches: branches.map((branch) => ({
      ...branch,
      x: laneStartX + (branch.index * laneGap),
    })),
    nodes: indexedNodes,
    edges,
    width,
    height,
    laneTop,
    laneBottom,
    renderWidth: width,
  };
});

const gitGraphNodePreview = computed(() => {
  if (!gitVisualGraph.value.ready) {
    return [];
  }

  return gitVisualGraph.value.nodes.slice(0, 60);
});

const filteredGitCommits = computed(() => {
  const commits = Array.isArray(history.value?.git?.commits) ? history.value.git.commits : [];
  const filterKey = String(historyAuthorFilter.value || "all");
  if (filterKey === "all") {
    return commits;
  }

  return commits.filter((commit) => resolveCommitContributorKey(commit) === filterKey);
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

function formatGitTreeDate(value) {
  const raw = String(value || "").trim();
  if (!raw || raw === "unknown") {
    return t("projectInfo.gitTreeDateUnknown");
  }

  return formatShortDate(raw);
}

function buildBranchNameSuggestion(commit) {
  const shortHash = String(commit?.short_hash || "head").trim() || "head";
  const subjectSlug = String(commit?.subject || "")
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 32);

  if (subjectSlug !== "") {
    return `${subjectSlug}-${shortHash}`;
  }

  return `branch-${shortHash}`;
}

function readGitActionError(errorInput) {
  const backendMessage = String(errorInput?.data?.message || "").trim();
  if (backendMessage === "Commit or stash local changes before switching project Git state.") {
    return t("projectInfo.gitActionDirtyHint");
  }

  if (backendMessage === "Git repository is not initialized for this project.") {
    return t("projectInfo.gitActionRepoMissing");
  }

  return readError(errorInput);
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

function resolveGraphNodeBranchLabel(node) {
  const labels = Array.isArray(node?.branch_names)
    ? node.branch_names
      .map((item) => String(item || "").trim())
      .filter((item) => item !== "")
    : [];

  if (labels.length === 0) {
    return "-";
  }

  if (labels.length <= 2) {
    return labels.join(", ");
  }

  return `${labels.slice(0, 2).join(", ")} +${labels.length - 2}`;
}

function resolveGitNodeTooltipPosition(event) {
  const host = gitGraphCanvasWrapRef.value;
  if (!host) {
    return { x: 10, y: 10 };
  }

  const rect = host.getBoundingClientRect();
  const tooltipWidth = 290;
  const tooltipHeight = 118;
  const rawX = (Number(event?.clientX || rect.left) - rect.left) + host.scrollLeft + 12;
  const rawY = (Number(event?.clientY || rect.top) - rect.top) + host.scrollTop + 12;
  const maxX = Math.max(10, host.scrollWidth - tooltipWidth - 10);
  const maxY = Math.max(10, host.scrollHeight - tooltipHeight - 10);

  return {
    x: Math.min(Math.max(10, Math.round(rawX)), maxX),
    y: Math.min(Math.max(10, Math.round(rawY)), maxY),
  };
}

function toggleGitGraphBranch(branchName) {
  const normalizedBranchName = String(branchName || "").trim();
  if (normalizedBranchName === "") {
    gitGraphBranchFilter.value = "all";
    hideGitNodeTooltip();
    return;
  }

  gitGraphBranchFilter.value = gitGraphActiveBranchName.value === normalizedBranchName
    ? "all"
    : normalizedBranchName;
  hideGitNodeTooltip();
}

function showGitNodeTooltip(event, node) {
  if (!node) {
    return;
  }

  const position = resolveGitNodeTooltipPosition(event);
  gitNodeTooltip.visible = true;
  gitNodeTooltip.x = position.x;
  gitNodeTooltip.y = position.y;
  gitNodeTooltip.node = node;
}

function moveGitNodeTooltip(event) {
  if (!gitNodeTooltip.visible || !gitNodeTooltip.node) {
    return;
  }

  const position = resolveGitNodeTooltipPosition(event);
  gitNodeTooltip.x = position.x;
  gitNodeTooltip.y = position.y;
}

function hideGitNodeTooltip() {
  gitNodeTooltip.visible = false;
  gitNodeTooltip.node = null;
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

async function openBranchInEditor(branch) {
  if (!projectId.value) {
    return;
  }

  const branchName = String(branch?.name || "").trim();
  if (!branchName) {
    return;
  }

  if (branch?.is_current) {
    await openEditor();
    return;
  }

  const confirmed = typeof window === "undefined"
    ? true
    : window.confirm(t("projectInfo.gitActionSwitchBranchConfirm", { name: branchName }));
  if (!confirmed || gitActionBusy.value || !canMutateGitState.value) {
    return;
  }

  gitActionBusy.value = true;
  error.value = "";

  try {
    await request({
      method: "POST",
      path: `/projects/${projectId.value}/git/checkout-branch`,
      auth: true,
      body: {
        branch_name: branchName,
      },
    });

    await openEditor();
  } catch (actionError) {
    error.value = readGitActionError(actionError);
  } finally {
    gitActionBusy.value = false;
  }
}

async function branchCommitAndOpen(commit) {
  if (!projectId.value || gitActionBusy.value || !canMutateGitState.value) {
    return;
  }

  const commitHash = String(commit?.hash || "").trim();
  if (!commitHash) {
    return;
  }

  const suggestedName = buildBranchNameSuggestion(commit);
  const branchName = typeof window === "undefined"
    ? suggestedName
    : window.prompt(
      t("projectInfo.gitActionBranchPrompt", {
        hash: String(commit?.short_hash || "").trim() || commitHash.slice(0, 7),
      }),
      suggestedName,
    );

  const normalizedBranchName = String(branchName || "").trim();
  if (!normalizedBranchName) {
    return;
  }

  gitActionBusy.value = true;
  error.value = "";

  try {
    await request({
      method: "POST",
      path: `/projects/${projectId.value}/git/branch`,
      auth: true,
      body: {
        branch_name: normalizedBranchName,
        commit_hash: commitHash,
      },
    });

    await openEditor();
  } catch (actionError) {
    error.value = readGitActionError(actionError);
  } finally {
    gitActionBusy.value = false;
  }
}

async function checkoutCommitAndOpen(commit) {
  if (!projectId.value || gitActionBusy.value || !canMutateGitState.value) {
    return;
  }

  const commitHash = String(commit?.hash || "").trim();
  if (!commitHash) {
    return;
  }

  const shortHash = String(commit?.short_hash || "").trim() || commitHash.slice(0, 7);
  const confirmed = typeof window === "undefined"
    ? true
    : window.confirm(t("projectInfo.gitActionCheckoutConfirm", { hash: shortHash }));
  if (!confirmed) {
    return;
  }

  gitActionBusy.value = true;
  error.value = "";

  try {
    await request({
      method: "POST",
      path: `/projects/${projectId.value}/git/checkout-commit`,
      auth: true,
      body: {
        commit_hash: commitHash,
      },
    });

    await openEditor();
  } catch (actionError) {
    error.value = readGitActionError(actionError);
  } finally {
    gitActionBusy.value = false;
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

