import { apiRequest } from "./client";

export async function createSale(data) {
  return await apiRequest("/admin/sales", {
    method: "POST",
    body: data,
  });
}

export async function getSales(params = {}) {
  const query = new URLSearchParams(params).toString();
  return await apiRequest(`/admin/sales${query ? `?${query}` : ""}`);
}

export async function getSale(id) {
  return await apiRequest(`/admin/sales/${id}`);
}

export async function getSaleInstallments(id) {
  return await apiRequest(`/admin/sales/${id}/installments`);
}

export async function getSaleStatement(id) {
  return await apiRequest(`/admin/sales/${id}/statement`);
}

export async function sendInstallmentReminder(id) {
  return await apiRequest(`/admin/sales/${id}/reminders`, {
    method: "POST",
  });
}
