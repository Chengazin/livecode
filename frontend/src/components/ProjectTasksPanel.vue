<template>
  <section class="card project-tasks-panel">
    <div class="sidebar-head">
      <h2>{{ t("projectInfo.tasksTitle", "Tasks") }}</h2>
      <div class="sidebar-controls">
        <button
          v-if="canCreateTasks"
          class="btn btn-sm btn-ghost"
          type="button"
          @click="showNewTaskForm = true"
        >
          +
        </button>
        <button
          class="btn btn-sm btn-ghost"
          type="button"
          :disabled="taskLoading"
          @click="loadTasks"
        >
          {{ taskLoading ? t("common.loading") : t("common.refresh") }}
        </button>
      </div>
    </div>

    <!-- Error and Loading States -->
    <p v-if="taskError" class="error-banner">{{ taskError }}</p>
    <div v-if="taskLoading && !tasks.length" class="muted-text">
      {{ t("common.loading") }}
    </div>

    <!-- New Task Form -->
    <form v-if="showNewTaskForm && canCreateTasks" class="form-grid compact-form" @submit.prevent="createNewTask">
      <label class="field">
        <span>{{ t("projectInfo.taskTitle", "Task Title") }}</span>
        <input
          v-model.trim="newTaskForm.title"
          type="text"
          maxlength="255"
          required
          placeholder="Enter task title"
        />
      </label>
      <label class="field">
        <span>{{ t("projectInfo.taskDescription", "Description") }}</span>
        <textarea
          v-model.trim="newTaskForm.description"
          rows="2"
          maxlength="2000"
          placeholder="Enter task description (optional)"
        />
      </label>
      <div class="form-row">
        <label class="field field-row">
          <span>{{ t("projectInfo.taskPriority", "Priority") }}</span>
          <select v-model.number="newTaskForm.priority">
            <option :value="0">Low</option>
            <option :value="1" selected>Medium</option>
            <option :value="2">High</option>
            <option :value="3">Urgent</option>
          </select>
        </label>
        <label class="field field-row">
          <span>{{ t("projectInfo.taskDueDate", "Due Date") }}</span>
          <input v-model="newTaskForm.due_date" type="date" />
        </label>
      </div>
      <div class="form-actions">
        <button class="btn btn-secondary" type="submit" :disabled="newTaskBusy">
          {{ newTaskBusy ? t("common.creating") : t("common.create") }}
        </button>
        <button class="btn btn-ghost" type="button" @click="showNewTaskForm = false">
          {{ t("common.cancel") }}
        </button>
      </div>
    </form>

    <!-- Tasks List -->
    <div v-if="!taskLoading && tasks.length === 0" class="muted-text">
      {{ t("projectInfo.noTasks", "No tasks yet") }}
    </div>

    <div v-else class="project-tasks-list">
      <article
        v-for="task in tasks"
        :key="`task-${task.project_task_id}`"
        class="project-task-item"
        :data-status="task.status"
      >
        <div class="project-task-head">
          <div class="project-task-title">
            <strong>{{ task.title }}</strong>
            <small class="project-task-status" :data-status="task.status">{{ STATUS_LABELS[task.status] }}</small>
            <small class="project-task-priority" :data-priority="task.priority">
              {{ PRIORITY_LABELS[task.priority] }}
            </small>
          </div>
          <div class="project-task-actions">
            <button
              v-if="task.status === 'open' || task.status === 'assigned'"
              class="btn btn-sm btn-ghost"
              type="button"
              :disabled="taskActionBusy"
              @click="startTaskAction(task)"
            >
              Start
            </button>
            <button
              v-if="task.status === 'in_progress'"
              class="btn btn-sm btn-ghost"
              type="button"
              :disabled="taskActionBusy"
              @click="completeTaskAction(task)"
            >
              Complete
            </button>
            <button
              v-if="task.status !== 'closed'"
              class="btn btn-sm btn-ghost"
              type="button"
              :disabled="taskActionBusy"
              @click="closeTaskAction(task)"
            >
              Close
            </button>
            <button
              class="btn btn-sm btn-ghost"
              type="button"
              :disabled="taskActionBusy"
              @click="deleteTaskAction(task)"
            >
              Delete
            </button>
          </div>
        </div>

        <p v-if="task.description" class="project-task-description">{{ task.description }}</p>

        <div class="project-task-meta">
          <div v-if="task.assigned_to_user_id" class="project-task-meta-item">
            <span class="label">{{ t("projectInfo.assignedTo", "Assigned to") }}:</span>
            <span>{{ task.assignedTo?.name || `User #${task.assigned_to_user_id}` }}</span>
          </div>
          <div v-if="task.due_date" class="project-task-meta-item">
            <span class="label">{{ t("projectInfo.dueDate", "Due") }}:</span>
            <span>{{ formatDate(task.due_date) }}</span>
          </div>
          <div v-if="task.started_at" class="project-task-meta-item">
            <span class="label">{{ t("projectInfo.startedAt", "Started") }}:</span>
            <span>{{ formatDateTime(task.started_at) }}</span>
          </div>
          <div v-if="task.completed_at" class="project-task-meta-item">
            <span class="label">{{ t("projectInfo.completedAt", "Completed") }}:</span>
            <span>{{ formatDateTime(task.completed_at) }}</span>
          </div>
        </div>
      </article>
    </div>
  </section>
