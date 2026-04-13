import { request } from './api';

/**
 * Get all tasks for a project
 */
export async function getTasks(projectId, options = {}) {
  const {
    status = null,
    assignedToUserId = null,
    sortBy = 'priority',
    perPage = 15,
    page = 1,
  } = options;

  return request({
    method: 'GET',
    path: `/api/projects/${projectId}/tasks`,
    query: {
      status,
      assigned_to_user_id: assignedToUserId,
      sort_by: sortBy,
      per_page: perPage,
      page,
    },
  });
}

/**
 * Get task statistics for a project
 */
export async function getTaskStats(projectId) {
  return request({
    method: 'GET',
    path: `/api/projects/${projectId}/tasks/stats`,
  });
}

/**
 * Get a specific task
 */
export async function getTask(projectId, taskId) {
  return request({
    method: 'GET',
    path: `/api/projects/${projectId}/tasks/${taskId}`,
  });
}

/**
 * Create a new task
 */
export async function createTask(projectId, data) {
  return request({
    method: 'POST',
    path: `/api/projects/${projectId}/tasks`,
    body: {
      title: data.title,
      description: data.description,
      priority: data.priority,
      due_date: data.due_date,
    },
  });
}

/**
 * Update a task
 */
export async function updateTask(projectId, taskId, data) {
  return request({
    method: 'PATCH',
    path: `/api/projects/${projectId}/tasks/${taskId}`,
    body: data,
  });
}

/**
 * Assign a task to a user
 */
export async function assignTask(projectId, taskId, userId) {
  return request({
    method: 'POST',
    path: `/api/projects/${projectId}/tasks/${taskId}/assign`,
    body: { user_id: userId },
  });
}

/**
 * Start working on a task
 */
export async function startTask(projectId, taskId) {
  return request({
    method: 'POST',
    path: `/api/projects/${projectId}/tasks/${taskId}/start`,
  });
}

/**
 * Complete a task
 */
export async function completeTask(projectId, taskId) {
  return request({
    method: 'POST',
    path: `/api/projects/${projectId}/tasks/${taskId}/complete`,
  });
}

/**
 * Close a task
 */
export async function closeTask(projectId, taskId) {
  return request({
    method: 'POST',
    path: `/api/projects/${projectId}/tasks/${taskId}/close`,
  });
}

/**
 * Unassign a task
 */
export async function unassignTask(projectId, taskId) {
  return request({
    method: 'POST',
    path: `/api/projects/${projectId}/tasks/${taskId}/unassign`,
  });
}

/**
 * Delete a task
 */
export async function deleteTask(projectId, taskId) {
  return request({
    method: 'DELETE',
    path: `/api/projects/${projectId}/tasks/${taskId}`,
  });
}

/**
 * Priority labels
 */
export const PRIORITY_LABELS = {
  0: 'Low',
  1: 'Medium',
  2: 'High',
  3: 'Urgent',
};

/**
 * Status labels
 */
export const STATUS_LABELS = {
  open: 'Open',
  assigned: 'Assigned',
  in_progress: 'In Progress',
  completed: 'Completed',
  closed: 'Closed',
};

/**
 * Status colors
 */
export const STATUS_COLORS = {
  open: '#gray',
  assigned: '#blue',
  in_progress: '#yellow',
  completed: '#green',
  closed: '#red',
};
