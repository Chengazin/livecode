<template>
  <div v-if="open" class="modal-overlay" @click.self="emit('close')">
    <section class="card project-settings-modal" role="dialog" aria-modal="true" :aria-label="t('editor.projectSettings')">
      <div class="sidebar-head">
        <h2>{{ t("editor.projectSettings") }}</h2>
        <button class="btn btn-sm btn-ghost" type="button" :disabled="saving || deleting" @click="emit('close')">x</button>
      </div>

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
  </div>
</template>

<script setup>
import { computed, defineEmits, defineProps, reactive, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

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
});

const emit = defineEmits(["close", "save", "delete"]);
const { t } = useI18n();

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

function applyProjectToForm() {
  form.name = String(props.project?.name || "").trim();
  form.description = String(props.project?.description || "");
  form.visibility = props.project?.is_public ? "public" : "private";
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

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) {
      resetDeleteFlow();
      return;
    }

    applyProjectToForm();
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
</script>
