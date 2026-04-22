<template>
  <section class="kanban-board">
    <div class="kanban-header">
      <h2>{{ t("projectInfo.tasksKanban") }}</h2>
      <div class="kanban-controls">
        <div class="view-toggle">
          <button
            class="btn btn-sm"
            :class="{ 'btn-primary': viewMode === 'kanban', 'btn-ghost': viewMode !== 'kanban' }"
            @click="viewMode = 'kanban'"
          >
            {{ t("common.kanban") }}
          </button>
          <button
            class="btn btn-sm"
            :class="{ 'btn-primary': viewMode === 'list', 'btn-ghost': viewMode !== 'list' }"
            @click="viewMode = 'list'"
          >
            {{ t("common.list") }}
          </button>
        </div>
        <button
          v-if="canCreateTasks"
          class="btn btn-sm btn-primary"
          type="button"
          @click="showNewTaskForm = true"
          :title="t('projectInfo.taskCreateAction')"
        >
          {{ t("projectInfo.taskCreateAction") }}
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

    <p v-if="taskError" class="error-banner">{{ taskError }}</p>

    <!-- New Task Form -->
    <form v-if="showNewTaskForm && canCreateTasks" class="form-grid compact-form" @submit.prevent="createNewTask">
      <label class="field">
        <span>{{ t("projectInfo.taskTitle") }}</span>
        <input
          v-model.trim="newTaskForm.title"
          type="text"
          maxlength="255"
          required
          :placeholder="t('projectInfo.taskTitlePlaceholder')"
        />
      </label>
      <label class="field">
        <span>{{ t("projectInfo.taskDescription") }}</span>
        <textarea
          v-model.trim="newTaskForm.description"
          rows="2"
          maxlength="2000"
          :placeholder="t('projectInfo.taskDescriptionPlaceholder')"
        />
      </label>
      <div class="form-row">
        <label class="field field-row">
          <span>{{ t("projectInfo.taskPriority") }}</span>
          <select v-model.number="newTaskForm.priority">
            <option :value="0">{{ priorityLabels[0] }}</option>
            <option :value="1">{{ priorityLabels[1] }}</option>
            <option :value="2">{{ priorityLabels[2] }}</option>
            <option :value="3">{{ priorityLabels[3] }}</option>
          </select>
        </label>
        <label class="field field-row">
          <span>{{ t("projectInfo.taskDueDate") }}</span>
          <input v-model="newTaskForm.due_date" type="date" />
        </label>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit" :disabled="newTaskBusy">
          {{ newTaskBusy ? t("common.creating") : t("common.create") }}
        </button>
        <button class="btn btn-ghost" type="button" @click="showNewTaskForm = false">
          {{ t("common.cancel") }}
        </button>
      </div>
    </form>

    <!-- Kanban View -->
    <div v-if="viewMode === 'kanban'" class="kanban-board-container">
      <div v-for="status in statuses" :key="status" class="kanban-column">
        <div class="column-header">
          <h3>{{ statusLabels[status] }}</h3>
          <span class="task-count">{{ getTasksByStatus(status).length }}</span>
        </div>

        <div
          class="kanban-tasks"
          @dragover.prevent="dragOverColumn = status"
          @dragleave.prevent="dragOverColumn = null"
          @drop.prevent="handleTaskDrop(status)"
          :class="{ 'drag-over': dragOverColumn === status }"
        >
          <div
            v-for="task in getTasksByStatus(status)"
            :key="`task-${task.project_task_id}`"
            class="kanban-task-card"
            draggable="true"
            @dragstart="draggedTask = task"
            @dragend="draggedTask = null"
          >
            <div class="task-header">
              <strong class="task-title">{{ task.title }}</strong>
              <span class="task-priority" :data-priority="task.priority">
                {{ priorityLabels[task.priority] }}
              </span>
            </div>

            <p v-if="task.description" class="task-description">{{ task.description }}</p>

            <div class="task-meta">
              <div v-if="task.assigned_to_user_id" class="task-assignee">
                <small>{{ task.assignedTo?.name || t("projectInfo.taskUserFallback", { id: task.assigned_to_user_id }) }}</small>
              </div>
              <div v-if="task.due_date" class="task-due-date">
                <small :class="{ overdue: isOverdue(task.due_date) }">
                  {{ formatDate(task.due_date) }}
                </small>
              </div>
            </div>

            <div class="task-actions">
              <button
                v-if="canCreateTasks"
                class="btn btn-xs btn-ghost"
                type="button"
                @click="editTask(task)"
                :title="t('projectInfo.taskEditAction')"
              >
                {{ t("common.edit") }}
              </button>
              <button
                v-if="canCreateTasks"
                class="btn btn-xs btn-ghost"
                type="button"
                @click="deleteTaskAction(task)"
                :title="t('projectInfo.taskDeleteAction')"
              >
                {{ t("common.delete") }}
              </button>
            </div>
          </div>

          <div v-if="getTasksByStatus(status).length === 0" class="empty-column">
            {{ t("projectInfo.noTasks") }}
          </div>
        </div>
      </div>
    </div>

    <!-- List View (Original Component Logic) -->
    <div v-else class="tasks-list-container">
      <div v-if="taskLoading && !tasks.length" class="muted-text">
        {{ t("common.loading") }}
      </div>

      <div v-else-if="!taskLoading && tasks.length === 0" class="muted-text">
          {{ t("projectInfo.noTasks") }}
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
              <small class="project-task-status" :data-status="task.status">
                {{ statusLabels[task.status] }}
              </small>
              <small class="project-task-priority" :data-priority="task.priority">
                {{ priorityLabels[task.priority] }}
              </small>
            </div>
            <div class="project-task-actions">
                <button
                  v-if="task.status === 'backlog'"
                  class="btn btn-sm btn-ghost"
                  type="button"
                  :disabled="taskActionBusy"
                  @click="startTaskAction(task)"
                >
                  {{ t("projectInfo.taskTakeAction") }}
                </button>
                <button
                  v-if="task.status === 'in_progress'"
                  class="btn btn-sm btn-ghost"
                  type="button"
                  :disabled="taskActionBusy"
                  @click="completeTaskAction(task)"
                >
                  {{ t("projectInfo.taskCompleteAction") }}
                </button>
                <button
                  v-if="task.status === 'done'"
                  class="btn btn-sm btn-ghost"
                  type="button"
                  :disabled="taskActionBusy"
                  @click="reopenTaskAction(task)"
                >
                  {{ t("projectInfo.taskReopenAction") }}
                </button>
                <button
                  v-if="canCreateTasks"
                  class="btn btn-sm btn-ghost"
                  type="button"
                  :disabled="taskActionBusy"
                  @click="editTask(task)"
                >
                  {{ t("projectInfo.taskEditAction") }}
                </button>
                <button
                  v-if="canCreateTasks"
                  class="btn btn-sm btn-ghost"
                  type="button"
                  :disabled="taskActionBusy"
                  @click="deleteTaskAction(task)"
                >
                  {{ t("projectInfo.taskDeleteAction") }}
                </button>
              </div>
            </div>

          <p v-if="task.description" class="project-task-description">{{ task.description }}</p>

          <div class="project-task-meta">
            <div v-if="task.assigned_to_user_id" class="project-task-meta-item">
              <span class="label">{{ t("projectInfo.assignedTo") }}:</span>
              <span>{{ task.assignedTo?.name || t("projectInfo.taskUserFallback", { id: task.assigned_to_user_id }) }}</span>
            </div>
            <div v-if="task.due_date" class="project-task-meta-item">
              <span class="label">{{ t("projectInfo.dueDate") }}:</span>
              <span>{{ formatDate(task.due_date) }}</span>
            </div>
          </div>
        </article>
      </div>
    </div>

    <div v-if="showEditTaskForm" class="modal-overlay" @click.self="closeEditTask">
      <section class="card task-edit-modal">
        <h3>{{ t("projectInfo.taskEditTitle") }}</h3>
        <form class="form-grid compact-form" @submit.prevent="saveTaskEdit">
          <label class="field">
            <span>{{ t("projectInfo.taskTitle") }}</span>
            <input
              v-model.trim="editTaskForm.title"
              type="text"
              maxlength="255"
              required
              :placeholder="t('projectInfo.taskTitlePlaceholder')"
            />
          </label>
          <label class="field">
            <span>{{ t("projectInfo.taskDescription") }}</span>
            <textarea
              v-model.trim="editTaskForm.description"
              rows="3"
              maxlength="2000"
              :placeholder="t('projectInfo.taskDescriptionPlaceholder')"
            />
          </label>
          <div class="form-row">
            <label class="field field-row">
              <span>{{ t("projectInfo.taskPriority") }}</span>
              <select v-model.number="editTaskForm.priority">
                <option :value="0">{{ priorityLabels[0] }}</option>
                <option :value="1">{{ priorityLabels[1] }}</option>
                <option :value="2">{{ priorityLabels[2] }}</option>
                <option :value="3">{{ priorityLabels[3] }}</option>
              </select>
            </label>
            <label class="field field-row">
              <span>{{ t("projectInfo.taskDueDate") }}</span>
              <input v-model="editTaskForm.due_date" type="date" />
            </label>
          </div>
          <label class="field">
            <span>{{ t("projectInfo.taskStatus") }}</span>
            <select v-model="editTaskForm.status">
              <option v-for="status in statuses" :key="`edit-status-${status}`" :value="status">
                {{ statusLabels[status] }}
              </option>
            </select>
          </label>
          <div class="form-actions">
            <button class="btn btn-primary" type="submit" :disabled="editTaskBusy">
              {{ editTaskBusy ? t("common.saving") : t("common.save") }}
            </button>
            <button class="btn btn-ghost" type="button" :disabled="editTaskBusy" @click="closeEditTask">
              {{ t("common.cancel") }}
            </button>
          </div>
        </form>
      </section>
    </div>
  </section>