</template>

<script setup>
import { ref, computed, onMounted, defineProps } from 'vue';
import { useI18n } from "vue-i18n";
import {
  getTasks,
  createTask,
  startTask,
  completeTask,
  closeTask,
  deleteTask,
  PRIORITY_LABELS,
  STATUS_LABELS,
} from '@/services/tasks';

const { t } = useI18n();

const props = defineProps({
  projectId: {
    type: Number,
    required: true,
  },
  permissions: {
    type: Object,
    default: () => ({
      can_manage_tasks: false,
    }),
  },
});

const tasks = ref([]);
const taskLoading = ref(false);
const taskError = ref('');
const newTaskBusy = ref(false);
const taskActionBusy = ref(false);
const showNewTaskForm = ref(false);

const newTaskForm = ref({
  title: '',
  description: '',
  priority: 1,
  due_date: '',
});

const canCreateTasks = computed(() => {
  return props.permissions.can_manage_tasks || props.permissions.effective_role === 'admin' || props.permissions.effective_role === 'manager';
});

const formatDate = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleDateString();
};

const formatDateTime = (dateTime) => {
  if (!dateTime) return '';
  return new Date(dateTime).toLocaleString();
};

const loadTasks = async () => {
  taskLoading.value = true;
  taskError.value = '';

  try {
    const response = await getTasks(props.projectId);
    // response.data is the full response object with {success, data, pagination}
    if (response?.data?.data) {
      tasks.value = response.data.data;
    }
  } catch (error) {
    taskError.value = error?.message || 'Failed to load tasks';
    console.error('Failed to load tasks:', error);
  } finally {
    taskLoading.value = false;
  }
};

const createNewTask = async () => {
  newTaskBusy.value = true;
  taskError.value = '';

  try {
    const response = await createTask(props.projectId, {
      title: newTaskForm.value.title,
      description: newTaskForm.value.description || null,
      priority: newTaskForm.value.priority,
      due_date: newTaskForm.value.due_date || null,
    });

    if (response?.data?.data) {
      tasks.value.push(response.data.data);
      showNewTaskForm.value = false;
      newTaskForm.value = {
        title: '',
        description: '',
        priority: 1,
        due_date: '',
      };
    }
  } catch (error) {
    taskError.value = error?.message || 'Failed to create task';
    console.error('Failed to create task:', error);
  } finally {
    newTaskBusy.value = false;
  }
};

