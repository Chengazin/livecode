import { request } from "./api";

export async function fetchNotifications(options = {}) {
  const { perPage = 10, unreadOnly = false } = options;

  return request({
    method: "GET",
    path: "/notifications",
    auth: true,
    query: {
      per_page: perPage,
      unread_only: unreadOnly ? 1 : undefined,
    },
  });
}

export async function fetchUnreadNotificationsCount() {
  return request({
    method: "GET",
    path: "/notifications/unread-count",
    auth: true,
  });
}

export async function markNotificationRead(notificationId) {
  return request({
    method: "PATCH",
    path: `/notifications/${notificationId}/mark-as-read`,
    auth: true,
  });
}

export async function markAllNotificationsRead() {
  return request({
    method: "POST",
    path: "/notifications/mark-all-as-read",
    auth: true,
  });
}