</template>

<script setup>
import { ref, computed, onMounted, defineProps } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  getTasks, createTask, startTask,
  completeTask,
  reopenTask,
  deleteTask,
  updateTask,
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
const taskActionBusy = ref(false);
const newTaskBusy = ref(false);
const viewMode = ref('kanban'); // 'kanban' or 'list'
const statuses = ['backlog', 'in_progress', 'done'];
const draggedTask = ref(null);
const dragOverColumn = ref(null);
const showNewTaskForm = ref(false);
const showEditTaskForm = ref(false);
const editTaskBusy = ref(false);
const newTaskForm = ref({
  title: '',
  description: '',
  priority: 1,
  due_date: '',
});
const editTaskForm = ref({
  project_task_id: null,
  title: '',
  description: '',
  priority: 1,
  due_date: '',
  status: 'backlog',
});

const canCreateTasks = computed(() => {
  return props.permissions.can_manage_tasks || props.permissions.effective_role === 'admin' || props.permissions.effective_role === 'manager';
});

const priorityLabels = computed(() => ({
  0: t('projectInfo.taskPriorityLow'),
  1: t('projectInfo.taskPriorityMedium'),
  2: t('projectInfo.taskPriorityHigh'),
  3: t('projectInfo.taskPriorityUrgent'),
}));

