import { apiRequest } from "./client";

export async function createEnquiry(data) {
  return await apiRequest("/enquiries", {
    method: "POST",
    body: data,
  });
}

export async function getEnquiries(params = {}) {
  const query = new URLSearchParams(params).toString();
  return await apiRequest(`/admin/enquiries${query ? `?${query}` : ""}`);
}

export async function getEnquiry(id) {
  return await apiRequest(`/enquiries/${id}`);
}

export async function updateEnquiryStatus(id, status) {
  return await apiRequest(`/enquiries/${id}/status`, {
    method: "PATCH",
    body: { status },
  });
}

export async function getEnquiryMatches(id) {
  return await apiRequest(`/enquiries/${id}/matches`);
}

export async function refreshEnquiryMatches(id) {
  return await apiRequest(`/enquiries/${id}/matches/refresh`, {
    method: "POST",
  });
}

export async function submitPropertyInquiry(propertyId, data) {
  return await apiRequest(`/properties/${propertyId}/inquiries`, {
    method: "POST",
    body: data,
  });
}

export async function getEnquiryFollowUps(enquiryId) {
  return await apiRequest(`/enquiries/${enquiryId}/follow-ups`);
}

export async function createEnquiryFollowUp(enquiryId, data) {
  return await apiRequest(`/enquiries/${enquiryId}/follow-ups`, {
    method: "POST",
    body: data,
  });
}

export async function updatePropertyMatchStatus(matchId, match_status) {
  return await apiRequest(`/property-matches/${matchId}/status`, {
    method: "PATCH",
    body: { match_status },
  });
}
