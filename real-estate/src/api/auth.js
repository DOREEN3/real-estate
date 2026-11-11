import { apiRequest, setTokens, clearTokens } from "./client";

export async function login(email, password) {
  const res = await apiRequest("/auth/login", {
    method: "POST",
    body: { email: email.trim().toLowerCase(), password },
  });

  if (res.success && res.data?.access_token) {
    setTokens(res.data.access_token, res.data.refresh_token);
    if (res.data.user) {
      localStorage.setItem("current_user", JSON.stringify(res.data.user));
    }
  }

  return res;
}

export async function getSetupStatus() {
  return await apiRequest("/auth/setup-status");
}

export async function setupInitialAdmin({ full_name, email, password, setup_key }) {
  return await apiRequest("/auth/setup-admin", {
    method: "POST",
    body: {
      full_name: full_name.trim(),
      email: email.trim().toLowerCase(),
      password,
      setup_key,
    },
  });
}

export async function register({ full_name, email, password, role }) {
  return await apiRequest("/auth/register", {
    method: "POST",
    body: {
      full_name: full_name.trim(),
      email: email.trim().toLowerCase(),
      password,
      role,
    },
  });
}

export async function getMe() {
  return await apiRequest("/auth/me");
}

export async function logout() {
  const refreshToken = localStorage.getItem("refresh_token");
  try {
    await apiRequest("/auth/logout", {
      method: "POST",
      body: { refresh_token: refreshToken },
    });
  } finally {
    clearTokens();
  }
}

export async function forgotPassword(email) {
  return await apiRequest("/auth/forgot-password", {
    method: "POST",
    body: { email: email.trim().toLowerCase() },
  });
}

export async function resetPassword(token, password) {
  return await apiRequest("/auth/reset-password", {
    method: "POST",
    body: { token, password },
  });
}

export async function requestAccountDeletion({ password, reason = "" }) {
  return await apiRequest("/auth/account/delete-request", {
    method: "POST",
    body: { password, reason },
  });
}

export async function getMyDeletionRequest() {
  return await apiRequest("/auth/account/deletion-status");
}

export function getCurrentUser() {
  try {
    const raw = localStorage.getItem("current_user");
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}
