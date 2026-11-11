import { apiRequest } from "./client";

export async function getCompanyProfile() {
  return await apiRequest("/admin/company-profile");
}

export async function updateCompanyProfile(profileData) {
  const body = typeof profileData === "string" ? { company_name: profileData } : profileData;
  return await apiRequest("/admin/company-profile", {
    method: "PUT",
    body,
  });
}

export async function uploadCompanyLogo(file) {
  const formData = new FormData();
  formData.append("logo", file);
  return await apiRequest("/admin/company-profile/logo", {
    method: "POST",
    body: formData,
  });
}

export function companyLogoUrl(logoUrl) {
  if (!logoUrl) return "";
  const apiBase = new URL(import.meta.env.VITE_API_BASE_URL || "/api/v1", window.location.origin);
  const projectPath = apiBase.pathname.replace(/\/api\/v\d+\/?$/i, "").replace(/\/$/, "");
  const logoPath = `${projectPath}/${String(logoUrl).replace(/^\/+/, "")}`;
  return new URL(logoPath, apiBase.origin).toString();
}
