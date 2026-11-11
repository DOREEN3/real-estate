import { apiRequest } from "./client";

export async function getUsers(params = {}) {
  const query = new URLSearchParams(params).toString();
  return await apiRequest(`/admin/users${query ? `?${query}` : ""}`);
}

export async function getPendingUsers() {
  return await apiRequest("/admin/users/pending");
}

export async function createAdminUser({ full_name, email, password }) {
  return await apiRequest("/admin/users/admins", {
    method: "POST",
    body: {
      full_name: full_name.trim(),
      email: email.trim().toLowerCase(),
      password,
    },
  });
}

export async function inviteUser({ email, role, full_name }) {
  return await apiRequest("/admin/users/invite", {
    method: "POST",
    body: {
      email: email.trim().toLowerCase(),
      role,
      full_name: full_name ? full_name.trim() : "",
    },
  });
}

export async function approveUser(id) {
  return await apiRequest(`/admin/users/${id}/approve`, {
    method: "PATCH",
  });
}

export async function rejectUser(id) {
  return await apiRequest(`/admin/users/${id}/reject`, {
    method: "PATCH",
  });
}

export async function suspendUser(id) {
  return await apiRequest(`/admin/users/${id}/suspend`, {
    method: "PATCH",
  });
}

export async function reactivateUser(id) {
  return await apiRequest(`/admin/users/${id}/reactivate`, {
    method: "PATCH",
  });
}

export async function getPendingDeletionRequests() {
  return await apiRequest("/admin/deletion-requests");
}

export async function approveDeletionRequest(id, notes = "") {
  return await apiRequest(`/admin/deletion-requests/${id}/approve`, {
    method: "PATCH",
    body: { notes },
  });
}

export async function rejectDeletionRequest(id, notes = "") {
  return await apiRequest(`/admin/deletion-requests/${id}/reject`, {
    method: "PATCH",
    body: { notes },
  });
}
