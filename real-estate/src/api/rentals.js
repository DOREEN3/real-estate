import { apiRequest } from "./client";

export async function getTenancies(filters = {}) {
  const query = new URLSearchParams();
  for (const [k, v] of Object.entries(filters)) {
    if (v !== undefined && v !== null && v !== "") {
      query.set(k, v);
    }
  }
  const qs = query.toString();
  return await apiRequest(`/admin/tenancies${qs ? `?${qs}` : ""}`);
}

export async function getTenancy(id) {
  return await apiRequest(`/admin/tenancies/${id}`);
}

export async function createTenancy(data) {
  return await apiRequest("/admin/tenancies", {
    method: "POST",
    body: data,
  });
}

export async function endTenancy(id, endDate) {
  return await apiRequest(`/admin/tenancies/${id}/status`, {
    method: "PATCH",
    body: {
      tenancy_status: "ended",
      end_date: endDate || new Date().toISOString().slice(0, 10),
    },
  });
}

export async function getTenants(filters = {}) {
  const query = new URLSearchParams();
  for (const [k, v] of Object.entries(filters)) {
    if (v !== undefined && v !== null && v !== "") {
      query.set(k, v);
    }
  }
  const qs = query.toString();
  return await apiRequest(`/admin/tenants${qs ? `?${qs}` : ""}`);
}

export async function createTenant(data) {
  return await apiRequest("/admin/tenants", {
    method: "POST",
    body: data,
  });
}

export async function recordTenancyPayment(tenancyId, data) {
  return await apiRequest(`/admin/tenancies/${tenancyId}/payments`, {
    method: "POST",
    body: data,
  });
}

export async function getTenancyPayments(tenancyId) {
  return await apiRequest(`/admin/tenancies/${tenancyId}/payments`);
}

export async function getRentCharges(filters = {}) {
  const query = new URLSearchParams();
  for (const [k, v] of Object.entries(filters)) {
    if (v !== undefined && v !== null && v !== "") {
      query.set(k, v);
    }
  }
  const qs = query.toString();
  return await apiRequest(`/admin/rent-charges${qs ? `?${qs}` : ""}`);
}
