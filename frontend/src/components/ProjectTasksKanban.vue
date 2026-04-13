<template>
  <section class="kanban-board">
    <div class="kanban-header">
      <h2>{{ t("projectInfo.tasksKanban", "Tasks Kanban") }}</h2>
      <div class="kanban-controls">
        <div class="view-toggle">
          <button
            class="btn btn-sm"
            :class="{ 'btn-primary': viewMode === 'kanban', 'btn-ghost': viewMode !== 'kanban' }"
            @click="viewMode = 'kanban'"
          >
            {{ t("common.kanban", "Kanban") }}
          </button>
          <button
            class="btn btn-sm"
            :class="{ 'btn-primary': viewMode === 'list', 'btn-ghost': viewMode !== 'list' }"
            @click="viewMode = 'list'"
          >
            {{ t("common.list", "List") }}
          </button>
        </div>
        <button
          v-if="canCreateTasks"
          class="btn btn-sm btn-primary"
          type="button"
          @click="showNewTaskForm = true"
          title="Add new task"
        >
          {{ t("common.add", "Add Task") }} +
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
          <h3>{{ STATUS_LABELS[status] }}</h3>
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
                {{ PRIORITY_LABELS[task.priority] }}
              </span>
            </div>

            <p v-if="task.description" class="task-description">{{ task.description }}</p>

            <div class="task-meta">
              <div v-if="task.assigned_to_user_id" class="task-assignee">
                <small>{{ task.assignedTo?.name || `User #${task.assigned_to_user_id}` }}</small>
              </div>
              <div v-if="task.due_date" class="task-due-date">
                <small :class="{ overdue: isOverdue(task.due_date) }">
                  {{ formatDate(task.due_date) }}
                </small>
              </div>
            </div>

            <div class="task-actions">
              <button
                class="btn btn-xs btn-ghost"
                type="button"
                @click="editTask(task)"
                title="Edit task"
              >
                ✎
              </button>
              <button
                class="btn btn-xs btn-ghost"
                type="button"
                @click="deleteTaskAction(task)"
                title="Delete task"
              >
                ✕
              </button>
            </div>
          </div>

          <div v-if="getTasksByStatus(status).length === 0" class="empty-column">
            {{ t("projectInfo.noTasks", "No tasks") }}
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
              <small class="project-task-status" :data-status="task.status">
                {{ STATUS_LABELS[task.status] }}
              </small>
              <small class="project-task-priority" :data-priority="task.priority">
                {{ PRIORITY_LABELS[task.priority] }}
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
                Take Task
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
                v-if="task.status === 'done'"
                class="btn btn-sm btn-ghost"
                type="button"
                :disabled="taskActionBusy"
                @click="reopenTaskAction(task)"
              >
                Reopen
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
          </div>
        </article>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, computed, onMounted, defineProps } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  getTasks,  createTask,  startTask,
  completeTask,
  reopenTask,
  deleteTask,
  updateTask,
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
const taskActionBusy = ref(false);
const newTaskBusy = ref(false);
const viewMode = ref('kanban'); // 'kanban' or 'list'
const statuses = ['backlog', 'in_progress', 'done'];
const draggedTask = ref(null);
const dragOverColumn = ref(null);
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
    taskError.value = error?.message || 'Failed to load tasks';
    console.error('Failed to load tasks:', error);
  } finally {
    taskLoading.value = false;
  }
};

const handleTaskDrop = async (targetStatus) => {
  if (!draggedTask.value) return;

  const sourceStatus = draggedTask.value.status;
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
      response = await startTask(props.projectId, draggedTask.value.project_task_id);
    } else if (sourceStatus === 'in_progress' && targetStatus === 'done') {
      response = await completeTask(props.projectId, draggedTask.value.project_task_id);
    } else if (targetStatus === 'backlog' && sourceStatus === 'done') {
      response = await reopenTask(props.projectId, draggedTask.value.project_task_id);
    } else if (targetStatus === 'backlog' && sourceStatus === 'in_progress') {
      response = await updateTask(props.projectId, draggedTask.value.project_task_id, {
        status: 'backlog',
      });
    } else {
      // Generic status update
      response = await updateTask(props.projectId, draggedTask.value.project_task_id, {
        status: targetStatus,
      });
    }

    if (response?.data?.data) {
      const index = tasks.value.findIndex((t) => t.project_task_id === draggedTask.value.project_task_id);
      if (index !== -1) {
        tasks.value[index] = response.data.data;
      }
    }
  } catch (error) {
    taskError.value = error?.message || 'Failed to move task';
    console.error('Failed to move task:', error);
  } finally {
    taskActionBusy.value = false;
    dragOverColumn.value = null;
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
      const index = tasks.value.findIndex((t) => t.project_task_id === task.project_task_id);
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
    taskError.value = error?.message || 'Failed to reopen task';
    console.error('Failed to reopen task:', error);
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
    tasks.value = tasks.value.filter((t) => t.project_task_id !== task.project_task_id);
  } catch (error) {
    taskError.value = error?.message || 'Failed to delete task';
    console.error('Failed to delete task:', error);
  } finally {
    taskActionBusy.value = false;
  }
};

const editTask = (task) => {
  console.log('Edit task:', task);
  // TODO: Implementation for editing tasks
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
  background: var(--color-background-secondary);
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
  background: var(--color-background-secondary);
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
  background: var(--color-primary);
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
  background-color: rgba(59, 130, 246, 0.1);
  border: 2px dashed var(--color-primary);
}

.kanban-task-card {
  background: var(--color-background-tertiary);
  border: 1px solid var(--color-border);
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
  color: var(--color-text-primary);
  word-break: break-word;
}

.task-priority {
  display: inline-block;
  padding: 0.125rem 0.5rem;
  border-radius: 0.25rem;
  font-size: 0.75rem;
  font-weight: 500;
  white-space: nowrap;
  background-color: var(--color-background-tertiary);
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
  color: var(--color-text-secondary);
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
  background: var(--color-background-tertiary);
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
  color: var(--color-text-secondary);
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
  background: var(--color-background-tertiary);
  border: 1px solid var(--color-border);
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
  color: var(--color-text-secondary);
  margin: 0.5rem 0;
  font-size: 0.95rem;
}

.project-task-meta {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
  font-size: 0.875rem;
  color: var(--color-text-secondary);
}

.project-task-meta-item {
  display: flex;
  gap: 0.25rem;
}

.project-task-meta-item .label {
  font-weight: 500;
}

.error-banner {
  background-color: #fee2e2;
  color: #991b1b;
  padding: 1rem;
  border-radius: 0.5rem;
  margin: 1rem 0;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1rem;
  background: var(--color-background-secondary);
  border: 1px solid var(--color-border);
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
  border: 1px solid var(--color-border);
  border-radius: 0.375rem;
  font-size: 0.95rem;
  font-family: inherit;
}

.field input:focus,
.field textarea:focus,
.field select:focus {
  outline: none;
  border-color: var(--color-primary);
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}

.field-row {
  flex-direction: row;
}

.field-row span {
  min-width: 100px;
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
  color: var(--color-text-secondary);
  font-style: italic;
  padding: 2rem;
  text-align: center;
}
</style>
