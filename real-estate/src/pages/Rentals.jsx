import { useState, useEffect, useCallback } from "react";
import {
  CalendarClock,
  CheckCircle2,
  CircleDollarSign,
  Plus,
  ReceiptText,
  KeyRound,
  BedDouble,
  LogOut,
  CalendarCheck,
  ShieldCheck,
  AlertCircle,
  Clock,
  UserCheck
} from "lucide-react";
import Modal from "../components/Modal";
import PageHeading from "../components/PageHeading";
import { sanitize, isValidEmail, isValidPhone } from "../lib/validation";
import {
  getTenancies,
  createTenancy,
  endTenancy,
  getTenants,
  createTenant,
  recordTenancyPayment,
  getTenancyPayments,
} from "../api/rentals";
import {
  getBookings,
  createBooking,
  updateBookingStatus,
  checkBookingAvailability,
  recordBookingPayment,
  getBookingPayments,
} from "../api/bookings";
import { addPropertyImage } from "../api/properties";
import { companyLogoUrl } from "../api/companyProfile";
import { fallbackPropertyImage, printDocument } from "../lib/utils";

function fileToWebpDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error("Unable to read image file"));
    reader.onload = () => {
      const img = new Image();
      img.onerror = () => resolve(String(reader.result || ""));
      img.onload = () => {
        const maxDim = 1200;
        let { width, height } = img;
        if (width > maxDim || height > maxDim) {
          const ratio = Math.min(maxDim / width, maxDim / height);
          width = Math.round(width * ratio);
          height = Math.round(height * ratio);
        }
        const canvas = document.createElement("canvas");
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext("2d");
        ctx.drawImage(img, 0, 0, width, height);
        resolve(canvas.toDataURL("image/webp", 0.82));
      };
      img.src = String(reader.result || "");
    };
    reader.readAsDataURL(file);
  });
}

const inputClass =
  "w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 placeholder-slate-400 focus:border-[#0F2A43] focus:outline-none focus:ring-1 focus:ring-[#0F2A43] transition";

