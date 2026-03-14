<template>
  <div v-if="open" class="modal-overlay" @click.self="emit('close')">
    <section class="card project-settings-modal" role="dialog" aria-modal="true" :aria-label="t('editor.projectSettings')">
      <div class="sidebar-head">
        <h2>{{ t("editor.projectSettings") }}</h2>
        <button class="btn btn-sm btn-ghost" type="button" :disabled="saving || deleting" @click="emit('close')">x</button>
      </div>

      <div class="project-settings-tabs" role="tablist" :aria-label="t('editor.projectSettings')">
        <button
          class="project-settings-tab"
          :class="{ 'is-active': activeTab === 'settings' }"
          type="button"
          @click="activeTab = 'settings'"
        >
          {{ t("editor.projectSettingsTabSettings") }}
        </button>
        <button
          class="project-settings-tab"
          :class="{ 'is-active': activeTab === 'stats' }"
          type="button"
          @click="openStatsTab"
        >
          {{ t("editor.projectSettingsTabStats") }}
        </button>
        <button
          class="project-settings-tab"
          :class="{ 'is-active': activeTab === 'testing' }"
          type="button"
          @click="openTestingTab"
        >
          {{ t("editor.projectSettingsTabTesting") }}
        </button>
      </div>

      <section v-if="activeTab === 'settings'" class="project-settings-pane">
        <form class="form-grid compact-form" @submit.prevent="submitSettings">
          <label class="field field-row">
            <span>{{ t("editor.projectName") }}</span>
            <input v-model.trim="form.name" type="text" maxlength="255" required />
          </label>

          <label class="field field-row">
            <span>{{ t("editor.projectDescription") }}</span>
            <input v-model.trim="form.description" type="text" maxlength="500" :placeholder="t('projects.projectDescription')" />
          </label>

          <label class="field field-row">
            <span>{{ t("editor.projectVisibility") }}</span>
            <select v-model="form.visibility">
              <option value="private">{{ t("editor.visibilityPrivate") }}</option>
              <option value="public">{{ t("editor.visibilityPublic") }}</option>
            </select>
          </label>

          <slot name="extra-fields" />

          <div class="project-settings-modal-actions">
            <button class="btn" type="submit" :disabled="saving || deleting">
              {{ saving ? t("common.saving") : t("editor.saveProjectSettings") }}
            </button>
            <button class="btn btn-ghost" type="button" :disabled="saving || deleting" @click="emit('close')">
              {{ t("editor.cancel") }}
            </button>
          </div>
        </form>

        <section v-if="canDelete" class="project-settings-danger">
          <div>
            <h3>{{ t("editor.projectDeleteTitle") }}</h3>
            <p class="muted-text">{{ t("editor.projectDeleteHint") }}</p>
          </div>

          <div v-if="deleteStage === 0" class="project-settings-danger-actions">
            <button class="btn btn-danger" type="button" :disabled="saving || deleting" @click="startDeleteFlow">
              {{ t("editor.projectDeleteAction") }}
            </button>
          </div>

          <div v-else-if="deleteStage === 1" class="project-settings-delete-confirm">
            <p class="warning-banner">{{ t("editor.projectDeleteConfirmStepOne") }}</p>
            <div class="project-settings-danger-actions">
              <button class="btn btn-danger" type="button" :disabled="saving || deleting" @click="goToDeleteStepTwo">
                {{ t("editor.projectDeleteContinue") }}
              </button>
              <button class="btn btn-ghost" type="button" :disabled="saving || deleting" @click="resetDeleteFlow">
                {{ t("editor.cancel") }}
              </button>
            </div>
          </div>

          <div v-else class="project-settings-delete-confirm">
            <p class="warning-banner">{{ t("editor.projectDeleteConfirmStepTwo", { name: projectName }) }}</p>
            <label class="field field-row">
              <span>{{ t("editor.projectDeleteTypeName") }}</span>
              <input v-model.trim="deleteNameInput" type="text" maxlength="255" :disabled="saving || deleting" />
            </label>
            <div class="project-settings-danger-actions">
              <button
                class="btn btn-danger"
                type="button"
                :disabled="saving || deleting || !isDeleteNameMatch"
                @click="emit('delete')"
              >
                {{ deleting ? t("common.saving") : t("editor.projectDeleteFinal") }}
              </button>
              <button class="btn btn-ghost" type="button" :disabled="saving || deleting" @click="resetDeleteFlow">
                {{ t("editor.cancel") }}
              </button>
            </div>
          </div>
        </section>
      </section>

      <section v-else-if="activeTab === 'stats'" class="project-settings-pane">
        <div class="sidebar-head project-settings-pane-head">
          <h3>{{ t("editor.projectStatsTitle") }}</h3>
          <div class="project-settings-pane-actions">
            <label class="field field-row project-info-period-field">
              <span>{{ t("projectInfo.periodLabel") }}</span>
              <select :value="statsPeriodDays" @change="onStatsPeriodChange">
                <option :value="7">7d</option>
                <option :value="30">30d</option>
                <option :value="90">90d</option>
              </select>
            </label>
            <button class="btn btn-sm btn-ghost" type="button" :disabled="statsLoading" @click="emit('refresh-stats')">
              {{ statsLoading ? t("common.loading") : t("common.refresh") }}
            </button>
          </div>
        </div>

        <p class="muted-text">
          {{ t("editor.projectStatsSummary", { commits: safeStats.total_commits }) }}
        </p>

        <p v-if="statsLoading" class="muted-text">{{ t("editor.projectStatsLoading") }}</p>
        <p v-else-if="statsError" class="error-banner">{{ statsError }}</p>

        <template v-else>
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

          <div v-if="safeStats.contributors.length === 0" class="muted-text">{{ t("projectInfo.noStats") }}</div>
          <div v-else class="project-info-stats-table">
            <div v-for="entry in safeStats.contributors" :key="entry.key" class="project-info-stats-row">
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
        </template>
      </section>

      <section v-else class="project-settings-pane">
        <div class="sidebar-head project-settings-pane-head">
          <h3>{{ t("editor.projectTestingTitle") }}</h3>
          <div class="project-settings-pane-actions">
            <button class="btn btn-sm btn-secondary" type="button" :disabled="!hasTestingSource" @click="runBigOtest">
              {{ t("editor.projectTestingRunBigO") }}
            </button>
            <button class="btn btn-sm btn-ghost" type="button" :disabled="!analysisReady" @click="resetBigOtest">
              {{ t("editor.projectTestingReset") }}
            </button>
          </div>
        </div>

        <p class="muted-text">{{ t("editor.projectTestingHint") }}</p>
        <p class="muted-text">{{ testingSelectionMessage }}</p>

        <label class="field">
          <span>{{ t("editor.projectTestingCodeLabel") }}</span>
          <textarea
            v-model="testingCode"
            class="project-testing-input"
            rows="8"
            :placeholder="t('editor.projectTestingCodePlaceholder')"
          />
        </label>

        <div class="project-testing-meta">
          <small><strong>{{ t("editor.projectTestingLanguage") }}:</strong> <code>{{ selectedCodeLanguage || "-" }}</code></small>
          <small><strong>{{ t("editor.projectTestingPath") }}:</strong> <code>{{ selectedCodePath || "-" }}</code></small>
        </div>

        <div v-if="analysisReady" class="project-testing-grid">
          <article class="project-testing-card">
            <h4>{{ t("editor.projectTestingComplexityTitle") }}</h4>
            <p class="project-testing-complexity-line">
              <strong>{{ t("editor.projectTestingTime") }}:</strong>
              <code>{{ analysisResult.timeComplexity }}</code>
            </p>
            <p class="project-testing-complexity-line">
              <strong>{{ t("editor.projectTestingSpace") }}:</strong>
              <code>{{ analysisResult.spaceComplexity }}</code>
            </p>
            <p class="project-testing-complexity-line">
              <strong>{{ t("editor.projectTestingConfidence") }}:</strong>
              {{ analysisResult.confidence }}%
            </p>
            <ul class="project-testing-list">
              <li v-for="item in analysisResult.signals" :key="item">{{ item }}</li>
            </ul>
          </article>

          <article class="project-testing-card">
            <h4>{{ t("editor.projectTestingWhiteBoxTitle") }}</h4>
            <p class="project-testing-complexity-line">
              <strong>{{ t("editor.projectTestingCyclomatic") }}:</strong>
              {{ whiteBoxChecklist.cyclomaticComplexity }}
            </p>
            <p class="project-testing-complexity-line">
              <strong>{{ t("editor.projectTestingMinCases") }}:</strong>
              {{ whiteBoxChecklist.recommendedMinTests }}
            </p>
            <ul class="project-testing-list">
              <li v-for="item in whiteBoxChecklist.checks" :key="item">{{ item }}</li>
            </ul>
          </article>

          <article class="project-testing-card">
            <h4>{{ t("editor.projectTestingBlackBoxTitle") }}</h4>
            <ul class="project-testing-list">
              <li v-for="scenario in blackBoxScenarios" :key="`${scenario.type}-${scenario.input}`">
                <strong>{{ scenario.input }}</strong><br />
                <small>{{ scenario.expectation }}</small>
              </li>
            </ul>
          </article>

          <article class="project-testing-card">
            <h4>{{ t("editor.projectTestingAutomationTitle") }}</h4>
            <p class="project-testing-complexity-line"><strong>{{ t("editor.projectTestingWhiteBoxTitle") }}</strong></p>
            <ul class="project-testing-list">
              <li v-for="command in automationCommands.whiteBox" :key="`wb-${command}`"><code>{{ command }}</code></li>
            </ul>
            <p class="project-testing-complexity-line"><strong>{{ t("editor.projectTestingBlackBoxTitle") }}</strong></p>
            <ul class="project-testing-list">
              <li v-for="command in automationCommands.blackBox" :key="`bb-${command}`"><code>{{ command }}</code></li>
            </ul>
            <p class="project-testing-complexity-line">
              <strong>{{ t("editor.projectTestingCoverage") }}:</strong>
              <code>{{ automationCommands.coverage }}</code>
            </p>
          </article>
        </div>

        <p v-else class="muted-text">{{ t("editor.projectTestingNoResult") }}</p>
      </section>
    </section>
  </div>