const statusLabels = computed(() => ({
  backlog: t('projectInfo.taskStatusBacklog'),
  in_progress: t('projectInfo.taskStatusInProgress'),
  done: t('projectInfo.taskStatusDone'),
}));

const TASK_ERROR_MAP = {
  'Access denied.': 'projectInfo.taskErrorAccessDenied',
  'Assignee must have access to the project': 'projectInfo.taskErrorAssigneeAccess',
  'Task must be assigned before moving to in progress.': 'projectInfo.taskErrorTaskMustBeAssigned',
  'Task must be assigned before starting.': 'projectInfo.taskErrorTaskMustBeAssigned',
  'Only in-progress tasks can be completed.': 'projectInfo.taskErrorOnlyInProgress',
  'Only completed tasks can be reopened.': 'projectInfo.taskErrorOnlyDoneReopen',
  'Completed task must be reopened before starting.': 'projectInfo.taskErrorDoneNeedsReopen',
};

const normalizeDateForInput = (value) => {
  if (!value) return '';
  if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return value;
  }
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return '';
  }
  return parsed.toISOString().slice(0, 10);
};

const readError = (error) => {
  if (error && typeof error === 'object') {
    if (typeof error?.data?.message === 'string' && error.data.message.trim() !== '') {
      return error.data.message.trim();
    }
    if (typeof error?.data?.error === 'string' && error.data.error.trim() !== '') {
      return error.data.error.trim();
    }
    if (typeof error?.message === 'string' && error.message.trim() !== '') {
      return error.message.trim();
    }
  }
  return '';
};