function formatMoney(amount, currency = "KES") {
  const num = Number(amount) || 0;
  return `${currency} ${num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(dateString) {
  if (!dateString) return "—";
  try {
    return new Date(dateString).toLocaleDateString("en-GB", {
      day: "numeric",
      month: "short",
      year: "numeric",
    });
  } catch {
    return dateString;
  }
}

export default function Rentals({
  properties = [],
  user = {},
  companyProfile,
  onToast = () => {},
  onReload = () => {},
  onAddProperty = null,
  canManage = true,
}) {
  const [activeTab, setActiveTab] = useState("rentals"); // 'rentals' | 'bnb'
  const [loading, setLoading] = useState(false);

  // Long-Term Rentals State
  const [tenancies, setTenancies] = useState([]);
  const [tenants, setTenants] = useState([]);
  const [showTenancyModal, setShowTenancyModal] = useState(false);
  const [showTenancyPayModal, setShowTenancyPayModal] = useState(false);
  const [selectedTenancy, setSelectedTenancy] = useState(null);
  const [endingTenancyId, setEndingTenancyId] = useState(null);

  // Short-Term BnB State
  const [bookings, setBookings] = useState([]);
  const [showBookingModal, setShowBookingModal] = useState(false);
  const [showBookingPayModal, setShowBookingPayModal] = useState(false);
  const [selectedBooking, setSelectedBooking] = useState(null);

  // Receipt & Payment Ledger Modal State
  const [receiptModal, setReceiptModal] = useState(null);
  const [ledgerModal, setLedgerModal] = useState(null);
  const [rentalPaymentFilter, setRentalPaymentFilter] = useState("all"); // 'all' | 'paid' | 'unpaid'
  const [bnbPaymentFilter, setBnbPaymentFilter] = useState("all"); // 'all' | 'paid' | 'unpaid'

  // Load Tenancies and Tenants
  const loadTenancies = useCallback(async () => {
    try {
      const res = await getTenancies();
      if (res.success && res.data?.data) {
        const sorted = [...res.data.data].sort((a, b) => Number(b.id) - Number(a.id));
        setTenancies(sorted);
      }
      const tRes = await getTenants();
      if (tRes.success && tRes.data?.data) {
        setTenants(tRes.data.data);
      }
    } catch (err) {
      console.error("Failed to load rentals:", err);
    }
  }, []);

  // Load BnB Bookings
  const loadBookings = useCallback(async () => {
    try {
      const res = await getBookings();
      if (res.success && res.data?.data) {
        const sorted = [...res.data.data].sort((a, b) => Number(b.id) - Number(a.id));
        setBookings(sorted);
      }
    } catch (err) {
      console.error("Failed to load bookings:", err);
    }
  }, []);

  useEffect(() => {
    setLoading(true);
    Promise.all([loadTenancies(), loadBookings()]).finally(() => setLoading(false));
  }, [loadTenancies, loadBookings]);

  // --- Handlers for Tenancies ---
  async function handleCreateTenancy(form) {
    try {
      let tenantId = form.useExistingTenant ? form.tenant_id : "";
      if (!tenantId) {
        const newT = await createTenant({
          full_name: (form.tenant_name || "").trim(),
          email: (form.tenant_email || "").trim() || null,
          phone: (form.tenant_phone || "").trim(),
          ...(form.tenant_national_id?.trim() ? { national_id: form.tenant_national_id.trim() } : {}),
        });
        if (!newT.success) {
          onToast(newT.message || "Unable to register tenant");
          return;
        }
        tenantId = newT.data.tenant.id;
      }

      const res = await createTenancy({
        tenant_id: Number(tenantId),
        property_id: Number(form.property_id),
        monthly_rent: form.monthly_rent,
        security_deposit: form.security_deposit || "0.00",
        rent_due_day: Number(form.rent_due_day) || 5,
        grace_period_days: Number(form.grace_period_days) || 0,
        start_date: form.start_date,
        end_date: form.end_date || null,
        currency: form.currency || "KES",
      });

      if (res.success) {
        if (form.property_image_data && form.property_id) {
          await addPropertyImage(Number(form.property_id), {
            image_url: form.property_image_data,
            alt_text: "Rental property photo",
            is_primary: true,
            sort_order: 0,
          });
        }
        onToast("Long-term tenancy created successfully!");
        setShowTenancyModal(false);
        loadTenancies();
        onReload();
      } else {
        onToast(res.message || "Failed to create tenancy");
      }
    } catch (err) {
      onToast(err.message || "Error creating tenancy");
    }
  }

  async function handleEndTenancy(id, endDate) {
    try {
      const res = await endTenancy(id, endDate);
      if (res.success) {
        onToast("Tenancy lease successfully ended.");
        setEndingTenancyId(null);
        loadTenancies();
        onReload();
      } else {
        onToast(res.message || "Failed to end tenancy");
      }
    } catch (err) {
      onToast(err.message || "Error ending tenancy");
    }
  }

  async function handleRecordTenancyPayment(data) {
    try {
      const res = await recordTenancyPayment(data.tenancy_id, {
        amount: data.amount,
        payment_type: data.payment_type, // 'rent' or 'security_deposit'
        payment_method: data.payment_method,
        transaction_reference: data.transaction_reference,
        payment_date: data.payment_date || new Date().toISOString(),
      });

      if (res.success) {
        onToast("Tenancy payment recorded and receipt generated!");
        setShowTenancyPayModal(false);
        loadTenancies();
        onReload();
        if (res.data?.payment) {
          setReceiptModal(res.data.payment);
        }
      } else {
        onToast(res.message || "Failed to record payment");
      }
    } catch (err) {
      onToast(err.message || "Error recording payment");
    }
  }

  // --- Handlers for BnB Bookings ---
  async function handleCreateBooking(form) {
    try {
      const { property_image_data, ...bookingPayload } = form;
      const res = await createBooking(bookingPayload);
      if (res.success) {
        if (property_image_data && form.property_id) {
          await addPropertyImage(Number(form.property_id), {
            image_url: property_image_data,
            alt_text: "BnB property photo",
            is_primary: true,
            sort_order: 0,
          });
        }
        onToast("BnB reservation confirmed!");
        setShowBookingModal(false);
        loadBookings();
        onReload();
      } else {
        onToast(res.message || "Failed to confirm reservation");
      }
    } catch (err) {
      onToast(err.message || "Error creating booking");
    }
  }

  async function handleUpdateBookingStatus(id, newStatus) {
    try {
      const res = await updateBookingStatus(id, newStatus);
      if (res.success) {
        onToast(`Booking updated to ${newStatus.replace("_", " ")}`);
        loadBookings();
        onReload();
      } else {
        onToast(res.message || "Failed to update booking status");
      }
    } catch (err) {
      onToast(err.message || "Error updating booking status");
    }
  }

  async function handleRecordBookingPayment(data) {
    try {
      const res = await recordBookingPayment(data.booking_id, {
        amount: data.amount,
        payment_type: data.payment_type, // 'accommodation' or 'damage_deposit'
        payment_method: data.payment_method,
        transaction_reference: data.transaction_reference,
        payment_date: data.payment_date || new Date().toISOString(),
      });

      if (res.success) {
        onToast("BnB payment recorded and receipt issued!");
        setShowBookingPayModal(false);
        loadBookings();
        onReload();
        if (res.data?.payment) {
          setReceiptModal(res.data.payment);
        }
      } else {
        onToast(res.message || "Failed to record payment");
      }
    } catch (err) {
      onToast(err.message || "Error recording booking payment");
    }
  }

  // Filter properties by transaction type
  const rentalProperties = properties.filter(
    (p) => (p.transactionType === "rent" || p.transaction_type === "rent")
  );
  const bnbProperties = properties.filter(
    (p) => (p.transactionType === "bnb" || p.transaction_type === "bnb")
  );

  return (
    <div className="space-y-6">
      <PageHeading
        eyebrow="Accommodations Management"
        title="Rentals & Short-Term BnBs"
        description="Unified management of ongoing residential tenancies and short-term BnB holiday reservations with automated availability synchronization."
        action={
          canManage && (
            <div className="flex flex-wrap gap-2">
              {onAddProperty && (
                <button
                  type="button"
                  onClick={() =>
                    onAddProperty({
                      listingType: activeTab === "bnb" ? "BnB" : "For Rent",
                    })
                  }
                  className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-[#0F2A43] shadow-sm hover:bg-slate-50 transition"
                >
                  <Plus size={18} />{" "}
                  {activeTab === "bnb" ? "Add BnB Property & Photos" : "Add Rental Property & Photos"}
                </button>
              )}
              {activeTab === "rentals" ? (
                <button
                  type="button"
                  onClick={() => setShowTenancyModal(true)}
                  className="inline-flex items-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-[#173e60] transition"
                >
                  <Plus size={18} /> Register Tenancy
                </button>
              ) : (
                <button
                  type="button"
                  onClick={() => setShowBookingModal(true)}
                  className="inline-flex items-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-[#173e60] transition"
                >
                  <Plus size={18} /> New BnB Reservation
                </button>
              )}
            </div>
          )
        }
      />

      {/* Primary Tab Switcher */}
      <div className="flex border-b border-slate-200">
        <button
          type="button"
          onClick={() => setActiveTab("rentals")}
          className={`flex items-center gap-2.5 px-6 py-4 text-sm font-bold border-b-2 transition ${
            activeTab === "rentals"
              ? "border-[#0F2A43] text-[#0F2A43]"
              : "border-transparent text-slate-500 hover:text-slate-800"
          }`}
        >
          <KeyRound size={18} />
          Long-Term Rentals
          <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">
            {tenancies.length}
          </span>
        </button>

        <button
          type="button"
          onClick={() => setActiveTab("bnb")}
          className={`flex items-center gap-2.5 px-6 py-4 text-sm font-bold border-b-2 transition ${
            activeTab === "bnb"
              ? "border-[#0F2A43] text-[#0F2A43]"
              : "border-transparent text-slate-500 hover:text-slate-800"
          }`}
        >
          <BedDouble size={18} />
          Short-Term BnBs
          <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">
            {bookings.length}
          </span>
        </button>
      </div>

      {/* TAB 1: LONG-TERM RENTALS */}
      {activeTab === "rentals" && (
        <div className="space-y-6">
          {/* Summary Metric Cards */}
          <div className="grid gap-4 sm:grid-cols-4">
            <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
              <p className="text-xs font-medium text-slate-500 uppercase tracking-wider">Active Tenancies</p>
              <p className="mt-2 text-2xl font-bold text-[#0F2A43]">
                {tenancies.filter((t) => t.tenancy_status === "active").length}
              </p>
              <p className="mt-1 text-xs text-slate-400">Total registered leases</p>
            </div>
            <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
              <p className="text-xs font-medium text-slate-500 uppercase tracking-wider">Total Charged</p>
              <p className="mt-2 text-2xl font-bold text-[#0F2A43]">
                {formatMoney(
                  tenancies.reduce((acc, t) => acc + Number(t.total_charged || 0), 0)
                )}
              </p>
              <p className="mt-1 text-xs text-slate-400">Rent billed across accounts</p>
            </div>
            <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
              <p className="text-xs font-medium text-slate-500 uppercase tracking-wider">Total Collected</p>
              <p className="mt-2 text-2xl font-bold text-emerald-600">
                {formatMoney(
                  tenancies.reduce((acc, t) => acc + Number(t.total_paid || 0), 0)
                )}
              </p>
              <p className="mt-1 text-xs text-slate-400">Cleared payments</p>
            </div>
            <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
              <p className="text-xs font-medium text-slate-500 uppercase tracking-wider">Security Deposits</p>
              <p className="mt-2 text-2xl font-bold text-[#B88912]">
                {formatMoney(
                  tenancies.reduce((acc, t) => acc + Number(t.security_deposit || 0), 0)
                )}
              </p>
              <p className="mt-1 text-xs text-slate-400">Held as refundable security</p>
            </div>
          </div>

          {/* Tenancies Table */}
          <section className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
              <div>
                <h2 className="font-bold text-[#0F2A43]">Long-Term Tenancy Accounts</h2>
                <p className="mt-1 text-xs text-slate-500">
                  Track monthly rent &amp; security deposits, see who has paid vs who owes, and view full payment ledgers.
                </p>
              </div>
              <div className="flex items-center gap-1.5 rounded-xl bg-slate-100 p-1 text-xs font-semibold">
                {[
                  ["all", "All Tenants"],
                  ["unpaid", "Unpaid / Balance Due"],
                  ["paid", "Paid Up"],
                ].map(([key, label]) => (
                  <button
                    key={key}
                    type="button"
                    onClick={() => setRentalPaymentFilter(key)}
                    className={`rounded-lg px-3 py-1.5 transition ${
                      rentalPaymentFilter === key
                        ? "bg-[#0F2A43] text-white shadow-sm"
                        : "text-slate-600 hover:text-slate-900"
                    }`}
                  >
                    {label}
                  </button>
                ))}
              </div>
            </div>

            {tenancies.length ? (
              <div className="overflow-x-auto">
                <table className="w-full min-w-240 text-left">
                  <thead className="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                    <tr>
                      <th className="px-5 py-3">Property</th>
                      <th className="px-5 py-3">Tenant Details</th>
                      <th className="px-5 py-3">Monthly Rent</th>
                      <th className="px-5 py-3">Payment Tracking (Paid vs Due)</th>
                      <th className="px-5 py-3">Security Deposit</th>
                      <th className="px-5 py-3">Lease Dates</th>
                      <th className="px-5 py-3">Status</th>
                      <th className="px-5 py-3 text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 text-sm">
                    {tenancies
                      .filter((t) => {
                        const charged = Math.max(Number(t.total_charged || 0), Number(t.monthly_rent || 0));
                        const rentPaid = Number(t.rent_paid ?? t.total_paid ?? 0);
                        const rentBalance = Math.max(0, charged - rentPaid);
                        if (rentalPaymentFilter === "paid") return rentBalance <= 0;
                        if (rentalPaymentFilter === "unpaid") return rentBalance > 0;
                        return true;
                      })
                      .map((t) => {
                        const isActive = t.tenancy_status === "active";
                        const charged = Math.max(Number(t.total_charged || 0), Number(t.monthly_rent || 0));
                        const rentPaid = Number(t.rent_paid ?? t.total_paid ?? 0);
                        const rentBalance = Math.max(0, charged - rentPaid);
                        const depositReq = Number(t.security_deposit || 0);
                        const depositPaid = Number(t.deposit_paid || 0);
                        const payBadge =
                          rentBalance <= 0
                            ? { label: "Paid Up", cls: "bg-emerald-50 text-emerald-700" }
                            : rentPaid > 0
                              ? { label: "Partial Paid", cls: "bg-amber-50 text-amber-700" }
                              : { label: "Unpaid / Due", cls: "bg-red-50 text-red-700" };

                        const matchedProp = properties.find((p) => Number(p.id) === Number(t.property_id));
                        const thumbUrl =
                          t.property_image_url ||
                          matchedProp?.primary_image_url ||
                          matchedProp?.images?.[0] ||
                          fallbackPropertyImage;

                        return (
                          <tr key={t.id} className="hover:bg-slate-50/60 transition">
                            <td className="px-5 py-4 font-semibold text-[#0F2A43]">
                              <div className="flex items-center gap-3">
                                <img
                                  src={thumbUrl}
                                  alt={t.property_title || "Rental property"}
                                  onError={(e) => {
                                    e.currentTarget.src = fallbackPropertyImage;
                                  }}
                                  className="h-11 w-14 shrink-0 rounded-lg object-cover border border-slate-200"
                                />
                                <div>
                                  <span>{t.property_title || `Property #${t.property_id}`}</span>
                                  <span className="block text-xs font-normal text-slate-400">
                                    Rent due on {t.rent_due_day}th
                                  </span>
                                </div>
                              </div>
                            </td>
                            <td className="px-5 py-4">
                              <p className="font-medium text-slate-800">{t.tenant_name}</p>
                              <p className="text-xs text-slate-500">{t.tenant_phone || t.tenant_email}</p>
                            </td>
                            <td className="px-5 py-4 font-bold text-[#0F2A43]">
                              {formatMoney(t.monthly_rent, t.currency)}
                            </td>
                            <td className="px-5 py-4">
                              <div className="flex items-center gap-2">
                                <span
                                  className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold ${payBadge.cls}`}
                                >
                                  {payBadge.label}
                                </span>
                              </div>
                              <p className="mt-1 text-xs font-semibold text-emerald-700">
                                Paid: {formatMoney(rentPaid, t.currency)}
                              </p>
                              <p className="text-xs text-slate-500">
                                Balance: {formatMoney(rentBalance, t.currency)}
                              </p>
                            </td>
                            <td className="px-5 py-4 text-xs text-slate-700">
                              <span className="block font-semibold">
                                Req: {formatMoney(depositReq, t.currency)}
                              </span>
                              <span className="block text-slate-500">
                                Paid: {formatMoney(depositPaid, t.currency)}
                              </span>
                            </td>
                            <td className="px-5 py-4 text-xs text-slate-600">
                              <span className="block font-medium">From: {formatDate(t.start_date)}</span>
                              <span className="block text-slate-400">
                                To: {t.end_date ? formatDate(t.end_date) : "Ongoing"}
                              </span>
                            </td>
                            <td className="px-5 py-4">
                              <span
                                className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-bold ${
                                  isActive ? "bg-emerald-50 text-emerald-700" : "bg-slate-100 text-slate-600"
                                }`}
                              >
                                {isActive ? "Active" : "Ended"}
                              </span>
                            </td>
                            <td className="px-5 py-4 text-right space-x-1.5">
                              <button
                                type="button"
                                onClick={() =>
                                  setLedgerModal({
                                    type: "tenancy",
                                    id: t.id,
                                    title: t.property_title || `Property #${t.property_id}`,
                                    payerName: t.tenant_name,
                                    payerContact: t.tenant_phone || t.tenant_email,
                                    currency: t.currency || "KES",
                                    totalBilled: charged,
                                    totalPaid: Number(t.total_paid || 0),
                                    balanceDue: rentBalance,
                                  })
                                }
                                className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-[#0F2A43] hover:bg-slate-100 transition"
                              >
                                Ledger
                              </button>
                              {isActive && canManage && (
                                <>
                                  <button
                                    type="button"
                                    onClick={() => {
                                      setSelectedTenancy(t);
                                      setShowTenancyPayModal(true);
                                    }}
                                    className="rounded-lg bg-[#0F2A43] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#173e60] transition"
                                  >
                                    Record Payment
                                  </button>
                                  <button
                                    type="button"
                                    onClick={() => setEndingTenancyId(t.id)}
                                    className="rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition"
                                  >
                                    End Lease
                                  </button>
                                </>
                              )}
                            </td>
                          </tr>
                        );
                      })}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="p-12 text-center text-slate-500">
                <KeyRound size={36} className="mx-auto mb-2 text-slate-300" />
                <p className="font-semibold text-slate-700">No tenancy agreements found</p>
                <p className="text-xs text-slate-400 mt-1">Register a tenant onto an approved rental property.</p>
              </div>
            )}
          </section>
        </div>
      )}

      {/* TAB 2: SHORT-TERM BNB BOOKINGS */}
      {activeTab === "bnb" && (
        <div className="space-y-6">
          {/* Summary Metric Cards */}
          <div className="grid gap-4 sm:grid-cols-4">
            <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
              <p className="text-xs font-medium text-slate-500 uppercase tracking-wider">Total Bookings</p>
              <p className="mt-2 text-2xl font-bold text-[#0F2A43]">{bookings.length}</p>
              <p className="mt-1 text-xs text-slate-400">All reservation records</p>
            </div>
            <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
              <p className="text-xs font-medium text-slate-500 uppercase tracking-wider">Guests In House</p>
              <p className="mt-2 text-2xl font-bold text-emerald-600">
                {bookings.filter((b) => b.booking_status === "checked_in").length}
              </p>
              <p className="mt-1 text-xs text-slate-400">Currently Occupied</p>
            </div>
            <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
              <p className="text-xs font-medium text-slate-500 uppercase tracking-wider">Upcoming Reserved</p>
              <p className="mt-2 text-2xl font-bold text-blue-600">
                {bookings.filter((b) => b.booking_status === "confirmed").length}
              </p>
              <p className="mt-1 text-xs text-slate-400">Confirmed upcoming stays</p>
            </div>
            <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
              <p className="text-xs font-medium text-slate-500 uppercase tracking-wider">Total BnB Revenue</p>
              <p className="mt-2 text-2xl font-bold text-[#B88912]">
                {formatMoney(
                  bookings.reduce((acc, b) => acc + Number(b.accommodation_paid || 0), 0)
                )}
              </p>
              <p className="mt-1 text-xs text-slate-400">Accommodation paid to date</p>
            </div>
          </div>

          {/* Bookings Table */}
          <section className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
              <div>
                <h2 className="font-bold text-[#0F2A43]">Short-Term BnB Reservations (Newest First)</h2>
                <p className="mt-1 text-xs text-slate-500">
                  Newest reservations appear at the top. Track guest payments, outstanding balances, and damage deposits.
                </p>
              </div>
              <div className="flex items-center gap-1.5 rounded-xl bg-slate-100 p-1 text-xs font-semibold">
                {[
                  ["all", "All Guests"],
                  ["unpaid", "Unpaid / Balance Due"],
                  ["paid", "Paid in Full"],
                ].map(([key, label]) => (
                  <button
                    key={key}
                    type="button"
                    onClick={() => setBnbPaymentFilter(key)}
                    className={`rounded-lg px-3 py-1.5 transition ${
                      bnbPaymentFilter === key
                        ? "bg-[#0F2A43] text-white shadow-sm"
                        : "text-slate-600 hover:text-slate-900"
                    }`}
                  >
                    {label}
                  </button>
                ))}
              </div>
            </div>

            {bookings.length ? (
              <div className="overflow-x-auto">
                <table className="w-full min-w-245 text-left">
                  <thead className="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                    <tr>
                      <th className="px-5 py-3">Property</th>
                      <th className="px-5 py-3">Guest Information</th>
                      <th className="px-5 py-3">Stay Dates</th>
                      <th className="px-5 py-3">Accommodation Total</th>
                      <th className="px-5 py-3">Payment Status &amp; Balance</th>
                      <th className="px-5 py-3">Damage Deposit</th>
                      <th className="px-5 py-3">Status</th>
                      <th className="px-5 py-3 text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 text-sm">
                    {bookings
                      .filter((b) => {
                        const bal = Number(b.accommodation_balance || 0);
                        if (bnbPaymentFilter === "paid") return bal <= 0;
                        if (bnbPaymentFilter === "unpaid") return bal > 0;
                        return true;
                      })
                      .map((b) => {
                        const statusBadges = {
                          confirmed: "bg-blue-50 text-blue-700",
                          checked_in: "bg-emerald-50 text-emerald-700",
                          checked_out: "bg-slate-100 text-slate-600",
                          cancelled: "bg-red-50 text-red-700",
                        };
                        const paidAmt = Number(b.accommodation_paid || 0);
                        const balAmt = Number(b.accommodation_balance || 0);
                        const payBadge =
                          balAmt <= 0
                            ? { label: "Paid in Full", cls: "bg-emerald-50 text-emerald-700" }
                            : paidAmt > 0
                              ? { label: "Partial Paid", cls: "bg-amber-50 text-amber-700" }
                              : { label: "Unpaid", cls: "bg-red-50 text-red-700" };

                        const matchedProp = properties.find((p) => Number(p.id) === Number(b.property_id));
                        const thumbUrl =
                          b.property_image_url ||
                          matchedProp?.primary_image_url ||
                          matchedProp?.images?.[0] ||
                          fallbackPropertyImage;

                        return (
                          <tr key={b.id} className="hover:bg-slate-50/60 transition">
                            <td className="px-5 py-4 font-semibold text-[#0F2A43]">
                              <div className="flex items-center gap-3">
                                <img
                                  src={thumbUrl}
                                  alt={b.property_title || "BnB property"}
                                  onError={(e) => {
                                    e.currentTarget.src = fallbackPropertyImage;
                                  }}
                                  className="h-11 w-14 shrink-0 rounded-lg object-cover border border-slate-200"
                                />
                                <div>
                                  <span>{b.property_title || `BnB #${b.property_id}`}</span>
                                  <span className="block text-xs font-normal text-slate-400">
                                    {b.number_of_nights} nights @ {formatMoney(b.nightly_rate, b.currency)}/nt
                                  </span>
                                </div>
                              </div>
                            </td>
                            <td className="px-5 py-4">
                              <p className="font-medium text-slate-800">{b.guest_name}</p>
                              <p className="text-xs text-slate-500">{b.guest_phone} · {b.guest_email}</p>
                            </td>
                            <td className="px-5 py-4 text-xs text-slate-600">
                              <span className="block font-medium">In: {formatDate(b.check_in_date)}</span>
                              <span className="block text-slate-400">Out: {formatDate(b.check_out_date)}</span>
                            </td>
                            <td className="px-5 py-4 font-bold text-[#0F2A43]">
                              {formatMoney(b.accommodation_total, b.currency)}
                              <span className="block text-xs font-normal text-emerald-600">
                                Paid: {formatMoney(paidAmt, b.currency)}
                              </span>
                            </td>
                            <td className="px-5 py-4">
                              <span
                                className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold ${payBadge.cls}`}
                              >
                                {payBadge.label}
                              </span>
                              <p className="mt-1 font-semibold text-slate-700">
                                Due: {formatMoney(balAmt, b.currency)}
                              </p>
                            </td>
                            <td className="px-5 py-4 text-xs text-slate-600">
                              <span className="block">Req: {formatMoney(b.required_damage_deposit || 0, b.currency)}</span>
                              <span className="block text-slate-400">
                                Paid: {formatMoney(b.damage_deposit_paid || 0, b.currency)}
                              </span>
                            </td>
                            <td className="px-5 py-4">
                              <span
                                className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-bold ${
                                  statusBadges[b.booking_status] || "bg-slate-100 text-slate-600"
                                }`}
                              >
                                {b.booking_status.replace("_", " ")}
                              </span>
                            </td>
                            <td className="px-5 py-4 text-right space-x-1.5">
                              <button
                                type="button"
                                onClick={() =>
                                  setLedgerModal({
                                    type: "booking",
                                    id: b.id,
                                    title: b.property_title || `BnB #${b.property_id}`,
                                    payerName: b.guest_name,
                                    payerContact: `${b.guest_phone} · ${b.guest_email}`,
                                    currency: b.currency || "KES",
                                    totalBilled: Number(b.accommodation_total || 0),
                                    totalPaid: paidAmt + Number(b.damage_deposit_paid || 0),
                                    balanceDue: balAmt,
                                  })
                                }
                                className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-[#0F2A43] hover:bg-slate-100 transition"
                              >
                                Ledger
                              </button>
                              {canManage && (
                                <>
                                  {b.booking_status === "confirmed" && (
                                    <>
                                      <button
                                        type="button"
                                        onClick={() => handleUpdateBookingStatus(b.id, "checked_in")}
                                        className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 transition"
                                      >
                                        Check In
                                      </button>
                                      <button
                                        type="button"
                                        onClick={() => handleUpdateBookingStatus(b.id, "cancelled")}
                                        className="rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 transition"
                                      >
                                        Cancel
                                      </button>
                                    </>
                                  )}

                                  {b.booking_status === "checked_in" && (
                                    <button
                                      type="button"
                                      onClick={() => handleUpdateBookingStatus(b.id, "checked_out")}
                                      className="rounded-lg bg-slate-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 transition"
                                    >
                                      Check Out
                                    </button>
                                  )}

                                  {b.booking_status !== "cancelled" && (
                                    <button
                                      type="button"
                                      onClick={() => {
                                        setSelectedBooking(b);
                                        setShowBookingPayModal(true);
                                      }}
                                      className="rounded-lg bg-[#0F2A43] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#173e60] transition"
                                    >
                                      Pay
                                    </button>
                                  )}
                                </>
                              )}
                            </td>
                          </tr>
                        );
                      })}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="p-12 text-center text-slate-500">
                <BedDouble size={36} className="mx-auto mb-2 text-slate-300" />
                <p className="font-semibold text-slate-700">No BnB reservations yet</p>
                <p className="text-xs text-slate-400 mt-1">Book a guest onto an approved short-term BnB property.</p>
              </div>
            )}
          </section>
        </div>
      )}

      {/* --- MODAL 1: REGISTER TENANCY --- */}
      {showTenancyModal && (
        <TenancyModal
          properties={rentalProperties}
          tenants={tenants}
          onClose={() => setShowTenancyModal(false)}
          onSave={handleCreateTenancy}
        />
      )}

      {/* --- MODAL 2: RECORD TENANCY PAYMENT --- */}
      {showTenancyPayModal && selectedTenancy && (
        <TenancyPaymentModal
          tenancy={selectedTenancy}
          onClose={() => {
            setShowTenancyPayModal(false);
            setSelectedTenancy(null);
          }}
          onSave={handleRecordTenancyPayment}
        />
      )}

      {/* --- MODAL 3: END LEASE CONFIRMATION --- */}
      {endingTenancyId && (
        <EndLeaseModal
          tenancyId={endingTenancyId}
          onClose={() => setEndingTenancyId(null)}
          onConfirm={(endDate) => handleEndTenancy(endingTenancyId, endDate)}
        />
      )}

      {/* --- MODAL 4: NEW BNB RESERVATION --- */}
      {showBookingModal && (
        <BnBBookingModal
          properties={bnbProperties}
          onClose={() => setShowBookingModal(false)}
          onSave={handleCreateBooking}
        />
      )}

      {/* --- MODAL 5: RECORD BNB PAYMENT --- */}
      {showBookingPayModal && selectedBooking && (
        <BnBPaymentModal
          booking={selectedBooking}
          onClose={() => {
            setShowBookingPayModal(false);
            setSelectedBooking(null);
          }}
          onSave={handleRecordBookingPayment}
        />
      )}

      {/* --- MODAL 6: OFFICIAL RECEIPT DISPLAY --- */}
      {receiptModal && (
        <ReceiptModal
          payment={receiptModal}
          companyProfile={companyProfile}
          onClose={() => setReceiptModal(null)}
        />
      )}

      {/* --- MODAL 7: TENANCY / BNB PAYMENT LEDGER --- */}
      {ledgerModal && (
        <AccountLedgerModal
          account={ledgerModal}
          companyProfile={companyProfile}
          onClose={() => setLedgerModal(null)}
          onViewReceipt={(pmt) => setReceiptModal(pmt)}
        />
      )}
    </div>
  );
}