</template>

<script setup>
import { computed, defineEmits, defineProps, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import {
  analyzeCodeComplexity,
  buildAutomationCommands,
  buildBlackBoxScenarios,
  buildWhiteBoxChecklist,
} from "../services/bigOtest";

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  project: {
    type: Object,
    default: null,
  },
  saving: {
    type: Boolean,
    default: false,
  },
  deleting: {
    type: Boolean,
    default: false,
  },
  canDelete: {
    type: Boolean,
    default: false,
  },
  stats: {
    type: Object,
    default: null,
  },
  statsLoading: {
    type: Boolean,
    default: false,
  },
  statsError: {
    type: String,
    default: "",
  },
  statsPeriodDays: {
    type: Number,
    default: 30,
  },
  selectedCode: {
    type: String,
    default: "",
  },
  selectedCodeLanguage: {
    type: String,
    default: "text",
  },
  selectedCodePath: {
    type: String,
    default: "",
  },
});

const emit = defineEmits(["close", "save", "delete", "update:stats-period-days", "refresh-stats"]);
const { t } = useI18n();

const activeTab = ref("settings");
const testingCode = ref("");
const analysisResult = ref(null);

const form = reactive({
  name: "",
  description: "",
  visibility: "private",
});

const deleteStage = ref(0);
const deleteNameInput = ref("");

