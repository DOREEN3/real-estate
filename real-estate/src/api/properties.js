import { apiRequest, apiDownload } from "./client";

export async function getProperties(filters = {}) {
  const query = new URLSearchParams();
  for (const [k, v] of Object.entries(filters)) {
    if (v !== undefined && v !== null && v !== "") {
      query.set(k, v);
    }
  }
  const qs = query.toString();
  return await apiRequest(`/properties${qs ? `?${qs}` : ""}`);
}

export async function getAdminProperties(filters = {}) {
  const query = new URLSearchParams();
  for (const [k, v] of Object.entries(filters)) {
    if (v !== undefined && v !== null && v !== "") {
      query.set(k, v);
    }
  }
  const qs = query.toString();
  return await apiRequest(`/admin/properties${qs ? `?${qs}` : ""}`);
}

export async function getProperty(id) {
  return await apiRequest(`/properties/${id}`);
}

export async function createProperty(data) {
  return await apiRequest("/properties", {
    method: "POST",
    body: data,
  });
}

export async function updateProperty(id, data) {
  return await apiRequest(`/properties/${id}`, {
    method: "PATCH",
    body: data,
  });
}

export async function addPropertyImage(id, { image_url, alt_text, is_primary, sort_order }) {
  return await apiRequest(`/properties/${id}/images`, {
    method: "POST",
    body: {
      image_url,
      alt_text: alt_text || null,
      is_primary: Boolean(is_primary),
      sort_order: Number(sort_order) || 0,
    },
  });
}

export async function approveProperty(id) {
  return await apiRequest(`/properties/${id}/approve`, {
    method: "PATCH",
  });
}

export async function rejectProperty(id) {
  return await apiRequest(`/properties/${id}/reject`, {
    method: "PATCH",
  });
}

export async function getMyProperties(filters = {}) {
  const query = new URLSearchParams();
  for (const [k, v] of Object.entries(filters)) {
    if (v !== undefined && v !== null && v !== "") {
      query.set(k, v);
    }
  }
  const qs = query.toString();
  return await apiRequest(`/properties/my${qs ? `?${qs}` : ""}`);
}

export async function archiveProperty(id) {
  return await apiRequest(`/properties/${id}`, {
    method: "DELETE",
  });
}

export async function requestPropertyVerification(propertyId) {
  return await apiRequest(`/properties/${propertyId}/verification`, {
    method: "POST",
  });
}

export async function getPropertyVerifications(propertyId) {
  return await apiRequest(`/properties/${propertyId}/verification`);
}

export async function getAdminPropertyVerifications(status = "") {
  const qs = status ? `?status=${encodeURIComponent(status)}` : "";
  return await apiRequest(`/admin/property-verifications${qs}`);
}

export async function uploadVerificationDocument(verificationId, file) {
  const formData = new FormData();
  formData.append("document", file);
  return await apiRequest(`/property-verifications/${verificationId}/document`, {
    method: "POST",
    body: formData,
  });
}

export async function downloadVerificationDocument(verificationId) {
  return await apiDownload(
    `/property-verifications/${verificationId}/document`,
    `verification-${verificationId}`
  );
}

export async function getPropertyDetailsForAdmin(id) {
  return await apiRequest(`/properties/${id}`);
}

export async function reviewPropertyVerification(verificationId, { verification_status, review_notes }) {
  return await apiRequest(`/admin/property-verifications/${verificationId}/review`, {
    method: "PATCH",
    body: {
      verification_status,
      review_notes: review_notes || null,
    },
  });
}

export async function scheduleVerificationVisit(verificationId, { assigned_to, scheduled_at, visit_notes }) {
  return await apiRequest(`/admin/property-verifications/${verificationId}/visits`, {
    method: "POST",
    body: {
      assigned_to: Number(assigned_to),
      scheduled_at: scheduled_at || null,
      visit_notes: visit_notes || null,
    },
  });
}

export async function getMyVerificationVisits() {
  return await apiRequest("/property-verifications/my-visits");
}

export async function recordVerificationVisitOutcome(visitId, { visit_status, visit_notes }) {
  return await apiRequest(`/property-verification-visits/${visitId}`, {
    method: "PATCH",
    body: {
      visit_status,
      visit_notes: visit_notes || "",
    },
  });
}