// -----------------------------------------------------------------------------------------
// Sub-Modals
// -----------------------------------------------------------------------------------------

function TenancyModal({ properties, tenants, onClose, onSave }) {
  const availableProperties = properties.filter((p) => !p.status || p.status === "Available");
  const selectableProperties = availableProperties.length > 0 ? availableProperties : properties;
  const [useExistingTenant, setUseExistingTenant] = useState(false);
  const [form, setForm] = useState({
    property_id: selectableProperties[0]?.id ? String(selectableProperties[0].id) : "",
    tenant_id: "",
    tenant_name: "",
    tenant_email: "",
    tenant_phone: "",
    tenant_national_id: "",
    monthly_rent: selectableProperties[0]?.price ? String(selectableProperties[0].price) : "",
    security_deposit: "",
    rent_due_day: "5",
    grace_period_days: "0",
    start_date: new Date().toISOString().slice(0, 10),
    end_date: "",
    currency: selectableProperties[0]?.currency || "KES",
    property_image_data: "",
  });

  const selectedProp = selectableProperties.find((p) => String(p.id) === String(form.property_id));
  const previewImageUrl =
    form.property_image_data ||
    selectedProp?.primary_image_url ||
    selectedProp?.images?.[0] ||
    fallbackPropertyImage;

  async function handleImageSelect(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    try {
      const dataUrl = await fileToWebpDataUrl(file);
      setForm((prev) => ({ ...prev, property_image_data: dataUrl }));
    } catch {
      // ignore read failure
    }
  }

  function handleSubmit(e) {
    e.preventDefault();
    onSave({
      ...form,
      useExistingTenant,
      tenant_id: useExistingTenant ? form.tenant_id : "",
    });
  }

  return (
    <Modal
      title="Register Long-Term Tenancy"
      description="Create a formal tenancy lease. Property availability status will automatically update to 'Rented'."
      onClose={onClose}
      size="max-w-xl"
    >
      <form onSubmit={handleSubmit} className="space-y-4 p-5 sm:p-7">
        <label className="block text-sm font-semibold text-slate-700">
          Select Rental Property
          <select
            required
            value={form.property_id}
            onChange={(e) => {
              const p = selectableProperties.find((item) => String(item.id) === e.target.value);
              setForm({
                ...form,
                property_id: e.target.value,
                monthly_rent: p ? String(p.price) : form.monthly_rent,
                currency: p?.currency || "KES",
                property_image_data: "",
              });
            }}
            className={`${inputClass} mt-2`}
          >
            {selectableProperties.map((p) => (
              <option key={p.id} value={p.id}>
                {p.title} · {formatMoney(p.price, p.currency || "KES")}/mo
              </option>
            ))}
          </select>
        </label>

        <div className="flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
          <div className="flex items-center gap-3">
            <img
              src={previewImageUrl}
              alt="Rental property preview"
              onError={(e) => {
                e.currentTarget.src = fallbackPropertyImage;
              }}
              className="h-14 w-20 rounded-lg object-cover border border-slate-200"
            />
            <div>
              <p className="text-xs font-bold text-[#0F2A43]">Property Photo</p>
              <p className="text-[11px] text-slate-500">
                {form.property_image_data
                  ? "New photo ready to save with tenancy"
                  : "Upload or update the photo for this rental unit"}
              </p>
            </div>
          </div>
          <label className="cursor-pointer rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-[#0F2A43] hover:bg-slate-100">
            Upload Photo
            <input type="file" accept="image/*" onChange={handleImageSelect} className="hidden" />
          </label>
        </div>

        {tenants.length > 0 && (
          <div className="flex items-center gap-4 py-1">
            <label className="flex items-center gap-2 text-xs font-semibold text-slate-700 cursor-pointer">
              <input
                type="radio"
                name="tenant_type"
                checked={!useExistingTenant}
                onChange={() => {
                  setUseExistingTenant(false);
                  setForm((prev) => ({ ...prev, tenant_id: "" }));
                }}
              />
              Register New Tenant
            </label>
            <label className="flex items-center gap-2 text-xs font-semibold text-slate-700 cursor-pointer">
              <input
                type="radio"
                name="tenant_type"
                checked={useExistingTenant}
                onChange={() => {
                  setUseExistingTenant(true);
                  setForm((prev) => ({
                    ...prev,
                    tenant_id: prev.tenant_id || (tenants[0]?.id ? String(tenants[0].id) : ""),
                  }));
                }}
              />
              Select Existing Tenant
            </label>
          </div>
        )}

        {useExistingTenant && tenants.length > 0 ? (
          <label className="block text-sm font-semibold text-slate-700">
            Select Tenant
            <select
              required
              value={form.tenant_id}
              onChange={(e) => setForm({ ...form, tenant_id: e.target.value })}
              className={`${inputClass} mt-2`}
            >
              {tenants.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.full_name} · {t.phone || t.email}
                </option>
              ))}
            </select>
          </label>
        ) : (
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="text-sm font-semibold text-slate-700">
              Tenant Full Name
              <input
                required
                placeholder="e.g. Jane Wanjiku"
                value={form.tenant_name}
                onChange={(e) => setForm({ ...form, tenant_name: e.target.value })}
                className={`${inputClass} mt-1`}
              />
            </label>
            <label className="text-sm font-semibold text-slate-700">
              Tenant Email
              <input
                required
                type="email"
                placeholder="jane@example.com"
                value={form.tenant_email}
                onChange={(e) => setForm({ ...form, tenant_email: e.target.value })}
                className={`${inputClass} mt-1`}
              />
            </label>
            <label className="text-sm font-semibold text-slate-700">
              Tenant Phone
              <input
                required
                placeholder="+254 7..."
                value={form.tenant_phone}
                onChange={(e) => setForm({ ...form, tenant_phone: e.target.value })}
                className={`${inputClass} mt-1`}
              />
            </label>
            <label className="text-sm font-semibold text-slate-700">
              National ID / Passport (Optional)
              <input
                placeholder="e.g. 29384756"
                value={form.tenant_national_id}
                onChange={(e) => setForm({ ...form, tenant_national_id: e.target.value })}
                className={`${inputClass} mt-1`}
              />
            </label>
          </div>
        )}

        <div className="grid gap-3 sm:grid-cols-2">
          <label className="text-sm font-semibold text-slate-700">
            Monthly Rent ({form.currency})
            <input
              required
              type="number"
              min="1"
              value={form.monthly_rent}
              onChange={(e) => setForm({ ...form, monthly_rent: e.target.value })}
              className={`${inputClass} mt-1`}
            />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Security Deposit ({form.currency})
            <input
              type="number"
              min="0"
              placeholder="e.g. 1 month rent"
              value={form.security_deposit}
              onChange={(e) => setForm({ ...form, security_deposit: e.target.value })}
              className={`${inputClass} mt-1`}
            />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Rent Due Day (1–28)
            <input
              required
              type="number"
              min="1"
              max="28"
              value={form.rent_due_day}
              onChange={(e) => setForm({ ...form, rent_due_day: e.target.value })}
              className={`${inputClass} mt-1`}
            />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Grace Period (days)
            <input
              required
              type="number"
              min="0"
              max="30"
              value={form.grace_period_days}
              onChange={(e) => setForm({ ...form, grace_period_days: e.target.value })}
              className={`${inputClass} mt-1`}
            />
            <span className="mt-1 block text-xs font-normal text-slate-500">
              Overdue notices begin after this agreement grace period.
            </span>
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Start Date
            <input
              required
              type="date"
              value={form.start_date}
              onChange={(e) => setForm({ ...form, start_date: e.target.value })}
              className={`${inputClass} mt-1`}
            />
          </label>
        </div>

        <div className="flex justify-end gap-3 border-t border-slate-100 pt-5">
          <button
            type="button"
            onClick={onClose}
            className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={!properties.length}
            className="rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60] disabled:opacity-50"
          >
            Create Tenancy
          </button>
        </div>
      </form>
    </Modal>
  );
}