const resolveTaskError = (error, fallbackKey) => {
  const message = readError(error);
  if (!message) {
    return t(fallbackKey);
  }

  if (TASK_ERROR_MAP[message]) {
    return t(TASK_ERROR_MAP[message]);
  }

  if (message.includes('WIP limit')) {
    return t('projectInfo.taskErrorWipLimit');
  }

  return message;
};

const formatDate = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleDateString();
};

const isOverdue = (dueDate) => {
  if (!dueDate) return false;
  return new Date(dueDate) < new Date() && new Date(dueDate).toDateString() !== new Date().toDateString();
};

const getTasksByStatus = (status) => {
  return tasks.value.filter((task) => task.status === status);
};

const loadTasks = async () => {
  taskLoading.value = true;
  taskError.value = '';

  try {
    const response = await getTasks(props.projectId);
    if (response?.data?.data) {
      tasks.value = response.data.data;
    }
  } catch (error) {
    taskError.value = resolveTaskError(error, 'projectInfo.taskLoadFailed');
    console.error('Task load failed:', error);
  } finally {
    taskLoading.value = false;
  }
};

const handleTaskDrop = async (targetStatus) => {
  const dragged = draggedTask.value;
  if (!dragged || !dragged.project_task_id) return;

  const taskId = dragged.project_task_id;
  const sourceStatus = dragged.status;
  if (sourceStatus === targetStatus) {
    dragOverColumn.value = null;
    return;
  }

  taskActionBusy.value = true;
  taskError.value = '';

  try {
    let response;

    // Handle status transitions
    if (sourceStatus === 'backlog' && targetStatus === 'in_progress') {
      response = await startTask(props.projectId, taskId);
    } else if (sourceStatus === 'in_progress' && targetStatus === 'done') {
      response = await completeTask(props.projectId, taskId);
    } else if (targetStatus === 'backlog' && sourceStatus === 'done') {
      response = await reopenTask(props.projectId, taskId);
    } else if (targetStatus === 'backlog' && sourceStatus === 'in_progress') {
      response = await updateTask(props.projectId, taskId, {
        status: 'backlog',
      });
    } else {
      // Generic status update
      response = await updateTask(props.projectId, taskId, {
        status: targetStatus,
      });
    }

    if (response?.data?.data) {
      const index = tasks.value.findIndex((t) => t.project_task_id === taskId);
      if (index !== -1) {
        tasks.value[index] = response.data.data;
      }
    }
  } catch (error) {
    taskError.value = resolveTaskError(error, 'projectInfo.taskMoveFailed');
    console.error('Task move failed:', error);
  } finally {
    taskActionBusy.value = false;
    dragOverColumn.value = null;
    draggedTask.value = null;
  }
};

const startTaskAction = async (task) => {
  taskActionBusy.value = true;
  taskError.value = '';

  try {
    const response = await startTask(props.projectId, task.project_task_id);
    if (response?.data?.data) {
      const index = tasks.value.findIndex((t) => t.project_task_id === task.project_task_id);
      if (index !== -1) {
        tasks.value[index] = response.data.data;
      }
    }
  } catch (error) {
    taskError.value = resolveTaskError(error, 'projectInfo.taskStartFailed');
    console.error('Task start failed:', error);
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
      const index = tasks.value.findIndex((t) => t.project_task_id === task.project_task_id);
      if (index !== -1) {
        tasks.value[index] = response.data.data;
      }
    }
  } catch (error) {
    taskError.value = resolveTaskError(error, 'projectInfo.taskCompleteFailed');
    console.error('Task complete failed:', error);
  } finally {
    taskActionBusy.value = false;
  }
};

const reopenTaskAction = async (task) => {
  taskActionBusy.value = true;
  taskError.value = '';

  try {
    const response = await reopenTask(props.projectId, task.project_task_id);
    if (response?.data?.data) {
      const index = tasks.value.findIndex((t) => t.project_task_id === task.project_task_id);
      if (index !== -1) {
        tasks.value[index] = response.data.data;
      }
    }
  } catch (error) {
    taskError.value = resolveTaskError(error, 'projectInfo.taskReopenFailed');
    console.error('Task reopen failed:', error);
  } finally {
    taskActionBusy.value = false;
  }
};