const projectName = computed(() => String(props.project?.name || "").trim());
const isDeleteNameMatch = computed(() => {
  if (!projectName.value) {
    return false;
  }

  return deleteNameInput.value.trim() === projectName.value;
});

const safeStats = computed(() => {
  const source = props.stats && typeof props.stats === "object" ? props.stats : {};

  return {
    total_commits: Math.max(0, Number(source.total_commits || 0)),
    contributors: Array.isArray(source.contributors) ? source.contributors : [],
    timeline: Array.isArray(source.timeline) ? source.timeline : [],
  };
});

const statsTimeline = computed(() => {
  const points = safeStats.value.timeline
    .map((point) => ({
      date: String(point?.date || ""),
      commits: Math.max(0, Number(point?.commits || 0)),
    }))
    .filter((point) => point.date !== "");

  if (points.length === 0) {
    return [];
  }

  const maxValue = points.reduce((max, point) => Math.max(max, point.commits), 0);
  const normalizedMax = maxValue > 0 ? maxValue : 1;

  return points.map((point) => ({
    ...point,
    commitsHeight: point.commits > 0 ? Math.max(8, Math.round((point.commits / normalizedMax) * 100)) : 0,
  }));
});

const hasTestingSource = computed(() => testingCode.value.trim() !== "");
const analysisReady = computed(() => Boolean(analysisResult.value && !analysisResult.value.empty));
const whiteBoxChecklist = computed(() => buildWhiteBoxChecklist(analysisResult.value, { t }));
const blackBoxScenarios = computed(() => buildBlackBoxScenarios(testingCode.value, { t }));
const automationCommands = computed(() => buildAutomationCommands({
  language: props.selectedCodeLanguage,
  filePath: props.selectedCodePath,
  t,
}));
const testingSelectionMessage = computed(() => {
  if (String(props.selectedCode || "").trim() !== "") {
    return t("editor.projectTestingSelectionLoaded");
  }

  return t("editor.projectTestingSelectionMissing");
});

