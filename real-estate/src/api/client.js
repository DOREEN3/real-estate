/**
 * Modern REST API Client with Automatic JWT Refresh and Error Handling
 */

const rawBase = (import.meta.env.VITE_API_BASE_URL || "").trim().replace(/\/+$/, "");
const BASE_URL = rawBase
  ? (rawBase.endsWith("/api/v1") ? rawBase : `${rawBase}/api/v1`)
  : "/api/v1";

let isRefreshing = false;
let failedQueue = [];

function processQueue(error, token = null) {
  failedQueue.forEach((prom) => {
    if (error) {
      prom.reject(error);
    } else {
      prom.resolve(token);
    }
  });
  failedQueue = [];
}

export function getTokens() {
  return {
    accessToken: localStorage.getItem("access_token"),
    refreshToken: localStorage.getItem("refresh_token"),
  };
}

export function setTokens(accessToken, refreshToken) {
  if (accessToken) localStorage.setItem("access_token", accessToken);
  if (refreshToken) localStorage.setItem("refresh_token", refreshToken);
}

export function clearTokens() {
  localStorage.removeItem("access_token");
  localStorage.removeItem("refresh_token");
  localStorage.removeItem("current_user");
}

export async function apiRequest(endpoint, options = {}) {
  const url = `${BASE_URL}${endpoint.startsWith("/") ? "" : "/"}${endpoint}`;
  const { accessToken, refreshToken } = getTokens();

  const isFormData = typeof FormData !== "undefined" && options.body instanceof FormData;
  const headers = {
    ...(isFormData ? {} : { "Content-Type": "application/json" }),
    Accept: "application/json",
    ...options.headers,
  };

  if (accessToken && !headers.Authorization) {
    headers.Authorization = `Bearer ${accessToken}`;
  }

  const config = {
    ...options,
    headers,
  };

  if (options.body && typeof options.body === "object" && !isFormData) {
    config.body = JSON.stringify(options.body);
  }

  try {
    let response = await fetch(url, config);

    // Silent Token Refresh on 401
    if (response.status === 401 && refreshToken && !endpoint.includes("/auth/")) {
      if (isRefreshing) {
        return new Promise((resolve, reject) => {
          failedQueue.push({ resolve, reject });
        }).then((newToken) => {
          headers.Authorization = `Bearer ${newToken}`;
          return fetch(url, { ...config, headers }).then((r) => r.json());
        });
      }

      isRefreshing = true;

      try {
        const refreshResponse = await fetch(`${BASE_URL}/auth/refresh`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ refresh_token: refreshToken }),
        });

        const refreshData = await refreshResponse.json();

        if (refreshData.success && refreshData.data?.access_token) {
          const newAccess = refreshData.data.access_token;
          const newRefresh = refreshData.data.refresh_token;
          setTokens(newAccess, newRefresh);
          processQueue(null, newAccess);

          headers.Authorization = `Bearer ${newAccess}`;
          response = await fetch(url, { ...config, headers });
        } else {
          clearTokens();
          processQueue(new Error("Session expired"), null);
          window.location.reload();
          return { success: false, message: "Session expired. Please log in again." };
        }
      } catch (refreshErr) {
        clearTokens();
        processQueue(refreshErr, null);
        return { success: false, message: "Authentication failed." };
      } finally {
        isRefreshing = false;
      }
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
      return {
        success: false,
        status: response.status,
        message: data.message || "An error occurred with your request",
        errors: data.errors || null,
        data: data.data || null,
      };
    }

    return {
      success: true,
      status: response.status,
      message: data.message || "",
      data,
    };
  } catch (err) {
    return {
      success: false,
      message: "Network connection error. Please ensure the backend server is reachable.",
      error: err.message,
    };
  }
}

export async function apiDownload(endpoint, fallbackFilename = "verification-document") {
  const url = `${BASE_URL}${endpoint.startsWith("/") ? "" : "/"}${endpoint}`;
  const { accessToken } = getTokens();
  const headers = {};
  if (accessToken) {
    headers.Authorization = `Bearer ${accessToken}`;
  }

  try {
    const response = await fetch(url, { method: "GET", headers });
    if (!response.ok) {
      const errData = await response.json().catch(() => ({}));
      return {
        success: false,
        message: errData.message || "Unable to download document.",
      };
    }

    const blob = await response.blob();
    const disposition = response.headers.get("Content-Disposition") || "";
    const match = disposition.match(/filename="?([^"]+)"?/i);
    const filename = match ? match[1] : fallbackFilename;

    const blobUrl = window.URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = blobUrl;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => window.URL.revokeObjectURL(blobUrl), 5000);
    return { success: true };
  } catch (err) {
    return {
      success: false,
      message: err.message || "Unable to download document.",
    };
  }
}