const deleteTaskAction = async (task) => {
  if (!canCreateTasks.value) return;
  if (!confirm(t('projectInfo.taskDeleteConfirm', { title: task?.title || '' }))) return;

  taskActionBusy.value = true;
  taskError.value = '';

  try {
    await deleteTask(props.projectId, task.project_task_id);
    tasks.value = tasks.value.filter((t) => t.project_task_id !== task.project_task_id);
  } catch (error) {
    taskError.value = resolveTaskError(error, 'projectInfo.taskDeleteFailed');
    console.error('Task delete failed:', error);
  } finally {
    taskActionBusy.value = false;
  }
};

const editTask = (task) => {
  if (!canCreateTasks.value || !task) {
    return;
  }

  editTaskForm.value = {
    project_task_id: task.project_task_id,
    title: String(task.title || ''),
    description: String(task.description || ''),
    priority: Number.isInteger(task.priority) ? task.priority : 1,
    due_date: normalizeDateForInput(task.due_date),
    status: String(task.status || 'backlog'),
  };
  showEditTaskForm.value = true;
};

const closeEditTask = () => {
  showEditTaskForm.value = false;
  editTaskForm.value = {
    project_task_id: null,
    title: '',
    description: '',
    priority: 1,
    due_date: '',
    status: 'backlog',
  };
};

const saveTaskEdit = async () => {
  const taskId = editTaskForm.value.project_task_id;
  if (!taskId) {
    return;
  }

  editTaskBusy.value = true;
  taskError.value = '';

  try {
    const response = await updateTask(props.projectId, taskId, {
      title: editTaskForm.value.title,
      description: editTaskForm.value.description || null,
      priority: editTaskForm.value.priority,
      due_date: editTaskForm.value.due_date || null,
      status: editTaskForm.value.status,
    });

    if (response?.data?.data) {
      const index = tasks.value.findIndex((item) => item.project_task_id === taskId);
      if (index !== -1) {
        tasks.value[index] = response.data.data;
      }
      closeEditTask();
    }
  } catch (error) {
    taskError.value = resolveTaskError(error, 'projectInfo.taskUpdateFailed');
    console.error('Task update failed:', error);
  } finally {
    editTaskBusy.value = false;
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
    taskError.value = resolveTaskError(error, 'projectInfo.taskCreateFailed');
    console.error('Task create failed:', error);
  } finally {
    newTaskBusy.value = false;
  }
};

onMounted(() => {
  loadTasks();
});
</script>

<style scoped>
.kanban-board {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.kanban-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
}

.kanban-header h2 {
  margin: 0;
  font-size: 1.5rem;
}

.kanban-controls {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}

.view-toggle {
  display: flex;
  gap: 0.25rem;
  background: var(--surface-muted);
  padding: 0.25rem;
  border-radius: 0.5rem;
}

.view-toggle .btn {
  flex: 1;
}

.kanban-board-container {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
  gap: 1.5rem;
  padding: 1rem 0;
}

.kanban-column {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  background: var(--surface);
  border-radius: 0.75rem;
  padding: 1rem;
  min-height: 500px;
}

.column-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}

.column-header h3 {
  margin: 0;
  font-size: 1.1rem;
}

.task-count {
  background: var(--accent);
  color: white;
  padding: 0.25rem 0.75rem;
  border-radius: 9999px;
  font-size: 0.875rem;
  font-weight: 500;
}

.kanban-tasks {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  flex: 1;
  overflow-y: auto;
  padding: 0.5rem;
  border-radius: 0.5rem;
  transition: background-color 0.2s ease;
}

.kanban-tasks.drag-over {
  background-color: var(--focus-ring);
  border: 2px dashed var(--accent);
}

.kanban-task-card {
  background: var(--surface-muted);
  border: 1px solid var(--border);
  border-radius: 0.5rem;
  padding: 0.75rem;
  cursor: grab;
  transition: all 0.2s ease;
}

.kanban-task-card:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  transform: translateY(-2px);
}

.kanban-task-card:active {
  cursor: grabbing;
  opacity: 0.9;
}

.task-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}

.task-title {
  font-size: 0.95rem;
  color: var(--text-primary);
  word-break: break-word;
}

.task-priority {
  display: inline-block;
  padding: 0.125rem 0.5rem;
  border-radius: 0.25rem;
  font-size: 0.75rem;
  font-weight: 500;
  white-space: nowrap;
  background-color: var(--surface);
}

.task-priority[data-priority='3'] {
  background-color: #fee2e2;
  color: #991b1b;
}

.task-priority[data-priority='2'] {
  background-color: #fef3c7;
  color: #92400e;
}

