import { apiRequest } from "./client";

export async function createPayment(saleId, data) {
  return await apiRequest(`/admin/sales/${saleId}/payments`, {
    method: "POST",
    body: data,
  });
}

export async function initiateMpesaStkPush(saleId, data) {
  return await apiRequest(`/admin/sales/${saleId}/payments/mpesa/stk-push`, {
    method: "POST",
    body: data,
  });
}

export async function getSalePayments(saleId) {
  return await apiRequest(`/admin/sales/${saleId}/payments`);
}

export async function getAdminPayments(params = {}) {
  const query = new URLSearchParams(params).toString();
  return await apiRequest(`/admin/payments${query ? `?${query}` : ""}`);
}

export async function getPayment(id) {
  return await apiRequest(`/admin/payments/${id}`);
}

export async function getPaymentsReport(filters = {}) {
  const query = new URLSearchParams();
  for (const [k, v] of Object.entries(filters)) {
    if (v !== undefined && v !== null && v !== "") {
      query.set(k, v);
    }
  }
  const qs = query.toString();
  return await apiRequest(`/admin/reports/payments${qs ? `?${qs}` : ""}`);
}

export async function getInstallmentsReport() {
  return await apiRequest("/admin/reports/installments");
}

export async function getSaleStatement(saleId) {
  return await apiRequest(`/admin/sales/${saleId}/statement`);
}