function applyProjectToForm() {
  form.name = String(props.project?.name || "").trim();
  form.description = String(props.project?.description || "");
  form.visibility = props.project?.is_public ? "public" : "private";
}

function syncTestingCodeFromSelection() {
  testingCode.value = String(props.selectedCode || "");
}

function resetDeleteFlow() {
  deleteStage.value = 0;
  deleteNameInput.value = "";
}

function startDeleteFlow() {
  deleteStage.value = 1;
}

function goToDeleteStepTwo() {
  deleteStage.value = 2;
}

function submitSettings() {
  emit("save", {
    name: form.name.trim(),
    description: form.description.trim(),
    visibility: form.visibility === "public" ? "public" : "private",
  });
}

function openStatsTab() {
  activeTab.value = "stats";
  emit("refresh-stats");
}

function openTestingTab() {
  activeTab.value = "testing";
  if (!analysisReady.value && hasTestingSource.value) {
    runBigOtest();
  }
}

function onStatsPeriodChange(event) {
  const nextValue = Number(event?.target?.value || props.statsPeriodDays || 30);
  emit("update:stats-period-days", nextValue);
}

function runBigOtest() {
  analysisResult.value = analyzeCodeComplexity(testingCode.value, {
    language: props.selectedCodeLanguage,
    filePath: props.selectedCodePath,
    t,
  });
}

function resetBigOtest() {
  analysisResult.value = null;
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

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) {
      activeTab.value = "settings";
      resetDeleteFlow();
      analysisResult.value = null;
      return;
    }

    applyProjectToForm();
    syncTestingCodeFromSelection();
    analysisResult.value = null;
    resetDeleteFlow();
  },
  { immediate: true },
);

watch(
  () => Number(props.project?.project_id || 0),
  () => {
    if (props.open) {
      applyProjectToForm();
      resetDeleteFlow();
    }
  },
);

watch(
  () => props.selectedCode,
  () => {
    if (!props.open) {
      testingCode.value = String(props.selectedCode || "");
      return;
    }

    if (activeTab.value !== "testing") {
      return;
    }

    testingCode.value = String(props.selectedCode || "");
    analysisResult.value = null;
  },
);
</script>