function TenancyPaymentModal({ tenancy, onClose, onSave }) {
  const currency = tenancy.currency || "KES";
  const rentCharged = Math.max(Number(tenancy.total_charged || 0), Number(tenancy.monthly_rent || 0));
  const rentPaid = Number(tenancy.rent_paid ?? tenancy.total_paid ?? 0);
  const rentBalance = Math.max(0, rentCharged - rentPaid);
  const depositExpected = Number(tenancy.security_deposit || 0);
  const depositPaid = Number(tenancy.deposit_paid || 0);
  const depositBalance = Math.max(0, depositExpected - depositPaid);

  const [form, setForm] = useState({
    tenancy_id: tenancy.id,
    amount: String(rentBalance > 0 ? rentBalance : tenancy.monthly_rent || ""),
    payment_type: "rent", // 'rent' | 'security_deposit'
    payment_method: "mobile_money",
    transaction_reference: "",
    payment_date: new Date().toISOString().slice(0, 16),
  });
  const [step, setStep] = useState("entry"); // 'entry' | 'confirm'
  const [authorizeAdvance, setAuthorizeAdvance] = useState(false);
  const [evidenceVerified, setEvidenceVerified] = useState(false);

  const originalDue = form.payment_type === "security_deposit" ? depositExpected : rentCharged;
  const previouslyPaid = form.payment_type === "security_deposit" ? depositPaid : rentPaid;
  const remainingBalance = form.payment_type === "security_deposit" ? depositBalance : rentBalance;

  const rawAmount = String(form.amount ?? "").trim();
  const isValidNum = /^\d+(\.\d{1,2})?$/.test(rawAmount);
  const enteredAmount = isValidNum ? Number(rawAmount) : NaN;
  const trimmedRef = String(form.transaction_reference ?? "").trim();

  const exceedsBalance =
    remainingBalance > 0 && Number.isFinite(enteredAmount) && enteredAmount > remainingBalance + 0.005;
  const ratio =
    remainingBalance > 0 && Number.isFinite(enteredAmount) ? enteredAmount / remainingBalance : 0;
  const isUnusualAmount = ratio >= 2;

  let validationError = "";
  if (rawAmount === "") {
    validationError = "Payment amount is required.";
  } else if (!isValidNum || !Number.isFinite(enteredAmount) || enteredAmount <= 0) {
    validationError = "Enter a valid positive numeric amount.";
  } else if (trimmedRef === "") {
    validationError = "Transaction reference / supporting evidence is required.";
  } else if (exceedsBalance && !authorizeAdvance) {
    validationError = "Amount exceeds the remaining balance. Authorize advance rent payment below or correct the amount.";
  }

  function handleProceed(e) {
    e.preventDefault();
    if (validationError) return;
    setEvidenceVerified(false);
    setStep("confirm");
  }

  function handleFinalSubmit() {
    if (!evidenceVerified) return;
    onSave({
      ...form,
      transaction_reference: trimmedRef,
    });
  }

  const methodLabels = {
    mobile_money: "M-Pesa / Mobile Money",
    bank_transfer: "Bank Transfer / EFT",
    cash: "Cash",
    cheque: "Cheque",
    credit_card: "Card",
  };

  return (
    <Modal
      title={step === "confirm" ? "Confirm tenancy payment" : "Record Tenancy Payment"}
      description={
        step === "confirm"
          ? `Pending confirmation — Verify payment for ${tenancy.tenant_name} (${tenancy.property_title}) before recording.`
          : `Record payment for ${tenancy.tenant_name} (${tenancy.property_title}). A formal receipt will be generated.`
      }
      onClose={onClose}
      size="max-w-lg"
    >
      {step === "confirm" ? (
        <div className="space-y-4 p-5 sm:p-7">
          {isUnusualAmount && (
            <div className="rounded-xl border border-red-200 bg-red-50 p-3.5 text-xs text-red-900">
              <p className="font-bold uppercase tracking-wider text-red-800">
                Unusual payment amount ({ratio.toFixed(1)}× outstanding balance)
              </p>
              <p className="mt-1">
                Outstanding balance is <strong>{formatMoney(remainingBalance, currency)}</strong> and you entered{" "}
                <strong>{formatMoney(enteredAmount, currency)}</strong>. Verify against payment evidence carefully.
              </p>
            </div>
          )}

          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">
              Amount to record ({form.payment_type === "security_deposit" ? "Security Deposit" : "Monthly Rent"})
            </p>
            <p className="mt-1 text-2xl font-extrabold text-[#0F2A43]">
              {formatMoney(enteredAmount, currency)}
            </p>
            <div className="mt-3 divide-y divide-slate-200/80 text-xs">
              <div className="flex justify-between py-2">
                <span className="text-slate-500">Payment method</span>
                <span className="font-semibold text-[#0F2A43]">
                  {methodLabels[form.payment_method] || form.payment_method}
                </span>
              </div>
              <div className="flex justify-between py-2">
                <span className="text-slate-500">Transaction reference</span>
                <span className="font-mono font-bold text-[#0F2A43]">{trimmedRef}</span>
              </div>
              <div className="flex justify-between py-2">
                <span className="text-slate-500">Outstanding before payment</span>
                <span className="font-semibold text-slate-700">{formatMoney(remainingBalance, currency)}</span>
              </div>
              <div className="flex justify-between py-2">
                <span className="text-slate-500">Outstanding after payment</span>
                <span className="font-bold text-emerald-700">
                  {formatMoney(Math.max(0, remainingBalance - enteredAmount), currency)}
                </span>
              </div>
            </div>
          </div>

          <label className="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 bg-white p-3 text-xs text-slate-700">
            <input
              type="checkbox"
              checked={evidenceVerified}
              onChange={(e) => setEvidenceVerified(e.target.checked)}
              className="mt-0.5 h-4 w-4 rounded border-slate-300 text-[#0F2A43]"
            />
            <span>
              <strong>Are you sure the amount matches the payment evidence?</strong> I have verified{" "}
              <strong>{formatMoney(enteredAmount, currency)}</strong> and reference{" "}
              <strong className="font-mono">{trimmedRef}</strong> before recording.
            </span>
          </label>

          <div className="flex justify-end gap-3 border-t border-slate-100 pt-4">
            <button
              type="button"
              onClick={() => setStep("entry")}
              className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50"
            >
              Back
            </button>
            <button
              type="button"
              onClick={handleFinalSubmit}
              disabled={!evidenceVerified}
              className="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50"
            >
              Confirm &amp; Record Payment
            </button>
          </div>
        </div>
      ) : (
        <form onSubmit={handleProceed} className="space-y-4 p-5 sm:p-7">
          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-3.5">
            <p className="text-xs font-bold uppercase tracking-wider text-[#0F2A43]">
              Automatic Tenancy Balance Breakdown
            </p>
            <div className="mt-2.5 grid grid-cols-3 gap-2 text-xs">
              <div>
                <p className="text-slate-500">Expected charge</p>
                <p className="mt-0.5 font-bold text-[#0F2A43]">{formatMoney(originalDue, currency)}</p>
              </div>
              <div>
                <p className="text-slate-500">Previously paid</p>
                <p className="mt-0.5 font-bold text-emerald-700">{formatMoney(previouslyPaid, currency)}</p>
              </div>
              <div>
                <p className="text-slate-500">Remaining balance</p>
                <p className="mt-0.5 font-extrabold text-amber-800">{formatMoney(remainingBalance, currency)}</p>
              </div>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <label className="text-sm font-semibold text-slate-700">
              Payment Type
              <select
                value={form.payment_type}
                onChange={(e) => {
                  const nextType = e.target.value;
                  const nextBal =
                    nextType === "security_deposit"
                      ? depositBalance > 0
                        ? depositBalance
                        : depositExpected
                      : rentBalance > 0
                        ? rentBalance
                        : tenancy.monthly_rent;
                  setForm({
                    ...form,
                    payment_type: nextType,
                    amount: String(nextBal || ""),
                  });
                  setAuthorizeAdvance(false);
                }}
                className={`${inputClass} mt-2`}
              >
                <option value="rent">Monthly Rent</option>
                <option value="security_deposit">Security Deposit</option>
              </select>
            </label>

            <label className="text-sm font-semibold text-slate-700">
              Amount ({currency})
              <input
                required
                type="text"
                inputMode="decimal"
                value={form.amount}
                onChange={(e) => setForm({ ...form, amount: e.target.value })}
                className={`${inputClass} mt-2 font-bold`}
              />
            </label>

            <label className="text-sm font-semibold text-slate-700">
              Payment Method
              <select
                value={form.payment_method}
                onChange={(e) => setForm({ ...form, payment_method: e.target.value })}
                className={`${inputClass} mt-2`}
              >
                <option value="mobile_money">M-Pesa / Mobile Money</option>
                <option value="bank_transfer">Bank Transfer / EFT</option>
                <option value="cash">Cash</option>
                <option value="cheque">Cheque</option>
                <option value="credit_card">Card</option>
              </select>
            </label>

            <label className="text-sm font-semibold text-slate-700">
              Payment Date
              <input
                required
                type="datetime-local"
                value={form.payment_date}
                onChange={(e) => setForm({ ...form, payment_date: e.target.value })}
                className={`${inputClass} mt-2`}
              />
            </label>
          </div>

          <label className="block text-sm font-semibold text-slate-700">
            Transaction Reference / Evidence <span className="text-red-600">*</span>
            <input
              required
              placeholder="e.g. MPESA Ref QWE123 or Bank Slip #"
              value={form.transaction_reference}
              onChange={(e) => setForm({ ...form, transaction_reference: e.target.value })}
              className={`${inputClass} mt-2 font-mono`}
            />
          </label>

          {exceedsBalance && (
            <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
              <p className="font-bold">
                {ratio >= 5
                  ? `Unusual payment amount (${ratio.toFixed(1)}× the remaining balance)`
                  : "Amount exceeds remaining tenancy balance"}
              </p>
              <p className="mt-1">
                Outstanding: <strong>{formatMoney(remainingBalance, currency)}</strong> · Entered:{" "}
                <strong>{formatMoney(enteredAmount, currency)}</strong>
              </p>
              <label className="mt-2 flex cursor-pointer items-start gap-2 rounded-lg bg-white p-2 text-slate-800">
                <input
                  type="checkbox"
                  checked={authorizeAdvance}
                  onChange={(e) => setAuthorizeAdvance(e.target.checked)}
                  className="mt-0.5 h-4 w-4 rounded border-slate-300"
                />
                <span>
                  I have verified the payment evidence and authorize recording this advance rent payment.
                </span>
              </label>
            </div>
          )}

          {validationError && (
            <div className="rounded-xl border border-red-200 bg-red-50 px-3.5 py-2.5 text-xs font-semibold text-red-700">
              {validationError}
            </div>
          )}

          <div className="flex justify-end gap-3 border-t border-slate-100 pt-5">
            <button
              type="button"
              onClick={onClose}
              className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={Boolean(validationError)}
              className="rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60] disabled:opacity-50"
            >
              Verify Before Recording
            </button>
          </div>
        </form>
      )}
    </Modal>
  );
}

