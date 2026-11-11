import { apiRequest } from "./client";

export async function getExpenses(year, page = 1, limit = 100) {
  const query = new URLSearchParams({ year: String(year), page: String(page), limit: String(limit) });
  return await apiRequest(`/admin/expenses?${query}`);
}

export async function createExpense(expense) {
  return await apiRequest("/admin/expenses", {
    method: "POST",
    body: expense,
  });
}

export async function getAnnualProfitLoss(year) {
  return await apiRequest(`/admin/reports/profit-loss?year=${encodeURIComponent(year)}`);
}