.task-priority[data-priority='1'] {
  background-color: #dbeafe;
  color: #0c4a6e;
}

.task-priority[data-priority='0'] {
  background-color: #d1fae5;
  color: #065f46;
}

.task-description {
  font-size: 0.875rem;
  color: var(--text-muted);
  margin: 0.5rem 0;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.task-meta {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  margin: 0.5rem 0;
  font-size: 0.8rem;
}

.task-assignee,
.task-due-date {
  background: var(--surface);
  padding: 0.25rem 0.5rem;
  border-radius: 0.25rem;
}

.task-due-date small.overdue {
  color: #991b1b;
  font-weight: 500;
}

.task-actions {
  display: flex;
  gap: 0.25rem;
  margin-top: 0.5rem;
}

.task-actions .btn {
  flex: 1;
  padding: 0.25rem 0.5rem;
  font-size: 0.75rem;
}

.empty-column {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 200px;
  color: var(--text-muted);
  font-style: italic;
}

.tasks-list-container {
  margin-top: 1rem;
}

.project-tasks-list {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.project-task-item {
  background: var(--surface-muted);
  border: 1px solid var(--border);
  border-radius: 0.5rem;
  padding: 1rem;
}

.project-task-head {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.5rem;
}

.project-task-title {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;
}

.project-task-title strong {
  font-size: 1rem;
}

.project-task-status {
  padding: 0.125rem 0.5rem;
  border-radius: 0.25rem;
  font-size: 0.75rem;
  font-weight: 500;
  white-space: nowrap;
}

.project-task-status[data-status='backlog'] {
  background-color: #f3f4f6;
  color: #374151;
}

.project-task-status[data-status='in_progress'] {
  background-color: #fef3c7;
  color: #92400e;
}

.project-task-status[data-status='done'] {
  background-color: #d1fae5;
  color: #065f46;
}

.project-task-priority {
  padding: 0.125rem 0.5rem;
  border-radius: 0.25rem;
  font-size: 0.75rem;
  font-weight: 500;
}

.project-task-priority[data-priority='3'] {
  background-color: #fee2e2;
  color: #991b1b;
}

.project-task-priority[data-priority='2'] {
  background-color: #fef3c7;
  color: #92400e;
}

.project-task-priority[data-priority='1'] {
  background-color: #dbeafe;
  color: #0c4a6e;
}

.project-task-priority[data-priority='0'] {
  background-color: #d1fae5;
  color: #065f46;
}

.project-task-actions {
  display: flex;
  gap: 0.5rem;
}

.project-task-actions .btn {
  font-size: 0.875rem;
}

.project-task-description {
  color: var(--text-muted);
  margin: 0.5rem 0;
  font-size: 0.95rem;
}

.project-task-meta {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
  font-size: 0.875rem;
  color: var(--text-muted);
}

.project-task-meta-item {
  display: flex;
  gap: 0.25rem;
}

.project-task-meta-item .label {
  font-weight: 500;
}

.error-banner {
  border: 1px solid var(--error-border);
  background: var(--error-bg);
  color: var(--danger);
  padding: 1rem;
  border-radius: 0.5rem;
  margin: 1rem 0;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1rem;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 0.5rem;
  padding: 1rem;
  margin: 1rem 0;
}

.compact-form {
  gap: 0.75rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.field span {
  font-weight: 500;
  font-size: 0.875rem;
}

.field input,
.field textarea,
.field select {
  padding: 0.5rem;
  border: 1px solid var(--border);
  border-radius: 0.375rem;
  font-size: 0.95rem;
  font-family: inherit;
  background: var(--surface-muted);
  color: var(--text-primary);
}

.field input:focus,
.field textarea:focus,
.field select:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px var(--focus-ring);
}

.form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}

.field-row {
  flex-direction: column;
}

.field-row span {
  min-width: 0;
}

.form-actions {
  display: flex;
  gap: 0.5rem;
  justify-content: flex-end;
  margin-top: 0.5rem;
}

.form-actions .btn {
  min-width: 100px;
}

.muted-text {
  color: var(--text-muted);
  font-style: italic;
  padding: 2rem;
  text-align: center;
}

.task-edit-modal {
  width: min(640px, 92vw);
  max-height: 86vh;
  overflow: auto;
}

.task-edit-modal h3 {
  margin: 0 0 0.75rem;
}
</style>