function EndLeaseModal({ tenancyId, onClose, onConfirm }) {
  const [endDate, setEndDate] = useState(new Date().toISOString().slice(0, 10));

  return (
    <Modal
      title="End Tenancy Agreement"
      description="Terminating the tenancy will mark the lease as ended and re-evaluate property availability."
      onClose={onClose}
      size="max-w-md"
    >
      <div className="p-5 sm:p-7 space-y-4">
        <label className="block text-sm font-semibold text-slate-700">
          Effective Move-Out Date
          <input
            type="date"
            value={endDate}
            onChange={(e) => setEndDate(e.target.value)}
            className={`${inputClass} mt-2`}
          />
        </label>
        <p className="text-xs text-amber-700 bg-amber-50 p-3 rounded-xl border border-amber-200">
          Notice: Ensure any refund of the security deposit has been settled prior to ending the active tenancy.
        </p>
        <div className="flex justify-end gap-3 border-t border-slate-100 pt-5">
          <button
            type="button"
            onClick={onClose}
            className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={() => onConfirm(endDate)}
            className="rounded-xl bg-red-600 px-5 py-3 text-sm font-semibold text-white hover:bg-red-700"
          >
            Confirm End Lease
          </button>
        </div>
      </div>
    </Modal>
  );
}

function BnBBookingModal({ properties, onClose, onSave }) {
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  const checkOutDefault = new Date();
  checkOutDefault.setDate(checkOutDefault.getDate() + 4);

  const [form, setForm] = useState({
    property_id: properties[0]?.id ? String(properties[0].id) : "",
    guest_name: "",
    guest_email: "",
    guest_phone: "",
    check_in_date: tomorrow.toISOString().slice(0, 10),
    check_out_date: checkOutDefault.toISOString().slice(0, 10),
    nightly_rate: properties[0]?.price ? String(properties[0].price) : "5000",
    required_booking_deposit: "0.00",
    required_damage_deposit: "5000.00",
    special_requests: "",
    currency: properties[0]?.currency || "KES",
    property_image_data: "",
  });

  const [checkingAvailability, setCheckingAvailability] = useState(false);
  const [availResult, setAvailResult] = useState(null);

  const selectedProp = properties.find((p) => String(p.id) === String(form.property_id));
  const previewImageUrl =
    form.property_image_data ||
    selectedProp?.primary_image_url ||
    selectedProp?.images?.[0] ||
    fallbackPropertyImage;

  async function handleImageSelect(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    try {
      const dataUrl = await fileToWebpDataUrl(file);
      setForm((prev) => ({ ...prev, property_image_data: dataUrl }));
    } catch {
      // ignore read failure
    }
  }

  // Compute nights
  const checkInD = new Date(form.check_in_date);
  const checkOutD = new Date(form.check_out_date);
  const nights = Math.max(0, Math.round((checkOutD - checkInD) / (1000 * 60 * 60 * 24)));
  const totalAccommodation = nights * (Number(form.nightly_rate) || 0);

  async function handleCheckDates() {
    if (!form.property_id || !form.check_in_date || !form.check_out_date) return;
    setCheckingAvailability(true);
    try {
      const res = await checkBookingAvailability(
        form.property_id,
        form.check_in_date,
        form.check_out_date
      );
      if (res.success) {
        setAvailResult(res.data);
      }
    } catch {
      setAvailResult({ available: false });
    } finally {
      setCheckingAvailability(false);
    }
  }

  function handleSubmit(e) {
    e.preventDefault();
    onSave(form);
  }

  return (
    <Modal
      title="New Short-Term BnB Reservation"
      description="Create a reservation. Overlap prevention ensures no double-booking for the selected dates."
      onClose={onClose}
      size="max-w-xl"
    >
      <form onSubmit={handleSubmit} className="space-y-4 p-5 sm:p-7">
        <label className="block text-sm font-semibold text-slate-700">
          Select BnB Property
          <select
            required
            value={form.property_id}
            onChange={(e) => {
              const p = properties.find((item) => String(item.id) === e.target.value);
              setForm({
                ...form,
                property_id: e.target.value,
                nightly_rate: p ? String(p.price) : form.nightly_rate,
                currency: p?.currency || "KES",
                property_image_data: "",
              });
              setAvailResult(null);
            }}
            className={`${inputClass} mt-2`}
          >
            {properties.map((p) => (
              <option key={p.id} value={p.id}>
                {p.title} · {formatMoney(p.price, p.currency || "KES")}/night
              </option>
            ))}
          </select>
        </label>

        <div className="flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
          <div className="flex items-center gap-3">
            <img
              src={previewImageUrl}
              alt="BnB property preview"
              onError={(e) => {
                e.currentTarget.src = fallbackPropertyImage;
              }}
              className="h-14 w-20 rounded-lg object-cover border border-slate-200"
            />
            <div>
              <p className="text-xs font-bold text-[#0F2A43]">BnB Unit Photo</p>
              <p className="text-[11px] text-slate-500">
                {form.property_image_data
                  ? "New photo ready to save with reservation"
                  : "Upload or update the photo for this BnB unit"}
              </p>
            </div>
          </div>
          <label className="cursor-pointer rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-[#0F2A43] hover:bg-slate-100">
            Upload Photo
            <input type="file" accept="image/*" onChange={handleImageSelect} className="hidden" />
          </label>
        </div>

        <div className="grid gap-3 sm:grid-cols-3">
          <label className="text-sm font-semibold text-slate-700">
            Guest Name
            <input
              required
              placeholder="e.g. Sarah Jenkins"
              value={form.guest_name}
              onChange={(e) => setForm({ ...form, guest_name: e.target.value })}
              className={`${inputClass} mt-1`}
            />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Guest Email
            <input
              required
              type="email"
              placeholder="sarah@example.com"
              value={form.guest_email}
              onChange={(e) => setForm({ ...form, guest_email: e.target.value })}
              className={`${inputClass} mt-1`}
            />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Guest Phone
            <input
              required
              placeholder="+254 7..."
              value={form.guest_phone}
              onChange={(e) => setForm({ ...form, guest_phone: e.target.value })}
              className={`${inputClass} mt-1`}
            />
          </label>
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <label className="text-sm font-semibold text-slate-700">
            Check-In Date
            <input
              required
              type="date"
              value={form.check_in_date}
              onChange={(e) => {
                setForm({ ...form, check_in_date: e.target.value });
                setAvailResult(null);
              }}
              className={`${inputClass} mt-1`}
            />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Check-Out Date
            <input
              required
              type="date"
              value={form.check_out_date}
              onChange={(e) => {
                setForm({ ...form, check_out_date: e.target.value });
                setAvailResult(null);
              }}
              className={`${inputClass} mt-1`}
            />
          </label>
        </div>

        {/* Live Availability & Price Calculation */}
        <div className="rounded-xl bg-slate-50 p-4 border border-slate-200/80">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <p className="text-sm font-bold text-[#0F2A43]">
                {nights} nights × {formatMoney(form.nightly_rate, form.currency)} ={" "}
                <span className="text-emerald-700 font-extrabold">{formatMoney(totalAccommodation, form.currency)}</span>
              </p>
              <p className="text-xs text-slate-500 mt-0.5">Accommodation charge (deposits held separately)</p>
            </div>
            <button
              type="button"
              onClick={handleCheckDates}
              disabled={checkingAvailability}
              className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-[#0F2A43] hover:bg-slate-100"
            >
              {checkingAvailability ? "Checking..." : "Verify Availability"}
            </button>
          </div>

          {availResult && (
            <div
              className={`mt-3 flex items-center gap-2 rounded-lg p-2.5 text-xs font-semibold ${
                availResult.available ? "bg-emerald-100 text-emerald-800" : "bg-red-100 text-red-800"
              }`}
            >
              {availResult.available ? (
                <>
                  <CheckCircle2 size={16} /> Dates are 100% available with zero booking collisions!
                </>
              ) : (
                <>
                  <AlertCircle size={16} /> Conflict detected: Property is already reserved for part of these dates.
                </>
              )}
            </div>
          )}
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <label className="text-sm font-semibold text-slate-700">
            Booking Deposit Required ({form.currency})
            <input
              type="number"
              min="0"
              placeholder="e.g. 5000"
              value={form.required_booking_deposit}
              onChange={(e) => setForm({ ...form, required_booking_deposit: e.target.value })}
              className={`${inputClass} mt-1`}
            />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Damage Deposit Required ({form.currency})
            <input
              type="number"
              min="0"
              placeholder="e.g. 10000"
              value={form.required_damage_deposit}
              onChange={(e) => setForm({ ...form, required_damage_deposit: e.target.value })}
              className={`${inputClass} mt-1`}
            />
          </label>
        </div>

        <label className="block text-sm font-semibold text-slate-700">
          Special Requests / Notes
          <textarea
            rows="2"
            placeholder="Early check-in, airport pickup, crib, etc."
            value={form.special_requests}
            onChange={(e) => setForm({ ...form, special_requests: e.target.value })}
            className={`${inputClass} mt-1`}
          />
        </label>

        <div className="flex justify-end gap-3 border-t border-slate-100 pt-5">
          <button
            type="button"
            onClick={onClose}
            className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={!properties.length || nights <= 0}
            className="rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60] disabled:opacity-50"
          >
            Confirm Reservation
          </button>
        </div>
      </form>
    </Modal>
  );
}

function BnBPaymentModal({ booking, onClose, onSave }) {
  const currency = booking.currency || "KES";
  const accommTotal = Number(booking.accommodation_total ?? booking.total_amount ?? 0);
  const accommPaid = Number(booking.accommodation_paid ?? booking.total_paid ?? 0);
  const accommBalance = Math.max(0, Number(booking.accommodation_balance ?? (accommTotal - accommPaid)));

  const damageTotal = Number(booking.required_damage_deposit || 0);
  const damagePaid = Number(booking.damage_deposit_paid || 0);
  const damageBalance = Math.max(0, damageTotal - damagePaid);

  const [form, setForm] = useState({
    booking_id: booking.id,
    amount: String(accommBalance > 0 ? accommBalance : accommTotal || "5000"),
    payment_type: "accommodation", // 'accommodation' | 'damage_deposit'
    payment_method: "mobile_money",
    transaction_reference: "",
    payment_date: new Date().toISOString().slice(0, 16),
  });
  const [step, setStep] = useState("entry"); // 'entry' | 'confirm'
  const [evidenceVerified, setEvidenceVerified] = useState(false);

  const originalDue = form.payment_type === "damage_deposit" ? damageTotal : accommTotal;
  const previouslyPaid = form.payment_type === "damage_deposit" ? damagePaid : accommPaid;
  const remainingBalance = form.payment_type === "damage_deposit" ? damageBalance : accommBalance;

  const rawAmount = String(form.amount ?? "").trim();
  const isValidNum = /^\d+(\.\d{1,2})?$/.test(rawAmount);
  const enteredAmount = isValidNum ? Number(rawAmount) : NaN;
  const trimmedRef = String(form.transaction_reference ?? "").trim();

  const exceedsBalance =
    remainingBalance > 0 && Number.isFinite(enteredAmount) && enteredAmount > remainingBalance + 0.005;
  const ratio =
    remainingBalance > 0 && Number.isFinite(enteredAmount) ? enteredAmount / remainingBalance : 0;

  let validationError = "";
  if (rawAmount === "") {
    validationError = "Payment amount is required.";
  } else if (!isValidNum || !Number.isFinite(enteredAmount) || enteredAmount <= 0) {
    validationError = "Enter a valid positive numeric amount.";
  } else if (remainingBalance <= 0) {
    validationError = "This BnB charge is already paid in full.";
  } else if (exceedsBalance) {
    validationError = `Amount entered (${formatMoney(enteredAmount, currency)}) exceeds the remaining ${
      form.payment_type === "damage_deposit" ? "damage deposit" : "accommodation"
    } balance (${formatMoney(remainingBalance, currency)}).${
      ratio >= 5 ? " Check for an accidental extra zero." : ""
    }`;
  } else if (trimmedRef === "") {
    validationError = "Transaction reference / supporting evidence is required.";
  }

  function handleProceed(e) {
    e.preventDefault();
    if (validationError) return;
    setEvidenceVerified(false);
    setStep("confirm");
  }

  function handleFinalSubmit() {
    if (!evidenceVerified) return;
    onSave({
      ...form,
      transaction_reference: trimmedRef,
    });
  }

  const methodLabels = {
    mobile_money: "M-Pesa / Mobile Money",
    bank_transfer: "Bank Transfer / EFT",
    cash: "Cash",
    credit_card: "Card",
  };

  return (
    <Modal
      title={step === "confirm" ? "Confirm BnB payment" : "Record BnB Payment"}
      description={
        step === "confirm"
          ? `Pending confirmation — Verify payment for guest ${booking.guest_name} (${booking.property_title}).`
          : `Record payment for guest ${booking.guest_name} (${booking.property_title}).`
      }
      onClose={onClose}
      size="max-w-lg"
    >
      {step === "confirm" ? (
        <div className="space-y-4 p-5 sm:p-7">
          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">
              Amount to record ({form.payment_type === "damage_deposit" ? "Security / Damage Deposit" : "Accommodation Charge"})
            </p>
            <p className="mt-1 text-2xl font-extrabold text-[#0F2A43]">
              {formatMoney(enteredAmount, currency)}
            </p>
            <div className="mt-3 divide-y divide-slate-200/80 text-xs">
              <div className="flex justify-between py-2">
                <span className="text-slate-500">Payment method</span>
                <span className="font-semibold text-[#0F2A43]">
                  {methodLabels[form.payment_method] || form.payment_method}
                </span>
              </div>
              <div className="flex justify-between py-2">
                <span className="text-slate-500">Transaction reference</span>
                <span className="font-mono font-bold text-[#0F2A43]">{trimmedRef}</span>
              </div>
              <div className="flex justify-between py-2">
                <span className="text-slate-500">Outstanding before payment</span>
                <span className="font-semibold text-slate-700">{formatMoney(remainingBalance, currency)}</span>
              </div>
              <div className="flex justify-between py-2">
                <span className="text-slate-500">Outstanding after payment</span>
                <span className="font-bold text-emerald-700">
                  {formatMoney(Math.max(0, remainingBalance - enteredAmount), currency)}
                </span>
              </div>
            </div>
          </div>

          <label className="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 bg-white p-3 text-xs text-slate-700">
            <input
              type="checkbox"
              checked={evidenceVerified}
              onChange={(e) => setEvidenceVerified(e.target.checked)}
              className="mt-0.5 h-4 w-4 rounded border-slate-300 text-[#0F2A43]"
            />
            <span>
              <strong>Are you sure the amount matches the payment evidence?</strong> I have verified{" "}
              <strong>{formatMoney(enteredAmount, currency)}</strong> and reference{" "}
              <strong className="font-mono">{trimmedRef}</strong> before recording.
            </span>
          </label>

          <div className="flex justify-end gap-3 border-t border-slate-100 pt-4">
            <button
              type="button"
              onClick={() => setStep("entry")}
              className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50"
            >
              Back
            </button>
            <button
              type="button"
              onClick={handleFinalSubmit}
              disabled={!evidenceVerified}
              className="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50"
            >
              Confirm &amp; Record Payment
            </button>
          </div>
        </div>
      ) : (
        <form onSubmit={handleProceed} className="space-y-4 p-5 sm:p-7">
          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-3.5">
            <p className="text-xs font-bold uppercase tracking-wider text-[#0F2A43]">
              Automatic BnB Reservation Balance Breakdown
            </p>
            <div className="mt-2.5 grid grid-cols-3 gap-2 text-xs">
              <div>
                <p className="text-slate-500">Original charge</p>
                <p className="mt-0.5 font-bold text-[#0F2A43]">{formatMoney(originalDue, currency)}</p>
              </div>
              <div>
                <p className="text-slate-500">Previously paid</p>
                <p className="mt-0.5 font-bold text-emerald-700">{formatMoney(previouslyPaid, currency)}</p>
              </div>
              <div>
                <p className="text-slate-500">Remaining balance</p>
                <p className="mt-0.5 font-extrabold text-amber-800">{formatMoney(remainingBalance, currency)}</p>
              </div>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <label className="text-sm font-semibold text-slate-700">
              Payment Category
              <select
                value={form.payment_type}
                onChange={(e) =>
                  setForm({
                    ...form,
                    payment_type: e.target.value,
                    amount: String(
                      e.target.value === "damage_deposit"
                        ? damageBalance > 0
                          ? damageBalance
                          : damageTotal
                        : accommBalance > 0
                          ? accommBalance
                          : accommTotal
                    ),
                  })
                }
                className={`${inputClass} mt-2`}
              >
                <option value="accommodation">Accommodation Charge</option>
                <option value="damage_deposit">Security / Damage Deposit</option>
              </select>
            </label>

            <label className="text-sm font-semibold text-slate-700">
              Amount ({currency})
              <input
                required
                type="text"
                inputMode="decimal"
                value={form.amount}
                onChange={(e) => setForm({ ...form, amount: e.target.value })}
                className={`${inputClass} mt-2 font-bold`}
              />
            </label>

            <label className="text-sm font-semibold text-slate-700">
              Payment Method
              <select
                value={form.payment_method}
                onChange={(e) => setForm({ ...form, payment_method: e.target.value })}
                className={`${inputClass} mt-2`}
              >
                <option value="mobile_money">M-Pesa / Mobile Money</option>
                <option value="bank_transfer">Bank Transfer / EFT</option>
                <option value="cash">Cash</option>
                <option value="credit_card">Card</option>
              </select>
            </label>

            <label className="text-sm font-semibold text-slate-700">
              Payment Date
              <input
                required
                type="datetime-local"
                value={form.payment_date}
                onChange={(e) => setForm({ ...form, payment_date: e.target.value })}
                className={`${inputClass} mt-2`}
              />
            </label>
          </div>

          <label className="block text-sm font-semibold text-slate-700">
            Transaction Reference / Provider Slip <span className="text-red-600">*</span>
            <input
              required
              placeholder="e.g. MPESA Ref or Card Auth Code"
              value={form.transaction_reference}
              onChange={(e) => setForm({ ...form, transaction_reference: e.target.value })}
              className={`${inputClass} mt-2 font-mono`}
            />
          </label>

          {validationError && (
            <div className="rounded-xl border border-red-200 bg-red-50 px-3.5 py-2.5 text-xs font-semibold text-red-700">
              {validationError}
            </div>
          )}

          <div className="flex justify-end gap-3 border-t border-slate-100 pt-5">
            <button
              type="button"
              onClick={onClose}
              className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={Boolean(validationError)}
              className="rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60] disabled:opacity-50"
            >
              Verify Before Recording
            </button>
          </div>
        </form>
      )}
    </Modal>
  );
}

function ReceiptModal({ payment, companyProfile, onClose }) {
  const logoUrl = companyLogoUrl(companyProfile?.logo_url);
  return (
    <Modal
      title="Official Payment Receipt"
      description={`Receipt reference: ${payment.receipt_number || payment.transaction_reference}`}
      onClose={onClose}
      size="max-w-md"
    >
      <div className="print-receipt space-y-4 p-6 sm:p-8">
        <div className="border-b border-slate-200 pb-4 text-center">
          {logoUrl && <img src={logoUrl} alt="" className="mx-auto mb-2 h-14 w-14 rounded-lg object-contain" />}
          <p className="text-xs font-bold uppercase tracking-widest text-[#B88912]">{companyProfile?.company_name || "Real Estate Management"}</p>
          <h3 className="mt-1 text-2xl font-bold text-[#0F2A43]">Payment Receipt</h3>
          <p className="font-mono text-xs text-slate-400 mt-1">{payment.receipt_number}</p>
        </div>

        <div className="space-y-2 text-sm divide-y divide-slate-100">
          <div className="flex justify-between py-1.5">
            <span className="text-slate-500">Payer</span>
            <span className="font-semibold text-slate-800">{payment.client_name || payment.buyer_name || "Guest / Tenant"}</span>
          </div>
          <div className="flex justify-between py-1.5">
            <span className="text-slate-500">Scope</span>
            <span className="font-semibold capitalize text-[#0F2A43]">{payment.transaction_scope || "Payment"}</span>
          </div>
          <div className="flex justify-between py-1.5">
            <span className="text-slate-500">Payment Type</span>
            <span className="font-semibold capitalize text-slate-800">{(payment.payment_type || "").replace("_", " ")}</span>
          </div>
          <div className="flex justify-between py-1.5">
            <span className="text-slate-500">Payment Method</span>
            <span className="font-semibold capitalize text-slate-800">{(payment.payment_method || "").replace("_", " ")}</span>
          </div>
          <div className="flex justify-between py-1.5">
            <span className="text-slate-500">Date</span>
            <span className="font-semibold text-slate-800">{formatDate(payment.payment_date)}</span>
          </div>
        </div>

        <div className="rounded-xl bg-slate-50 p-4 flex justify-between items-center border border-slate-200">
          <span className="font-bold text-[#0F2A43]">Amount Cleared</span>
          <span className="text-xl font-extrabold text-[#0F2A43]">
            {formatMoney(payment.amount, payment.currency || "KES")}
          </span>
        </div>

        <div className="flex gap-3 pt-3">
          <button
            type="button"
            onClick={() => printDocument("receipt")}
            className="w-full rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#173e60] print:hidden"
          >
            Print Receipt
          </button>
        </div>
      </div>
    </Modal>
  );
}

function AccountLedgerModal({ account, companyProfile, onClose, onViewReceipt }) {
  const [payments, setPayments] = useState([]);
  const [loading, setLoading] = useState(true);
  const logoUrl = companyLogoUrl(companyProfile?.logo_url);

  useEffect(() => {
    let active = true;
    setLoading(true);
    const fetchFn =
      account.type === "tenancy"
        ? getTenancyPayments(account.id)
        : getBookingPayments(account.id);

    fetchFn
      .then((res) => {
        if (!active) return;
        const rows = res.data?.data || res.data?.payments || (Array.isArray(res.data) ? res.data : []);
        setPayments(rows);
      })
      .catch(() => {
        if (active) setPayments([]);
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [account.id, account.type]);

  return (
    <Modal
      title={`${account.type === "tenancy" ? "Tenancy" : "BnB Guest"} Payment Ledger`}
      description={`${account.payerName} · ${account.title}`}
      onClose={onClose}
      size="max-w-xl"
    >
      <div className="print-statement space-y-4 p-5 sm:p-7">
        <div className="flex items-center gap-3 border-b border-slate-200 pb-4">
          {logoUrl && (
            <img src={logoUrl} alt="" className="h-12 w-12 rounded-lg object-contain" />
          )}
          <div>
            <p className="text-xs font-bold uppercase tracking-wider text-[#B88912]">{companyProfile?.company_name || "Real Estate Management"}</p>
            <p className="mt-1 text-xs text-slate-500">Account payment statement</p>
            <p className="mt-1 text-sm font-semibold text-[#0F2A43]">{account.payerName} · {account.title}</p>
          </div>
        </div>
        <div className="rounded-2xl bg-slate-50 p-4 border border-slate-200/80 space-y-2 text-sm">
          <div className="flex justify-between">
            <span className="text-slate-500">Account Holder</span>
            <span className="font-semibold text-[#0F2A43]">
              {account.payerName} ({account.payerContact || "—"})
            </span>
          </div>
          <div className="flex justify-between">
            <span className="text-slate-500">Total Billed</span>
            <span className="font-semibold text-slate-800">
              {formatMoney(account.totalBilled, account.currency)}
            </span>
          </div>
          <div className="flex justify-between">
            <span className="text-slate-500">Total Collected</span>
            <span className="font-bold text-emerald-700">
              {formatMoney(account.totalPaid, account.currency)}
            </span>
          </div>
          <div className="flex justify-between border-t border-slate-200 pt-2">
            <span className="font-bold text-[#0F2A43]">Outstanding Balance Due</span>
            <span
              className={`font-extrabold ${
                account.balanceDue > 0 ? "text-red-600" : "text-emerald-700"
              }`}
            >
              {formatMoney(account.balanceDue, account.currency)}
            </span>
          </div>
        </div>

        <div>
          <h3 className="text-sm font-bold text-[#0F2A43] mb-2">Recorded Payments &amp; Receipts</h3>
          {loading ? (
            <p className="py-6 text-center text-xs text-slate-400">Loading payment history...</p>
          ) : payments.length === 0 ? (
            <div className="rounded-xl border border-dashed border-slate-200 p-6 text-center text-xs text-slate-500">
              No payments have been recorded for this account yet.
            </div>
          ) : (
            <div className="max-h-60 overflow-y-auto divide-y divide-slate-100 rounded-xl border border-slate-100 print-ledger-list">
              {payments.map((pmt) => (
                <div
                  key={pmt.id}
                  className="flex items-center justify-between gap-3 px-4 py-3 text-xs hover:bg-slate-50"
                >
                  <div>
                    <p className="font-semibold text-[#0F2A43] capitalize">
                      {(pmt.payment_type || "payment").replace("_", " ")} ·{" "}
                      {(pmt.payment_method || "cash").replace("_", " ")}
                    </p>
                    <p className="text-slate-400 mt-0.5">
                      {formatDate(pmt.payment_date)} ·{" "}
                      {pmt.receipt_number || pmt.transaction_reference || `PAY-${pmt.id}`}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="font-bold text-[#0F2A43]">
                      {formatMoney(pmt.amount, pmt.currency || account.currency)}
                    </span>
                    <button
                      type="button"
                      onClick={() =>
                        onViewReceipt({
                          ...pmt,
                          client_name: pmt.client_name || account.payerName,
                          currency: pmt.currency || account.currency,
                        })
                      }
                      className="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] font-semibold text-[#0F2A43] hover:bg-slate-100"
                    >
                      Receipt
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="flex justify-end gap-3 border-t border-slate-100 pt-4 print:hidden">
          <button
            type="button"
            onClick={() => printDocument("statement")}
            className="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-semibold text-[#0F2A43] hover:bg-slate-50"
          >
            Print / Save PDF
          </button>
          <button
            type="button"
            onClick={onClose}
            className="rounded-xl bg-[#0F2A43] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#173e60]"
          >
            Close
          </button>
        </div>
      </div>
    </Modal>
  );
}
