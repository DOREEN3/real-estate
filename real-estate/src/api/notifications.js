import { apiRequest } from "./client";

export async function getNotificationLogs(params = {}) {
  const query = new URLSearchParams(params).toString();
  return await apiRequest(`/admin/notification-logs${query ? `?${query}` : ""}`);
}

export async function getNotificationLog(id) {
  return await apiRequest(`/admin/notification-logs/${id}`);
}