const startTaskAction = async (task) => {
  taskActionBusy.value = true;
  taskError.value = '';

  try {
    const response = await startTask(props.projectId, task.project_task_id);
    if (response?.data?.data) {
      const index = tasks.value.findIndex(t => t.project_task_id === task.project_task_id);
      if (index !== -1) {
        tasks.value[index] = response.data.data;
      }
    }
  } catch (error) {
    taskError.value = error?.message || 'Failed to start task';
    console.error('Failed to start task:', error);
  } finally {
    taskActionBusy.value = false;
  }
};

const completeTaskAction = async (task) => {
  taskActionBusy.value = true;
  taskError.value = '';

  try {
    const response = await completeTask(props.projectId, task.project_task_id);
    if (response?.data?.data) {
      const index = tasks.value.findIndex(t => t.project_task_id === task.project_task_id);
      if (index !== -1) {
        tasks.value[index] = response.data.data;
      }
    }
  } catch (error) {
    taskError.value = error?.message || 'Failed to complete task';
    console.error('Failed to complete task:', error);
  } finally {
    taskActionBusy.value = false;
  }
};

const closeTaskAction = async (task) => {
  taskActionBusy.value = true;
  taskError.value = '';

  try {
    const response = await closeTask(props.projectId, task.project_task_id);
    if (response?.data?.data) {
      const index = tasks.value.findIndex(t => t.project_task_id === task.project_task_id);
      if (index !== -1) {
        tasks.value[index] = response.data.data;
      }
    }
  } catch (error) {
    taskError.value = error?.message || 'Failed to close task';
    console.error('Failed to close task:', error);
  } finally {
    taskActionBusy.value = false;
  }
};

const deleteTaskAction = async (task) => {
  if (!confirm('Are you sure you want to delete this task?')) return;

  taskActionBusy.value = true;
  taskError.value = '';

  try {
    await deleteTask(props.projectId, task.project_task_id);
    tasks.value = tasks.value.filter(t => t.project_task_id !== task.project_task_id);
  } catch (error) {
    taskError.value = error?.message || 'Failed to delete task';
    console.error('Failed to delete task:', error);
  } finally {
    taskActionBusy.value = false;
  }
};

onMounted(() => {
  loadTasks();
});
</script>

<style scoped>
.project-tasks-panel {
  margin-top: 1rem;
}

.sidebar-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}

.sidebar-controls {
  display: flex;
  gap: 0.5rem;
}

.project-tasks-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.project-task-item {
  border: 1px solid var(--color-border);
  border-radius: 4px;
  padding: 0.75rem;
  background: var(--color-background-secondary);
}

.project-task-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 0.5rem;
  gap: 1rem;
}

.project-task-title {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex: 1;
}

.project-task-title strong {
  font-weight: 600;
}

.project-task-status {
  display: inline-block;
  padding: 0.2rem 0.5rem;
  border-radius: 3px;
  font-size: 0.75rem;
  font-weight: 500;
  background: var(--color-background-tertiary);
}

.project-task-priority {
  display: inline-block;
  padding: 0.2rem 0.5rem;
  border-radius: 3px;
  font-size: 0.75rem;
  font-weight: 500;
}

.project-task-priority[data-priority="0"] {
  background: #d4f1d4;
  color: #2d5a2d;
}

.project-task-priority[data-priority="1"] {
  background: #fff3cd;
  color: #664d0f;
}

.project-task-priority[data-priority="2"] {
  background: #ffe0e0;
  color: #662d2d;
}

.project-task-priority[data-priority="3"] {
  background: #ff6666;
  color: #fff;
}

.project-task-actions {
  display: flex;
  gap: 0.25rem;
}

.project-task-description {
  color: var(--color-text-secondary);
  font-size: 0.9rem;
  margin: 0.5rem 0;
  line-height: 1.4;
}

.project-task-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  font-size: 0.85rem;
  color: var(--color-text-secondary);
}

.project-task-meta-item {
  display: flex;
  gap: 0.5rem;
}

.project-task-meta-item .label {
  font-weight: 500;
  color: var(--color-text-primary);
}

.form-actions {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.5rem;
}

.form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
}
</style>
