import { apiRequest } from "./client";

export async function getBookings(filters = {}) {
  const query = new URLSearchParams();
  for (const [k, v] of Object.entries(filters)) {
    if (v !== undefined && v !== null && v !== "") {
      query.set(k, v);
    }
  }
  const qs = query.toString();
  return await apiRequest(`/admin/bookings${qs ? `?${qs}` : ""}`);
}

export async function getBooking(id) {
  return await apiRequest(`/admin/bookings/${id}`);
}

export async function createBooking(data) {
  return await apiRequest("/admin/bookings", {
    method: "POST",
    body: data,
  });
}

export async function updateBookingStatus(id, status) {
  return await apiRequest(`/admin/bookings/${id}/status`, {
    method: "PATCH",
    body: { booking_status: status },
  });
}

export async function checkBookingAvailability(propertyId, checkIn, checkOut) {
  return await apiRequest(
    `/properties/${propertyId}/booking-availability?check_in=${encodeURIComponent(checkIn)}&check_out=${encodeURIComponent(checkOut)}`
  );
}

export async function recordBookingPayment(bookingId, data) {
  return await apiRequest(`/admin/bookings/${bookingId}/payments`, {
    method: "POST",
    body: data,
  });
}

export async function getBookingPayments(bookingId) {
  return await apiRequest(`/admin/bookings/${bookingId}/payments`);
}
