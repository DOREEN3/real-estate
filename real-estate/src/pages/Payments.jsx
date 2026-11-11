import { useCallback, useEffect, useState } from "react";
import {
  AlertTriangle,
  ArrowDownLeft,
  BarChart3,
  BellRing,
  CalendarClock,
  CheckCircle2,
  Download,
  FileSpreadsheet,
  FileText,
  Filter,
  Lock,
  Plus,
  Printer,
  ReceiptText,
  RefreshCw,
  ShieldAlert,
  ShieldCheck,
} from "lucide-react";
import Modal from "../components/Modal";
import PageHeading from "../components/PageHeading";
import { getInstallmentsReport, getPaymentsReport, getSaleStatement } from "../api/payments";
import { getSaleInstallments } from "../api/sales";
import { getTenancies } from "../api/rentals";
import { getBookings } from "../api/bookings";
import { createExpense, getAnnualProfitLoss, getExpenses } from "../api/finance";
import { companyLogoUrl } from "../api/companyProfile";
import { formatCurrencyTotals, formatDate, formatMoney, inputClass, printDocument } from "../lib/utils";

const expenseCategories = [
  ["operations", "Operations"],
  ["maintenance", "Maintenance"],
  ["marketing", "Marketing"],
  ["utilities", "Utilities"],
  ["taxes", "Taxes"],
  ["staff", "Staff"],
  ["other", "Other"],
];

function CompanyBrand({ profile, compact = false }) {
  const logo = companyLogoUrl(profile?.logo_url);
  return (
    <div className={`flex items-start justify-between gap-4 ${compact ? "" : "border-b border-slate-200 pb-4 mb-4"}`}>
      <div className="flex items-center gap-3">
        {logo ? (
          <img
            src={logo}
            alt=""
            className={`${compact ? "h-10 w-10" : "h-14 w-14"} rounded-lg border border-slate-100 bg-white object-contain p-1`}
          />
        ) : (
          <div className={`${compact ? "h-10 w-10" : "h-12 w-12"} flex items-center justify-center rounded-lg bg-[#0F2A43] text-white font-bold text-sm`}>
            ERP
          </div>
        )}
        <div>
          <p className="text-xs font-bold uppercase tracking-wider text-[#B88912]">
            {profile?.company_name || "GoldERP Real Estate"}
          </p>
          {profile?.physical_address && (
            <p className="text-[11px] text-slate-500">
              {profile.physical_address}{profile.city ? `, ${profile.city}` : ""}
            </p>
          )}
          {(profile?.phone_number || profile?.whatsapp_number || profile?.email) && (
            <p className="text-[11px] text-slate-500">
              {profile.phone_number ? `Tel: ${profile.phone_number}` : ""}
              {profile.phone_number && profile.whatsapp_number ? " · " : ""}
              {profile.whatsapp_number ? `WhatsApp: ${profile.whatsapp_number}` : ""}
              {(profile.phone_number || profile.whatsapp_number) && profile.email ? " · " : ""}
              {profile.email ? profile.email : ""}
            </p>
          )}
        </div>
      </div>
      {profile?.kra_pin && (
        <div className="text-right">
          <span className="inline-block rounded border border-slate-300 bg-slate-50 px-2 py-0.5 font-mono text-[11px] font-bold text-slate-800">
            KRA PIN: {profile.kra_pin}
          </span>
          <p className="mt-0.5 text-[10px] text-slate-400">Official Document / Tax Receipt</p>
        </div>
      )}
    </div>
  );
}

function ExpenseEditor({ year, onClose, onSave }) {
  const today = new Date().toISOString().slice(0, 10);
  const defaultDate = year === new Date().getFullYear() ? today : `${year}-01-01`;
  const [form, setForm] = useState({
    expense_date: defaultDate,
    category: "operations",
    vendor: "",
    description: "",
    amount: "",
    currency: "KES",
  });
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function submit(event) {
    event.preventDefault();
    setError("");
    setSaving(true);
    try {
      const result = await onSave(form);
      if (!result?.success) setError(result?.message || "Unable to record this expense.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal title="Record business expense" description={`Add a manual expense to the ${year} profit and loss report.`} onClose={onClose} size="max-w-xl">
      <form onSubmit={submit} className="space-y-4 p-5 sm:p-7">
        {error && <p role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="text-sm font-semibold text-slate-700">
            Expense date
            <input required type="date" min={`${year}-01-01`} max={`${year}-12-31`} value={form.expense_date} onChange={(event) => setForm({ ...form, expense_date: event.target.value })} className={`${inputClass} mt-2`} />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Category
            <select value={form.category} onChange={(event) => setForm({ ...form, category: event.target.value })} className={`${inputClass} mt-2`}>
              {expenseCategories.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </label>
          <label className="text-sm font-semibold text-slate-700 sm:col-span-2">
            Description
            <input required minLength={2} maxLength={500} value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} className={`${inputClass} mt-2`} placeholder="What was this expense for?" />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Vendor (optional)
            <input maxLength={150} value={form.vendor} onChange={(event) => setForm({ ...form, vendor: event.target.value })} className={`${inputClass} mt-2`} />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Amount
            <input required type="number" min="0.01" step="0.01" value={form.amount} onChange={(event) => setForm({ ...form, amount: event.target.value })} className={`${inputClass} mt-2`} />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Currency
            <input required minLength={3} maxLength={10} pattern="[A-Za-z]{3,10}" value={form.currency} onChange={(event) => setForm({ ...form, currency: event.target.value.toUpperCase() })} className={`${inputClass} mt-2`} />
          </label>
        </div>
        <div className="flex justify-end gap-3 border-t border-slate-100 pt-4">
          <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600">Cancel</button>
          <button type="submit" disabled={saving} className="rounded-xl bg-[#0F2A43] px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{saving ? "Saving..." : "Record expense"}</button>
        </div>
      </form>
    </Modal>
  );
}

function PaymentEditor({ sales, existingPayments = [], user, initialSaleId, onClose, onSave }) {
  const defaultSale = sales.find((sale) => Number(sale.id) === Number(initialSaleId)) || sales[0];
  const [form, setForm] = useState({
    saleId: defaultSale?.id ? String(defaultSale.id) : "",
    installmentId: "",
    amount: "",
    method: "M-Pesa",
    date: new Date().toISOString().slice(0, 10),
    note: "",
  });
  const [installments, setInstallments] = useState([]);
  const [loadingInstallments, setLoadingInstallments] = useState(false);
  const [step, setStep] = useState("entry"); // 'entry' | 'confirm'
  const [authorizeOverpayment, setAuthorizeOverpayment] = useState(false);
  const [evidenceVerified, setEvidenceVerified] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState("");

  const selectedSale = sales.find((sale) => String(sale.id) === String(form.saleId));
  const currency = selectedSale?.currency || "KES";

  // Calculate total sale paid & remaining balance
  const totalSalePaid = existingPayments
    .filter((p) => Number(p.saleId) === Number(selectedSale?.id))
    .reduce((sum, p) => sum + Number(p.amount || 0), 0);
  const totalSaleBalance = Math.max(0, Number(selectedSale?.salePrice || 0) - totalSalePaid);

  // Load installments automatically when sale changes
  useEffect(() => {
    let active = true;
    async function fetchInst() {
      if (!form.saleId) {
        setInstallments([]);
        return;
      }
      setLoadingInstallments(true);
      try {
        const res = await getSaleInstallments(Number(form.saleId));
        if (!active) return;
        const list = res.success && Array.isArray(res.data?.data) ? res.data.data : [];
        setInstallments(list);
        const firstUnpaid = list.find(
          (inst) => Number(inst.outstanding_balance ?? inst.amount_due) > 0
        );
        if (firstUnpaid) {
          const rem = Number(firstUnpaid.outstanding_balance ?? firstUnpaid.amount_due);
          setForm((prev) => ({
            ...prev,
            installmentId: String(firstUnpaid.id),
            amount: String(rem),
          }));
        } else {
          setForm((prev) => ({
            ...prev,
            installmentId: "",
            amount: totalSaleBalance > 0 ? String(totalSaleBalance) : "",
          }));
        }
        setAuthorizeOverpayment(false);
        setEvidenceVerified(false);
        setStep("entry");
        setSubmitError("");
      } finally {
        if (active) setLoadingInstallments(false);
      }
    }
    fetchInst();
    return () => {
      active = false;
    };
  }, [form.saleId, totalSaleBalance]);

  const selectedInstallment = installments.find(
    (inst) => String(inst.id) === String(form.installmentId)
  );

  // Automatic balance calculation figures
  const hasInstallmentSchedule = installments.length > 0;
  const originalDue = selectedInstallment
    ? Number(selectedInstallment.amount_due || 0)
    : Number(selectedSale?.salePrice || 0);
  const remainingBalance = selectedInstallment
    ? Math.max(0, Number(selectedInstallment.outstanding_balance ?? selectedInstallment.amount_due ?? 0))
    : totalSaleBalance;
  const previouslyPaid = Math.max(0, originalDue - remainingBalance);

  // Strict amount & reference validations
  const rawAmountStr = String(form.amount ?? "").trim();
  const isValidNumberFormat = /^\d+(\.\d{1,2})?$/.test(rawAmountStr);
  const enteredAmount = isValidNumberFormat ? Number(rawAmountStr) : NaN;

  const trimmedRef = String(form.note ?? "").trim();
  const isDuplicateRef =
    trimmedRef !== "" &&
    existingPayments.some(
      (p) =>
        (p.note && p.note.toLowerCase() === trimmedRef.toLowerCase()) ||
        (p.reference && p.reference.toLowerCase() === trimmedRef.toLowerCase())
    );

  const isSelectedInstallmentPaid =
    selectedInstallment && remainingBalance <= 0;
  const exceedsSaleBalance =
    Number.isFinite(enteredAmount) && enteredAmount > totalSaleBalance + 0.005;
  const exceedsInstallmentBalance =
    hasInstallmentSchedule &&
    selectedInstallment &&
    Number.isFinite(enteredAmount) &&
    enteredAmount > remainingBalance + 0.005 &&
    !exceedsSaleBalance;

  // Unusual / 10x extra-zero detection
  const ratioToExpected =
    remainingBalance > 0 && Number.isFinite(enteredAmount)
      ? enteredAmount / remainingBalance
      : 0;
  const isSuspiciousTenX = ratioToExpected >= 5;
  const isUnusuallyLarge = ratioToExpected >= 2;

  let validationError = "";
  if (rawAmountStr === "") {
    validationError = "Payment amount is required.";
  } else if (!isValidNumberFormat || !Number.isFinite(enteredAmount)) {
    validationError = "Enter a valid positive numeric amount (no letters or invalid characters).";
  } else if (enteredAmount <= 0) {
    validationError = "Payment amount must be greater than zero.";
  } else if (totalSaleBalance <= 0) {
    validationError = "This sale account is already paid in full.";
  } else if (isSelectedInstallmentPaid) {
    validationError = "Payment cannot be allocated to an already fully paid installment.";
  } else if (exceedsSaleBalance) {
    validationError = `Amount entered (${formatMoney(enteredAmount, currency)}) exceeds the total remaining sale balance (${formatMoney(totalSaleBalance, currency)}). Check for an accidental extra zero.`;
  } else if (trimmedRef === "") {
    validationError = "Transaction reference / supporting evidence (e.g. M-Pesa code, bank ref, or voucher #) is required.";
  } else if (isDuplicateRef) {
    validationError = `Duplicate transaction reference "${trimmedRef}". A payment with this reference is already recorded.`;
  } else if (exceedsInstallmentBalance && !authorizeOverpayment) {
    validationError = "Amount exceeds the selected installment balance. Check the advance-payment authorization box below or correct the amount.";
  }

  function handleProceedToConfirm(event) {
    event.preventDefault();
    setSubmitError("");
    if (validationError) return;
    setEvidenceVerified(false);
    setStep("confirm");
  }

  async function handleFinalConfirm() {
    if (!evidenceVerified || submitting) return;
    setSubmitting(true);
    setSubmitError("");
    try {
      const paymentType = selectedInstallment
        ? Number(selectedInstallment.installment_number) === 0
          ? "deposit"
          : "installment"
        : "full";
      const res = await onSave({
        ...form,
        saleId: Number(form.saleId),
        installmentId: selectedInstallment ? Number(selectedInstallment.id) : null,
        paymentType,
        confirmOverpayment: exceedsInstallmentBalance && authorizeOverpayment,
      });
      if (res === false || (res && res.success === false)) {
        setSubmitError(res?.message || "Payment rejected by server validation.");
        return;
      }
      onClose();
    } finally {
      setSubmitting(false);
    }
  }

  const outstandingBefore = exceedsInstallmentBalance ? totalSaleBalance : remainingBalance;
  const outstandingAfter = Math.max(0, outstandingBefore - (Number.isFinite(enteredAmount) ? enteredAmount : 0));

  return (
    <Modal
      title={step === "confirm" ? "Confirm payment" : "Record a payment"}
      description={
        step === "confirm"
          ? "Pending confirmation — Review the payment allocation and verify against supporting evidence before recording."
          : "System automatically calculates installment balances and validates against overpayments or duplicate references."
      }
      onClose={onClose}
      size="max-w-xl"
    >
      {sales.length === 0 ? (
        <div className="p-5 sm:p-7">
          <p className="rounded-xl bg-amber-50 p-4 text-sm text-amber-800">
            Register a sale before recording payments.
          </p>
        </div>
      ) : step === "confirm" ? (
        <div className="space-y-5 p-5 sm:p-7">
          <div className="flex items-center justify-between rounded-2xl border border-amber-200 bg-amber-50/80 px-4 py-3">
            <div className="flex items-center gap-2.5">
              <ShieldCheck size={20} className="text-amber-800" />
              <div>
                <p className="text-xs font-bold uppercase tracking-wider text-amber-900">
                  Pending confirmation
                </p>
                <p className="text-xs text-amber-800">
                  {selectedSale?.buyerName} · {selectedSale?.propertyTitle}
                </p>
              </div>
            </div>
            <span className="rounded-full bg-white px-3 py-1 text-xs font-bold text-[#0F2A43] shadow-sm">
              {selectedInstallment
                ? Number(selectedInstallment.installment_number) === 0
                  ? "Deposit (#0)"
                  : `Installment #${selectedInstallment.installment_number}`
                : "Full Sale Account"}
            </span>
          </div>

          {isUnusuallyLarge && (
            <div className="rounded-2xl border border-red-200 bg-red-50 p-4 text-xs text-red-900">
              <div className="flex items-start gap-2.5">
                <ShieldAlert size={18} className="mt-0.5 shrink-0 text-red-600" />
                <div>
                  <p className="font-bold uppercase tracking-wider text-red-800">
                    Unusual payment amount ({ratioToExpected.toFixed(1)}× installment balance)
                  </p>
                  <p className="mt-1">
                    Installment balance is <strong>{formatMoney(remainingBalance, currency)}</strong>, and you entered{" "}
                    <strong>{formatMoney(enteredAmount, currency)}</strong>. Excess{" "}
                    <strong>{formatMoney(enteredAmount - remainingBalance, currency)}</strong> will be allocated as an advance payment toward subsequent installments.
                  </p>
                </div>
              </div>
            </div>
          )}

          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-5">
            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">
              Amount to record
            </p>
            <p className="mt-1 text-3xl font-extrabold text-[#0F2A43]">
              {formatMoney(enteredAmount, currency)}
            </p>

            <div className="mt-4 divide-y divide-slate-200/80 text-sm">
              <div className="flex justify-between py-2.5">
                <span className="text-slate-500">Payment method</span>
                <span className="font-semibold text-[#0F2A43]">{form.method}</span>
              </div>
              <div className="flex justify-between py-2.5">
                <span className="text-slate-500">Transaction reference</span>
                <span className="font-mono font-bold text-[#0F2A43]">{trimmedRef}</span>
              </div>
              <div className="flex justify-between py-2.5">
                <span className="text-slate-500">
                  {exceedsInstallmentBalance ? "Sale balance before payment" : "Outstanding before payment"}
                </span>
                <span className="font-semibold text-slate-700">
                  {formatMoney(outstandingBefore, currency)}
                </span>
              </div>
              <div className="flex justify-between py-2.5">
                <span className="text-slate-500">
                  {exceedsInstallmentBalance ? "Sale balance after payment" : "Outstanding after payment"}
                </span>
                <span className="font-bold text-emerald-700">
                  {formatMoney(outstandingAfter, currency)}
                </span>
              </div>
              <div className="flex justify-between py-2.5">
                <span className="text-slate-500">Recorded by (Audit trail)</span>
                <span className="font-semibold text-[#0F2A43]">
                  {user?.name || "System Administrator"}
                </span>
              </div>
            </div>
          </div>

          <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-3.5 text-xs text-slate-700 hover:bg-slate-50">
            <input
              type="checkbox"
              checked={evidenceVerified}
              onChange={(e) => setEvidenceVerified(e.target.checked)}
              className="mt-0.5 h-4 w-4 rounded border-slate-300 text-[#0F2A43]"
            />
            <span>
              <strong>Are you sure the amount matches the payment evidence?</strong> I have verified{" "}
              <strong>{formatMoney(enteredAmount, currency)}</strong> and reference{" "}
              <strong className="font-mono">{trimmedRef}</strong> against the bank confirmation, M-Pesa receipt, or cash voucher. Once confirmed, this payment is locked in the audit trail.
            </span>
          </label>

          {submitError && (
            <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-semibold text-red-700">
              {submitError}
            </div>
          )}

          <div className="flex justify-end gap-3 border-t border-slate-100 pt-4">
            <button
              type="button"
              onClick={() => setStep("entry")}
              disabled={submitting}
              className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50"
            >
              Back to edit
            </button>
            <button
              type="button"
              onClick={handleFinalConfirm}
              disabled={!evidenceVerified || submitting}
              className="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50"
            >
              <CheckCircle2 size={16} />
              {submitting ? "Recording..." : "Confirm & record payment"}
            </button>
          </div>
        </div>
      ) : (
        <form onSubmit={handleProceedToConfirm} className="space-y-4 p-5 sm:p-7">
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="block text-sm font-semibold text-slate-700 sm:col-span-2">
              Sale account
              <select
                value={form.saleId}
                onChange={(event) => setForm({ ...form, saleId: event.target.value })}
                className={`${inputClass} mt-2`}
              >
                {sales.map((sale) => (
                  <option key={sale.id} value={sale.id}>
                    {sale.buyerName} · {sale.propertyTitle}
                  </option>
                ))}
              </select>
            </label>

            {hasInstallmentSchedule && (
              <label className="block text-sm font-semibold text-slate-700 sm:col-span-2">
                Installment being paid
                <select
                  value={form.installmentId}
                  onChange={(event) => {
                    const inst = installments.find((i) => String(i.id) === event.target.value);
                    const rem = inst
                      ? Math.max(0, Number(inst.outstanding_balance ?? inst.amount_due ?? 0))
                      : 0;
                    setForm({
                      ...form,
                      installmentId: event.target.value,
                      amount: rem > 0 ? String(rem) : form.amount,
                    });
                    setAuthorizeOverpayment(false);
                  }}
                  className={`${inputClass} mt-2`}
                >
                  {installments.map((inst) => {
                    const rem = Math.max(0, Number(inst.outstanding_balance ?? inst.amount_due ?? 0));
                    const isPaid = rem <= 0 || inst.status === "paid";
                    const label =
                      Number(inst.installment_number) === 0
                        ? "Deposit (#0)"
                        : `Installment #${inst.installment_number}`;
                    return (
                      <option key={inst.id} value={inst.id} disabled={isPaid}>
                        {label} · Due {formatDate(inst.due_date)} ·{" "}
                        {isPaid
                          ? `PAID (${formatMoney(inst.amount_due, currency)})`
                          : `Remaining: ${formatMoney(rem, currency)}`}
                      </option>
                    );
                  })}
                </select>
              </label>
            )}
          </div>

          {/* Automatic Expected Amount & Balance Breakdown */}
          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200/80 pb-2.5">
              <span className="text-xs font-bold uppercase tracking-wider text-[#0F2A43]">
                {selectedInstallment
                  ? Number(selectedInstallment.installment_number) === 0
                    ? "Deposit Payment Breakdown"
                    : `Installment #${selectedInstallment.installment_number} Payment Breakdown`
                  : "Sale Account Balance Breakdown"}
              </span>
              {loadingInstallments ? (
                <span className="text-xs text-slate-400">Calculating...</span>
              ) : (
                <span className="text-xs font-semibold text-slate-500">
                  Total sale balance: {formatMoney(totalSaleBalance, currency)}
                </span>
              )}
            </div>
            <div className="mt-3 grid grid-cols-3 gap-3 text-center sm:text-left">
              <div>
                <p className="text-[11px] font-medium text-slate-500">
                  {hasInstallmentSchedule ? "Installment amount" : "Agreed sale price"}
                </p>
                <p className="mt-0.5 text-sm font-bold text-[#0F2A43]">
                  {formatMoney(originalDue, currency)}
                </p>
              </div>
              <div>
                <p className="text-[11px] font-medium text-slate-500">Previously paid</p>
                <p className="mt-0.5 text-sm font-bold text-emerald-700">
                  {formatMoney(previouslyPaid, currency)}
                </p>
              </div>
              <div>
                <p className="text-[11px] font-medium text-slate-500">Remaining balance</p>
                <p className="mt-0.5 text-sm font-extrabold text-amber-800">
                  {formatMoney(remainingBalance, currency)}
                </p>
              </div>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <div className="flex items-center justify-between">
                <label className="text-sm font-semibold text-slate-700">
                  Amount received ({currency})
                </label>
                {remainingBalance > 0 && Number(form.amount) !== remainingBalance && (
                  <button
                    type="button"
                    onClick={() => {
                      setForm({ ...form, amount: String(remainingBalance) });
                      setAuthorizeOverpayment(false);
                    }}
                    className="text-[11px] font-bold text-[#0F2A43] underline hover:text-[#B88912]"
                  >
                    Use {formatMoney(remainingBalance, currency)}
                  </button>
                )}
              </div>
              <input
                required
                type="text"
                inputMode="decimal"
                value={form.amount}
                onChange={(event) => setForm({ ...form, amount: event.target.value })}
                placeholder={remainingBalance > 0 ? String(remainingBalance) : "0.00"}
                className={`${inputClass} mt-2 font-bold`}
              />
            </div>

            <label className="text-sm font-semibold text-slate-700">
              Payment date
              <input
                required
                type="date"
                value={form.date}
                onChange={(event) => setForm({ ...form, date: event.target.value })}
                className={`${inputClass} mt-2`}
              />
            </label>

            <label className="text-sm font-semibold text-slate-700">
              Payment method
              <select
                value={form.method}
                onChange={(event) => setForm({ ...form, method: event.target.value })}
                className={`${inputClass} mt-2`}
              >
                {["M-Pesa", "Bank transfer", "Cash"].map((method) => (
                  <option key={method}>{method}</option>
                ))}
              </select>
            </label>

            <label className="text-sm font-semibold text-slate-700">
              Transaction reference / evidence <span className="text-red-600">*</span>
              <input
                required
                value={form.note}
                onChange={(event) => setForm({ ...form, note: event.target.value })}
                className={`${inputClass} mt-2 font-mono`}
                placeholder="e.g. TRX-2026-001 or M-Pesa code"
              />
            </label>
          </div>

          {/* Second-level protection: Suspicious / 10x or Overpayment Detection */}
          {Number.isFinite(enteredAmount) && enteredAmount > remainingBalance + 0.005 && remainingBalance > 0 && (
            <div
              className={`rounded-2xl border p-4 text-xs ${
                exceedsSaleBalance || isSuspiciousTenX
                  ? "border-red-200 bg-red-50 text-red-900"
                  : "border-amber-200 bg-amber-50 text-amber-900"
              }`}
            >
              <div className="flex items-start gap-2.5">
                <AlertTriangle
                  size={18}
                  className={`mt-0.5 shrink-0 ${
                    exceedsSaleBalance || isSuspiciousTenX ? "text-red-600" : "text-amber-700"
                  }`}
                />
                <div className="space-y-1.5">
                  <p className="font-bold uppercase tracking-wider">
                    {isSuspiciousTenX
                      ? `Unusual payment amount — ${ratioToExpected.toFixed(1)}× the outstanding balance`
                      : exceedsSaleBalance
                        ? "Payment exceeds total outstanding sale balance"
                        : "Amount exceeds selected installment balance"}
                  </p>
                  <ul className="list-inside list-disc space-y-0.5 font-medium">
                    <li>Outstanding installment balance: <strong>{formatMoney(remainingBalance, currency)}</strong></li>
                    <li>Amount entered: <strong>{formatMoney(enteredAmount, currency)}</strong></li>
                    <li>Total sale remaining balance: <strong>{formatMoney(totalSaleBalance, currency)}</strong></li>
                  </ul>
                  {!exceedsSaleBalance && (
                    <label className="mt-2 flex cursor-pointer items-start gap-2 rounded-xl border border-amber-300/80 bg-white/90 p-2.5 text-slate-800">
                      <input
                        type="checkbox"
                        checked={authorizeOverpayment}
                        onChange={(e) => setAuthorizeOverpayment(e.target.checked)}
                        className="mt-0.5 h-4 w-4 rounded border-slate-300 text-[#0F2A43]"
                      />
                      <span>
                        <strong>Authorize Advance Allocation:</strong> I have verified the payment evidence and authorize allocating the excess{" "}
                        <strong>{formatMoney(enteredAmount - remainingBalance, currency)}</strong> toward the next unpaid installment(s).
                      </span>
                    </label>
                  )}
                </div>
              </div>
            </div>
          )}

          {validationError && (
            <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-xs font-semibold text-red-700">
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
              disabled={Boolean(validationError) || loadingInstallments}
              className="inline-flex items-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60] disabled:opacity-50"
            >
              <ShieldCheck size={16} /> Verify before recording
            </button>
          </div>
        </form>
      )}
    </Modal>
  );
}

function SaleStatementModal({ saleId, onClose, companyProfile }) {
  const [statement, setStatement] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    async function load() {
      setLoading(true);
      try {
        const res = await getSaleStatement(saleId);
        if (active && res.success && res.data?.statement) {
          setStatement(res.data.statement);
        }
      } finally {
        if (active) setLoading(false);
      }
    }
    load();
    return () => {
      active = false;
    };
  }, [saleId]);

  const currency = statement?.sale?.currency || "KES";

  return (
    <Modal
      title="Official Buyer Account Statement"
      description={
        statement?.sale
          ? `${statement.sale.buyer_name} · ${statement.sale.property_title} (Sale Agreement #${statement.sale.id})`
          : `Sale Agreement #${saleId}`
      }
      onClose={onClose}
      size="max-w-3xl"
    >
      <div className="print-statement space-y-6 p-5 sm:p-7">
        {loading ? (
          <p className="py-8 text-center text-sm text-slate-500">Generating account statement...</p>
        ) : !statement ? (
          <p className="py-8 text-center text-sm text-red-600">Unable to load statement for this sale account.</p>
        ) : (
          <>
            <CompanyBrand profile={companyProfile} />
            <div>
              <h3 className="text-xl font-bold text-[#0F2A43]">Buyer Account Statement</h3>
              <p className="mt-1 text-sm text-slate-600">
                {statement.sale.buyer_name} · {statement.sale.property_title}
              </p>
            </div>
            <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-4">
              <div>
                <p className="text-xs font-semibold text-slate-500">Agreed Sale Price</p>
                <p className="mt-1 text-base font-bold text-[#0F2A43]">
                  {formatMoney(statement.summary.sale_price, currency)}
                </p>
              </div>
              <div>
                <p className="text-xs font-semibold text-slate-500">Total Paid</p>
                <p className="mt-1 text-base font-bold text-emerald-700">
                  {formatMoney(statement.summary.total_paid, currency)}
                </p>
              </div>
              <div>
                <p className="text-xs font-semibold text-slate-500">Outstanding Balance</p>
                <p className="mt-1 text-base font-bold text-amber-800">
                  {formatMoney(statement.summary.outstanding_balance, currency)}
                </p>
              </div>
              <div>
                <p className="text-xs font-semibold text-slate-500">Completion</p>
                <p className="mt-1 text-base font-bold text-[#0F2A43]">
                  {statement.summary.completion_percentage}%
                </p>
                <div className="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-200">
                  <div
                    className="h-full rounded-full bg-emerald-600"
                    style={{ width: `${Math.min(100, statement.summary.completion_percentage)}%` }}
                  />
                </div>
              </div>
            </div>

            {Array.isArray(statement.installments) && statement.installments.length > 0 && (
              <div>
                <h4 className="mb-2.5 text-xs font-bold uppercase tracking-wider text-slate-500">
                  Installment Schedule ({statement.installments.length})
                </h4>
                <div className="overflow-x-auto rounded-xl border border-slate-200">
                  <table className="w-full text-left text-xs">
                    <thead className="bg-slate-50 uppercase text-slate-500">
                      <tr>
                        <th className="px-3.5 py-2.5">#</th>
                        <th className="px-3.5 py-2.5">Due Date</th>
                        <th className="px-3.5 py-2.5">Invoice #</th>
                        <th className="px-3.5 py-2.5">Amount Due</th>
                        <th className="px-3.5 py-2.5">Paid</th>
                        <th className="px-3.5 py-2.5">Status</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {statement.installments.map((inst) => (
                        <tr key={inst.id}>
                          <td className="px-3.5 py-2.5 font-semibold text-[#0F2A43]">
                            {Number(inst.installment_number) === 0 ? "Deposit (#0)" : `Installment #${inst.installment_number}`}
                          </td>
                          <td className="px-3.5 py-2.5 text-slate-600">{formatDate(inst.due_date)}</td>
                          <td className="px-3.5 py-2.5 font-mono text-slate-600">{inst.invoice_number || "—"}</td>
                          <td className="px-3.5 py-2.5 font-semibold text-[#0F2A43]">
                            {formatMoney(inst.amount_due, currency)}
                          </td>
                          <td className="px-3.5 py-2.5 font-semibold text-emerald-700">
                            {formatMoney(inst.amount_paid, currency)}
                          </td>
                          <td className="px-3.5 py-2.5">
                            <span
                              className={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ${
                                inst.status === "paid"
                                  ? "bg-emerald-50 text-emerald-700"
                                  : "bg-amber-50 text-amber-800"
                              }`}
                            >
                              {inst.status}
                            </span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            <div>
              <h4 className="mb-2.5 text-xs font-bold uppercase tracking-wider text-slate-500">
                Payment &amp; Receipt Ledger ({(statement.payments || []).length})
              </h4>
              {Array.isArray(statement.payments) && statement.payments.length > 0 ? (
                <div className="overflow-x-auto rounded-xl border border-slate-200">
                  <table className="w-full text-left text-xs">
                    <thead className="bg-slate-50 uppercase text-slate-500">
                      <tr>
                        <th className="px-3.5 py-2.5">Receipt #</th>
                        <th className="px-3.5 py-2.5">Invoice #</th>
                        <th className="px-3.5 py-2.5">Date</th>
                        <th className="px-3.5 py-2.5">Purpose</th>
                        <th className="px-3.5 py-2.5">Method</th>
                        <th className="px-3.5 py-2.5 text-right">Amount</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {statement.payments.map((pay) => (
                        <tr key={pay.id}>
                          <td className="px-3.5 py-2.5 font-mono font-semibold text-[#0F2A43]">
                            {pay.official_receipt_number || pay.transaction_reference || `PAY-${pay.id}`}
                          </td>
                          <td className="px-3.5 py-2.5 font-mono text-slate-600">{pay.invoice_number || "—"}</td>
                          <td className="px-3.5 py-2.5 text-slate-600">{formatDate(pay.payment_date)}</td>
                          <td className="px-3.5 py-2.5 capitalize text-slate-600">{pay.payment_type}</td>
                          <td className="px-3.5 py-2.5 capitalize text-slate-600">
                            {(pay.payment_method || "cash").replace(/_/g, " ")}
                          </td>
                          <td className="px-3.5 py-2.5 text-right font-bold text-emerald-700">
                            {formatMoney(pay.amount, currency)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <p className="rounded-xl bg-slate-50 p-4 text-xs text-slate-500">No payments recorded yet.</p>
              )}
            </div>

            <div className="flex justify-end gap-3 border-t border-slate-100 pt-4 print:hidden">
              <button
                type="button"
                onClick={() => printDocument("statement")}
                className="inline-flex items-center gap-2 rounded-xl bg-[#0F2A43] px-4 py-2.5 text-xs font-semibold text-white hover:bg-[#173e60]"
              >
                <Printer size={14} /> Print / Save PDF Statement
              </button>
            </div>
          </>
        )}
      </div>
    </Modal>
  );
}

function Payments({ payments, sales, user, onRecordPayment, onSendReminder, highlightedSaleId, initialView = "ledger", companyProfile }) {
  const [activeView, setActiveView] = useState(initialView); // 'ledger' | 'reports'
  const [showEditor, setShowEditor] = useState(false);
  const [selectedReceipt, setSelectedReceipt] = useState(null);
  const [statementSaleId, setStatementSaleId] = useState(null);
  const [scopeFilter, setScopeFilter] = useState("all"); // 'all' | 'sale' | 'rental' | 'bnb'

  // Financial report states
  const [reportFilters, setReportFilters] = useState({
    start_date: "",
    end_date: "",
    payment_method: "",
  });
  const [paymentsReport, setPaymentsReport] = useState(null);
  const [installmentsReport, setInstallmentsReport] = useState(null);
  const [tenancies, setTenancies] = useState([]);
  const [bookings, setBookings] = useState([]);
  const [loadingReports, setLoadingReports] = useState(false);
  const [exportingReport, setExportingReport] = useState(false);
  const [reportExportError, setReportExportError] = useState("");
  const [reportYear, setReportYear] = useState(new Date().getFullYear());
  const [expenses, setExpenses] = useState([]);
  const [expensePagination, setExpensePagination] = useState({ page: 1, total: 0, total_pages: 0 });
  const [expensesPage, setExpensesPage] = useState(1);
  const [profitLoss, setProfitLoss] = useState([]);
  const [printExpenses, setPrintExpenses] = useState(null);
  const [printTransactions, setPrintTransactions] = useState(null);
  const [financeError, setFinanceError] = useState("");
  const [showExpenseEditor, setShowExpenseEditor] = useState(false);

  const total = formatCurrencyTotals(payments);
  const salePaymentsList = payments.filter((p) => (p.scope || "sale") === "sale");
  const rentalPaymentsList = payments.filter((p) => p.scope === "rental");
  const bnbPaymentsList = payments.filter((p) => p.scope === "bnb");
  const salesTotal = formatCurrencyTotals(salePaymentsList);
  const rentalsTotal = formatCurrencyTotals(rentalPaymentsList);
  const bnbTotal = formatCurrencyTotals(bnbPaymentsList);
  const highlightedSale = sales.find((sale) => sale.id === highlightedSaleId);
  const filteredPayments =
    scopeFilter === "all"
      ? payments
      : payments.filter((p) => (p.scope || "sale") === scopeFilter);

  function saleBalance(sale) {
    const received = payments
      .filter((payment) => payment.saleId === sale.id)
      .reduce((sum, payment) => sum + Number(payment.amount), 0);
    return Math.max(0, sale.salePrice - received);
  }

  const loadFinancialReports = useCallback(async (overrideFilters, page = expensesPage, year = reportYear) => {
    setLoadingReports(true);
    setFinanceError("");
    try {
      const activeFilters = overrideFilters || reportFilters;
      const [payRepRes, instRepRes, tenRes, bookRes, expensesRes, profitLossRes] = await Promise.all([
        getPaymentsReport({ ...activeFilters, page: 1, limit: 25 }),
        getInstallmentsReport(),
        getTenancies({ limit: 100 }),
        getBookings({ limit: 100 }),
        getExpenses(year, page),
        getAnnualProfitLoss(year),
      ]);
      if (payRepRes.success && payRepRes.data) {
        setPaymentsReport(payRepRes.data);
      }
      if (instRepRes.success && instRepRes.data) {
        setInstallmentsReport(instRepRes.data);
      }
      if (tenRes.success) {
        setTenancies(tenRes.data?.data || (Array.isArray(tenRes.data) ? tenRes.data : []));
      }
      if (bookRes.success) {
        setBookings(bookRes.data?.data || (Array.isArray(bookRes.data) ? bookRes.data : []));
      }
      if (expensesRes.success && expensesRes.data) {
        setExpenses(expensesRes.data.data || []);
        setExpensePagination(expensesRes.data.pagination || { page, total: 0, total_pages: 0 });
      } else {
        setFinanceError(expensesRes.message || "Unable to load annual expenses.");
      }
      if (profitLossRes.success && profitLossRes.data) {
        setProfitLoss(profitLossRes.data.data || []);
      } else {
        setFinanceError((current) => current || profitLossRes.message || "Unable to load annual profit and loss.");
      }
    } catch (error) {
      setFinanceError(error.message || "Unable to load financial report data.");
    } finally {
      setLoadingReports(false);
    }
  }, [reportFilters, expensesPage, reportYear]);

  useEffect(() => {
    if (activeView === "reports") {
      loadFinancialReports();
    }
  }, [activeView, loadFinancialReports]);

  async function exportReportCsv() {
    setReportExportError("");
    setExportingReport(true);
    let rows;
    try {
      const firstPage = await getPaymentsReport({
        ...reportFilters,
        page: 1,
        limit: 500,
      });
      if (!firstPage.success || !firstPage.data) {
        setReportExportError(firstPage.message || "Unable to export the financial report.");
        return;
      }

      const totalPages = Number(firstPage.data.pagination?.total_pages || 0);
      rows = firstPage.data.recent_transactions || [];
      for (let page = 2; page <= totalPages; page += 1) {
        const pageResult = await getPaymentsReport({
          ...reportFilters,
          page,
          limit: 500,
        });
        if (!pageResult.success || !pageResult.data) {
          setReportExportError(pageResult.message || `Unable to load report page ${page}.`);
          return;
        }
        rows = rows.concat(pageResult.data.recent_transactions || []);
      }

      const [firstExpenses, profitLossResult] = await Promise.all([
        getExpenses(reportYear, 1, 500),
        getAnnualProfitLoss(reportYear),
      ]);
      if (!firstExpenses.success || !firstExpenses.data) {
        setReportExportError(firstExpenses.message || "Unable to export annual expenses.");
        return;
      }
      if (!profitLossResult.success || !profitLossResult.data) {
        setReportExportError(profitLossResult.message || "Unable to export annual profit and loss.");
        return;
      }
      const allExpenses = firstExpenses.data.data || [];
      const expensePages = Number(firstExpenses.data.pagination?.total_pages || 0);
      for (let page = 2; page <= expensePages; page += 1) {
        const pageResult = await getExpenses(reportYear, page, 500);
        if (!pageResult.success || !pageResult.data) {
          setReportExportError(pageResult.message || `Unable to load expense page ${page}.`);
          return;
        }
        allExpenses.push(...(pageResult.data.data || []));
      }
      rows = [
        ...rows,
        ...allExpenses.map((expense) => ({
          record_type: "Expense",
          receipt_number: "",
          transaction_scope: expense.category,
          payment_type: "expense",
          client_name: expense.vendor || "",
          property_title: expense.description,
          payment_method: "",
          amount: expense.amount,
          currency: expense.currency,
          payment_date: expense.expense_date,
        })),
        ...(profitLossResult.data.data || []).flatMap((summary) => [
          { record_type: "Annual P&L Revenue", amount: summary.revenue, currency: summary.currency },
          { record_type: "Annual P&L Expenses", amount: summary.expenses, currency: summary.currency },
          { record_type: "Annual P&L Net Result", amount: summary.net_result, currency: summary.currency },
        ]),
      ];
    } catch (error) {
      setReportExportError(error.message || "Unable to export the financial report.");
      return;
    } finally {
      setExportingReport(false);
    }

    const headers = [
      "Record Type",
      "Receipt Number",
      "Revenue Stream",
      "Payment Purpose",
      "Client / Payer",
      "Property",
      "Payment Method",
      "Amount",
      "Currency",
      "Date",
    ];
    const csvCell = (value) => {
      const text = String(value ?? "");
      const safeText = /^[\s]*[=+\-@]/.test(text) ? `'${text}` : text;
      return `"${safeText.replace(/"/g, '""')}"`;
    };
    const csvLines = [
      headers.join(","),
      ...rows.map((r) => {
        const recordType = r.record_type || "Payment";
        const isPayment = recordType === "Payment";
        const ref = isPayment
          ? r.receipt_number || r.reference || r.transaction_reference || `PAY-${r.id}`
          : "";
        const stream = r.transaction_scope || r.scope || "";
        const purpose = r.payment_type || r.paymentType || "";
        const client = r.client_name || r.buyer_name || r.buyerName || "";
        const prop = r.property_title || r.propertyTitle || "";
        const method = r.payment_method || r.method || "";
        const amt = Number(r.amount || 0).toFixed(2);
        const curr = r.currency || "KES";
        const dt = r.payment_date || r.date || r.expense_date || "";
        return [recordType, ref, stream, purpose, client, prop, method, amt, curr, dt]
          .map(csvCell)
          .join(",");
      }),
    ];
    const blob = new Blob(["\uFEFF", csvLines.join("\n")], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `financial-report-${reportYear}-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  async function handleSaveExpense(expense) {
    const result = await createExpense(expense);
    if (!result.success) return result;
    setShowExpenseEditor(false);
    setExpensesPage(1);
    await loadFinancialReports(reportFilters, 1, reportYear);
    return { success: true };
  }

  async function handlePrintReport() {
    setReportExportError("");
    try {
      const [firstExpenses, firstPayments] = await Promise.all([
        getExpenses(reportYear, 1, 500),
        getPaymentsReport({ ...reportFilters, page: 1, limit: 500 }),
      ]);
      if (!firstExpenses.success || !firstExpenses.data) {
        setReportExportError(firstExpenses.message || "Unable to prepare annual expenses for printing.");
        return;
      }
      if (!firstPayments.success || !firstPayments.data) {
        setReportExportError(firstPayments.message || "Unable to prepare payment records for printing.");
        return;
      }

      const allExpenses = firstExpenses.data.data || [];
      const expensePages = Number(firstExpenses.data.pagination?.total_pages || 0);
      for (let page = 2; page <= expensePages; page += 1) {
        const pageResult = await getExpenses(reportYear, page, 500);
        if (!pageResult.success || !pageResult.data) {
          setReportExportError(pageResult.message || `Unable to load expense page ${page}.`);
          return;
        }
        allExpenses.push(...(pageResult.data.data || []));
      }
      const allTransactions = firstPayments.data.recent_transactions || [];
      const paymentPages = Number(firstPayments.data.pagination?.total_pages || 0);
      for (let page = 2; page <= paymentPages; page += 1) {
        const pageResult = await getPaymentsReport({ ...reportFilters, page, limit: 500 });
        if (!pageResult.success || !pageResult.data) {
          setReportExportError(pageResult.message || `Unable to load payment page ${page}.`);
          return;
        }
        allTransactions.push(...(pageResult.data.recent_transactions || []));
      }
      setPrintExpenses(allExpenses);
      setPrintTransactions(allTransactions);
      window.setTimeout(() => {
        const clearPrintData = () => {
          setPrintExpenses(null);
          setPrintTransactions(null);
          window.removeEventListener("afterprint", clearPrintData);
        };
        window.addEventListener("afterprint", clearPrintData, { once: true });
        printDocument("report");
      }, 100);
    } catch (error) {
      setReportExportError(error.message || "Unable to prepare the report for printing.");
    }
  }

  // Computed report metrics
  const reportSummary = paymentsReport?.summary || {};
  const totalReportTxCount = Number(reportSummary.total_transactions || 0);
  const reportCurrencySummaries = paymentsReport?.by_currency || [];

  const unpaidTenancies = tenancies.filter((t) => {
    const charged = Math.max(Number(t.total_charged || 0), Number(t.monthly_rent || 0));
    const paid = Number(t.rent_paid ?? t.total_paid ?? 0);
    return charged - paid > 0;
  });
  const unpaidBookings = bookings.filter((b) => {
    const due = Number(b.total_amount || 0);
    const paid = Number(b.total_paid || 0);
    return due - paid > 0;
  });

  const salesReceivableEntries = sales.map((sale) => ({
    amount: saleBalance(sale),
    currency: sale.currency || "KES",
  }));
  const rentalReceivableEntries = unpaidTenancies.map((tenancy) => {
    const charged = Math.max(Number(tenancy.total_charged || 0), Number(tenancy.monthly_rent || 0));
    const paid = Number(tenancy.rent_paid ?? tenancy.total_paid ?? 0);
    return {
      amount: Math.max(0, charged - paid),
      currency: tenancy.currency || "KES",
    };
  });
  const bookingReceivableEntries = unpaidBookings.map((booking) => ({
    amount: Math.max(0, Number(booking.total_amount || 0) - Number(booking.total_paid || 0)),
    currency: booking.currency || "KES",
  }));
  const totalReceivablesOutstanding = formatCurrencyTotals([
    ...salesReceivableEntries,
    ...rentalReceivableEntries,
    ...bookingReceivableEntries,
  ]);

  const overdueInstallments = installmentsReport?.overdue_installments || [];
  const upcomingInstallments = installmentsReport?.upcoming_installments || [];

  return (
    <div>
      <PageHeading
        eyebrow="Receipting, collections & financial intelligence"
        title="Payments & Financial Reports"
        description="Unified ledger and executive financial reports across Property Sales, Long-Term Rentals, and Short-Term BnBs."
        action={
          <div className="flex flex-wrap items-center gap-2.5 print:hidden">
            <div className="inline-flex rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
              <button
                type="button"
                onClick={() => setActiveView("ledger")}
                className={`inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-xs font-bold transition ${
                  activeView === "ledger"
                    ? "bg-[#0F2A43] text-white"
                    : "text-slate-600 hover:text-[#0F2A43]"
                }`}
              >
                <ReceiptText size={15} /> Receipts Ledger
              </button>
              <button
                type="button"
                onClick={() => setActiveView("reports")}
                className={`inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-xs font-bold transition ${
                  activeView === "reports"
                    ? "bg-[#0F2A43] text-white"
                    : "text-slate-600 hover:text-[#0F2A43]"
                }`}
              >
                <BarChart3 size={15} /> Financial Reports
              </button>
            </div>
            <button
              type="button"
              onClick={() => setShowEditor(true)}
              className="inline-flex items-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60]"
            >
              <Plus size={18} /> Record sale payment
            </button>
          </div>
        }
      />

      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[
          ["All Collections", total, `${payments.length} total receipts issued`, ArrowDownLeft, "bg-[#f8f0d8] text-[#0F2A43]"],
          ["Property Sales", salesTotal, `${salePaymentsList.length} buyer sale receipts`, ReceiptText, "bg-blue-50 text-blue-700"],
          ["Long-Term Rentals", rentalsTotal, `${rentalPaymentsList.length} tenant rent & deposit receipts`, CalendarClock, "bg-emerald-50 text-emerald-700"],
          ["Short-Term BnBs", bnbTotal, `${bnbPaymentsList.length} guest reservation receipts`, ReceiptText, "bg-amber-50 text-amber-800"],
        ].map(([label, value, caption, Icon, badgeCls]) => (
          <div key={label} className="flex items-start justify-between rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <div>
              <p className="text-sm font-semibold text-slate-500">{label}</p>
              <p className="mt-2 text-xl font-bold text-[#0F2A43]">{value}</p>
              <p className="mt-1 text-xs text-slate-400">{caption}</p>
            </div>
            <span className={`rounded-xl p-3 ${badgeCls}`}>
              <Icon size={20} />
            </span>
          </div>
        ))}
      </div>

      {activeView === "ledger" ? (
        <>
          {highlightedSale && (
            <div className="mb-5 flex flex-col justify-between gap-3 rounded-2xl border border-blue-100 bg-blue-50 p-4 sm:flex-row sm:items-center">
              <div>
                <p className="text-xs font-bold uppercase tracking-wider text-blue-700">Selected sale account</p>
                <p className="mt-1 font-bold text-[#0F2A43]">
                  {highlightedSale.buyerName} · {highlightedSale.propertyTitle}
                </p>
                <p className="mt-1 text-sm text-slate-600">Balance due {formatMoney(saleBalance(highlightedSale))}</p>
              </div>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => setStatementSaleId(highlightedSale.id)}
                  className="rounded-lg border border-blue-200 bg-white px-4 py-2.5 text-sm font-semibold text-[#0F2A43]"
                >
                  View statement
                </button>
                <button
                  type="button"
                  onClick={() => setShowEditor(true)}
                  className="rounded-lg bg-[#0F2A43] px-4 py-2.5 text-sm font-semibold text-white"
                >
                  Add payment
                </button>
              </div>
            </div>
          )}

          <section className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
              <div>
                <h2 className="font-bold text-[#0F2A43]">Unified Receipts &amp; Payment Ledger</h2>
                <p className="mt-0.5 text-xs text-slate-500">
                  Filter by revenue stream or inspect individual buyer, tenant, and BnB guest receipts.
                </p>
              </div>
              <div className="flex flex-wrap items-center gap-1.5 rounded-xl bg-slate-100 p-1 text-xs font-semibold">
                {[
                  ["all", `All (${payments.length})`],
                  ["sale", `Property Sales (${salePaymentsList.length})`],
                  ["rental", `Long-Term Rentals (${rentalPaymentsList.length})`],
                  ["bnb", `Short-Term BnBs (${bnbPaymentsList.length})`],
                ].map(([key, label]) => (
                  <button
                    key={key}
                    type="button"
                    onClick={() => setScopeFilter(key)}
                    className={`rounded-lg px-3 py-1.5 transition ${
                      scopeFilter === key
                        ? "bg-[#0F2A43] text-white shadow-sm"
                        : "text-slate-600 hover:text-slate-900"
                    }`}
                  >
                    {label}
                  </button>
                ))}
              </div>
            </div>
            {filteredPayments.length ? (
              <div className="overflow-x-auto">
                <table className="w-full min-w-205 text-left">
                  <thead className="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                      {["Receipt #", "Revenue Stream & Purpose", "Payer Role & Property Account", "Date", "Method", "Amount", ""].map((heading) => (
                        <th key={heading} className="px-5 py-3 font-bold">
                          {heading}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {filteredPayments.map((payment) => {
                      const scopeBadges = {
                        sale: "bg-blue-50 text-blue-700 border border-blue-200",
                        rental: "bg-emerald-50 text-emerald-700 border border-emerald-200",
                        bnb: "bg-amber-50 text-amber-800 border border-amber-200",
                      };
                      const scopeLabels = {
                        sale: "Property Sale",
                        rental: "Long-Term Rent",
                        bnb: "Short-Term BnB",
                      };
                      const roleLabels = {
                        sale: "Buyer",
                        rental: "Tenant",
                        bnb: "BnB Guest",
                      };
                      const accountRef =
                        payment.scope === "rental"
                          ? `Tenancy Lease #${payment.tenancyId || payment.id}`
                          : payment.scope === "bnb"
                            ? `BnB Booking #${payment.bookingId || payment.id}`
                            : `Sale Agreement #${payment.saleId || payment.id}`;
                      const scopeKey = payment.scope || "sale";
                      return (
                        <tr key={payment.id} className="text-sm hover:bg-slate-50/60">
                          <td className="px-5 py-4 font-mono text-xs font-semibold text-[#0F2A43]">{payment.reference}</td>
                          <td className="px-5 py-4">
                            <span
                              className={`inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-bold ${
                                scopeBadges[scopeKey] || "bg-slate-100 text-slate-700"
                              }`}
                            >
                              {scopeLabels[scopeKey] || "Payment"}
                            </span>
                            {payment.paymentType && (
                              <span className="mt-1 block text-xs font-medium capitalize text-slate-500">
                                Purpose: {payment.paymentType}
                              </span>
                            )}
                          </td>
                          <td className="px-5 py-4">
                            <p className="font-semibold text-[#0F2A43]">
                              <span className="mr-1.5 text-xs font-bold uppercase tracking-wider text-slate-400">
                                {roleLabels[scopeKey]}:
                              </span>
                              {payment.buyerName}
                            </p>
                            <p className="mt-0.5 text-xs text-slate-500">
                              {payment.propertyTitle} · <span className="text-slate-400">{accountRef}</span>
                            </p>
                          </td>
                          <td className="px-5 py-4 text-slate-500">{formatDate(payment.date)}</td>
                          <td className="px-5 py-4 capitalize text-slate-500">{payment.method}</td>
                          <td className="px-5 py-4 font-bold text-[#0F2A43]">
                            {formatMoney(payment.amount, payment.currency || "KES")}
                          </td>
                          <td className="px-5 py-4">
                            <button
                              type="button"
                              onClick={() => setSelectedReceipt(payment)}
                              className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-[#0F2A43] hover:bg-slate-100"
                            >
                              <ReceiptText size={14} /> Receipt
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="p-12 text-center">
                <p className="font-semibold text-[#0F2A43]">No payments recorded</p>
                <p className="mt-1 text-sm text-slate-500">
                  Once a sale has been registered, add payments and issue receipts here.
                </p>
              </div>
            )}
          </section>

          <section className="mt-6 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <h2 className="mb-4 font-bold text-[#0F2A43]">Outstanding sale balances &amp; statements</h2>
            {sales.length ? (
              <div className="divide-y divide-slate-100">
                {sales.map((sale) => (
                  <div key={sale.id} className="flex flex-col justify-between gap-3 py-3 sm:flex-row sm:items-center">
                    <div>
                      <p className="text-sm font-semibold text-[#0F2A43]">
                        {sale.buyerName} · {sale.propertyTitle}
                      </p>
                      <p className="mt-1 text-xs text-slate-500">
                        Agreed {formatMoney(sale.salePrice, sale.currency || "KES")} ·{" "}
                        {sale.nextDueDate ? `Next installment ${formatDate(sale.nextDueDate)}` : "No future installment scheduled"}
                      </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                      <p className="font-bold text-amber-800">
                        {formatMoney(saleBalance(sale), sale.currency || "KES")} due
                      </p>
                      <button
                        type="button"
                        onClick={() => setStatementSaleId(sale.id)}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-[#0F2A43] hover:bg-slate-50"
                      >
                        <FileText size={13} /> Statement
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-sm text-slate-500">No sale accounts created yet.</p>
            )}
          </section>
        </>
      ) : (
        /* FINANCIAL REPORTS & AGING VIEW */
        <div className="print-report space-y-6">
          <div className="hidden print:block">
            <CompanyBrand profile={companyProfile} />
            <h2 className="mt-4 text-xl font-bold text-[#0F2A43]">Annual Financial Report · {reportYear}</h2>
          </div>
          {/* Filter & Export Controls */}
          <section className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm print:hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-4">
              <div className="flex items-center gap-2">
                <Filter size={17} className="text-[#0F2A43]" />
                <div>
                  <h2 className="text-sm font-bold text-[#0F2A43]">Report Period &amp; Channel Filters</h2>
                  <p className="text-xs text-slate-500">
                    Filter revenue analytics by date range and payment method, or export all matching transactions to CSV / PDF.
                  </p>
                </div>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  onClick={exportReportCsv}
                  disabled={exportingReport}
                  className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-[#0F2A43] hover:bg-slate-50 disabled:opacity-50"
                >
                  <FileSpreadsheet size={15} /> {exportingReport ? "Preparing CSV..." : "Export all CSV"}
                </button>
                <button
                  type="button"
                  onClick={handlePrintReport}
                  className="inline-flex items-center gap-1.5 rounded-xl bg-[#0F2A43] px-3.5 py-2 text-xs font-semibold text-white hover:bg-[#173e60]"
                >
                  <Printer size={15} /> Print / Save PDF Report
                </button>
              </div>
            </div>

            {reportExportError && (
              <p role="alert" className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-700">
                {reportExportError}
              </p>
            )}

            <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
              <label className="text-xs font-semibold text-slate-600">
                Start date
                <input
                  type="date"
                  value={reportFilters.start_date}
                  onChange={(e) => setReportFilters((curr) => ({ ...curr, start_date: e.target.value }))}
                  className={`${inputClass} mt-1.5 py-2 text-xs`}
                />
              </label>
              <label className="text-xs font-semibold text-slate-600">
                End date
                <input
                  type="date"
                  value={reportFilters.end_date}
                  onChange={(e) => setReportFilters((curr) => ({ ...curr, end_date: e.target.value }))}
                  className={`${inputClass} mt-1.5 py-2 text-xs`}
                />
              </label>
              <label className="text-xs font-semibold text-slate-600">
                Payment channel
                <select
                  value={reportFilters.payment_method}
                  onChange={(e) => setReportFilters((curr) => ({ ...curr, payment_method: e.target.value }))}
                  className={`${inputClass} mt-1.5 py-2 text-xs`}
                >
                  <option value="">All channels</option>
                  <option value="mobile_money">M-Pesa / Mobile Money</option>
                  <option value="bank_transfer">Bank Transfer</option>
                  <option value="cash">Cash</option>
                </select>
              </label>
              <div className="flex items-end gap-2 lg:col-span-2">
                <button
                  type="button"
                  onClick={() => loadFinancialReports(reportFilters)}
                  disabled={loadingReports}
                  className="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-[#0F2A43] px-4 py-2.5 text-xs font-semibold text-white hover:bg-[#173e60] disabled:opacity-50"
                >
                  <RefreshCw size={14} className={loadingReports ? "animate-spin" : ""} />
                  {loadingReports ? "Generating..." : "Apply Filters"}
                </button>
                {(reportFilters.start_date || reportFilters.end_date || reportFilters.payment_method) && (
                  <button
                    type="button"
                    onClick={() => {
                      const reset = { start_date: "", end_date: "", payment_method: "" };
                      setReportFilters(reset);
                      loadFinancialReports(reset);
                    }}
                    className="rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                  >
                    Reset
                  </button>
                )}
              </div>
            </div>
          </section>

          {/* Executive Financial KPIs */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
              <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Filtered Revenue Collected</p>
              <div className="mt-2 space-y-1">
                {reportCurrencySummaries.length ? reportCurrencySummaries.map((item) => (
                  <p key={item.currency} className="text-lg font-bold text-emerald-700">
                    {formatMoney(item.total_revenue, item.currency)}
                  </p>
                )) : <p className="text-lg font-bold text-emerald-700">{formatMoney(0, "KES")}</p>}
              </div>
              <p className="mt-1 text-xs text-slate-500">
                Across {totalReportTxCount} completed receipt{totalReportTxCount === 1 ? "" : "s"}
              </p>
            </div>
            <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
              <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Average Payment by Currency</p>
              <div className="mt-2 space-y-1.5">
                {reportCurrencySummaries.length ? reportCurrencySummaries.map((item) => (
                  <p key={item.currency} className="text-sm font-bold text-[#0F2A43]">
                    {formatMoney(item.average_payment, item.currency)}
                    <span className="ml-1 text-[10px] font-normal text-slate-500">
                      min {formatMoney(item.minimum_payment, item.currency)} · max {formatMoney(item.maximum_payment, item.currency)}
                    </span>
                  </p>
                )) : <p className="text-sm text-slate-500">No completed payments.</p>}
              </div>
            </div>
            <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
              <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Total Receivables Due</p>
              <p className="mt-2 text-2xl font-bold text-amber-800">
                {totalReceivablesOutstanding}
              </p>
              <p className="mt-1 text-xs text-slate-500">
                Sales + Long-Term Rent + BnB balances
              </p>
            </div>
            <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
              <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Accounts in Arrears</p>
              <p className="mt-2 text-2xl font-bold text-red-600">
                {overdueInstallments.length + unpaidTenancies.length + unpaidBookings.length}
              </p>
              <p className="mt-1 text-xs text-slate-500">
                {overdueInstallments.length} overdue sale inst. · {unpaidTenancies.length} rent · {unpaidBookings.length} BnB
              </p>
            </div>
          </div>

          <section className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <div className="flex flex-col justify-between gap-4 border-b border-slate-100 pb-4 sm:flex-row sm:items-center">
              <div>
                <h2 className="text-base font-bold text-[#0F2A43]">Annual Profit &amp; Loss</h2>
                <p className="mt-1 text-xs text-slate-500">
                  Completed payments less manually recorded expenses. Results are kept separate by currency.
                </p>
              </div>
              <div className="flex flex-wrap items-center gap-2 print:hidden">
                <label className="text-xs font-semibold text-slate-600">
                  Report year
                  <select
                    value={reportYear}
                    onChange={(event) => {
                      const year = Number(event.target.value);
                      setReportYear(year);
                      setExpensesPage(1);
                      setPrintExpenses(null);
                    }}
                    className={`${inputClass} mt-1.5 min-w-28 py-2 text-xs`}
                  >
                    {Array.from({ length: new Date().getFullYear() - 1999 + 1 }, (_, index) => new Date().getFullYear() + 1 - index).map((year) => (
                      <option key={year} value={year}>{year}</option>
                    ))}
                  </select>
                </label>
                <button
                  type="button"
                  onClick={() => setShowExpenseEditor(true)}
                  className="mt-5 inline-flex items-center gap-1.5 rounded-xl bg-emerald-700 px-3.5 py-2.5 text-xs font-semibold text-white hover:bg-emerald-800"
                >
                  <Plus size={14} /> Record expense
                </button>
              </div>
            </div>

            {financeError && (
              <p role="alert" className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-700">{financeError}</p>
            )}

            {profitLoss.length ? (
              <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200">
                <table className="w-full min-w-[560px] text-left text-sm">
                  <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                      <th className="px-4 py-3">Currency</th>
                      <th className="px-4 py-3 text-right">Completed payments</th>
                      <th className="px-4 py-3 text-right">Expenses</th>
                      <th className="px-4 py-3 text-right">Net result</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {profitLoss.map((summary) => (
                      <tr key={summary.currency}>
                        <td className="px-4 py-3 font-bold text-[#0F2A43]">{summary.currency}</td>
                        <td className="px-4 py-3 text-right font-semibold text-emerald-700">{formatMoney(summary.revenue, summary.currency)}</td>
                        <td className="px-4 py-3 text-right font-semibold text-red-700">{formatMoney(summary.expenses, summary.currency)}</td>
                        <td className={`px-4 py-3 text-right font-bold ${Number(summary.net_result) >= 0 ? "text-[#0F2A43]" : "text-red-700"}`}>{formatMoney(summary.net_result, summary.currency)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <p className="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-500">
                {loadingReports ? "Loading annual results..." : "No completed payments or expenses recorded for this year."}
              </p>
            )}

            <div className="mt-6">
              <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                  <h3 className="font-bold text-[#0F2A43]">Recorded expenses</h3>
                  <p className="mt-0.5 text-xs text-slate-500">{expensePagination.total || 0} expense records in {reportYear}</p>
                </div>
                {expensePagination.total_pages > 1 && (
                  <div className="flex items-center gap-2 text-xs">
                    <button
                      type="button"
                      disabled={expensesPage <= 1 || loadingReports}
                      onClick={() => {
                        setExpensesPage((page) => page - 1);
                      }}
                      className="rounded-lg border border-slate-200 px-3 py-2 font-semibold text-slate-600 disabled:opacity-40 print:hidden"
                    >Previous</button>
                    <span className="text-slate-500">Page {expensesPage} of {expensePagination.total_pages}</span>
                    <button
                      type="button"
                      disabled={expensesPage >= expensePagination.total_pages || loadingReports}
                      onClick={() => {
                        setExpensesPage((page) => page + 1);
                      }}
                      className="rounded-lg border border-slate-200 px-3 py-2 font-semibold text-slate-600 disabled:opacity-40 print:hidden"
                    >Next</button>
                  </div>
                )}
              </div>
              {(printExpenses || expenses).length ? (
                <div className="overflow-x-auto rounded-xl border border-slate-200">
                  <table className="w-full min-w-[640px] text-left text-xs">
                    <thead className="bg-slate-50 uppercase text-slate-500">
                      <tr>
                        <th className="px-3 py-2.5">Date</th>
                        <th className="px-3 py-2.5">Category</th>
                        <th className="px-3 py-2.5">Description</th>
                        <th className="px-3 py-2.5">Vendor</th>
                        <th className="px-3 py-2.5 text-right">Amount</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {(printExpenses || expenses).map((expense) => (
                        <tr key={expense.id}>
                          <td className="whitespace-nowrap px-3 py-2.5 text-slate-600">{formatDate(expense.expense_date)}</td>
                          <td className="px-3 py-2.5 font-semibold capitalize text-[#0F2A43]">{expense.category}</td>
                          <td className="px-3 py-2.5 text-slate-600">{expense.description}</td>
                          <td className="px-3 py-2.5 text-slate-600">{expense.vendor || "—"}</td>
                          <td className="whitespace-nowrap px-3 py-2.5 text-right font-bold text-red-700">{formatMoney(expense.amount, expense.currency)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <p className="rounded-xl bg-slate-50 p-4 text-xs text-slate-500">No expenses recorded for this year.</p>
              )}
            </div>
          </section>

          <section className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <div className="border-b border-slate-100 p-5">
              <h2 className="font-bold text-[#0F2A43]">Completed payment transactions</h2>
              <p className="mt-1 text-xs text-slate-500">
                Showing {printTransactions ? printTransactions.length : (paymentsReport?.recent_transactions || []).length} of {totalReportTxCount} matching completed receipts. Printed reports include all matching transactions.
              </p>
            </div>
            {(printTransactions || paymentsReport?.recent_transactions || []).length ? (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[780px] text-left text-xs">
                  <thead className="bg-slate-50 uppercase text-slate-500">
                    <tr>
                      <th className="px-4 py-3">Receipt</th>
                      <th className="px-4 py-3">Date</th>
                      <th className="px-4 py-3">Payer</th>
                      <th className="px-4 py-3">Property</th>
                      <th className="px-4 py-3">Stream</th>
                      <th className="px-4 py-3">Method</th>
                      <th className="px-4 py-3 text-right">Amount</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {(printTransactions || paymentsReport?.recent_transactions || []).map((payment) => (
                      <tr key={payment.id}>
                        <td className="px-4 py-3 font-mono text-[#0F2A43]">
                          <span className="font-semibold">{payment.receipt_number || payment.transaction_reference || `PAY-${payment.id}`}</span>
                          {payment.invoice_number && <span className="mt-1 block text-[10px] text-slate-500">Invoice {payment.invoice_number}</span>}
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-slate-600">{formatDate(payment.payment_date)}</td>
                        <td className="px-4 py-3 font-medium text-slate-700">{payment.client_name || payment.buyer_name || "—"}</td>
                        <td className="px-4 py-3 text-slate-600">{payment.property_title || "—"}</td>
                        <td className="px-4 py-3 capitalize text-slate-600">{payment.transaction_scope || "—"}</td>
                        <td className="px-4 py-3 capitalize text-slate-600">{(payment.payment_method || "").replace(/_/g, " ") || "—"}</td>
                        <td className="whitespace-nowrap px-4 py-3 text-right font-bold text-emerald-700">{formatMoney(payment.amount, payment.currency || "KES")}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <p className="p-5 text-sm text-slate-500">No completed payments match the selected report filters.</p>
            )}
          </section>

          {/* 3-Column Revenue Breakdown */}
          <div className="grid gap-6 lg:grid-cols-3">
            {/* Breakdown by Revenue Stream */}
            <section className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
              <h3 className="font-bold text-[#0F2A43]">1. Revenue by Stream</h3>
              <p className="mt-0.5 text-xs text-slate-500">Collections split across Sales, Rentals &amp; BnB</p>
              <div className="mt-4 space-y-4">
                {[
                  { key: "sale", label: "Property Sales", color: "bg-blue-600" },
                  { key: "rental", label: "Long-Term Rentals", color: "bg-emerald-600" },
                  { key: "bnb", label: "Short-Term BnBs", color: "bg-amber-500" },
                ].map(({ key, label, color }) => {
                  const found = (paymentsReport?.by_stream || []).filter(
                    (item) => item.transaction_scope === key
                  );
                  const count = found.reduce((sum, item) => sum + Number(item.transaction_count || 0), 0);
                  return (
                    <div key={key}>
                      <div className="flex items-center justify-between text-xs">
                        <span className="font-semibold text-[#0F2A43]">
                          {label} <span className="text-slate-400">({count})</span>
                        </span>
                        <div className="text-right font-bold text-[#0F2A43]">
                          {found.length ? found.map((item) => (
                            <p key={item.currency}>{formatMoney(item.total_amount, item.currency)}</p>
                          )) : formatMoney(0, "KES")}
                        </div>
                      </div>
                      <div className={`mt-1.5 h-1 w-full rounded-full ${color}`} />
                    </div>
                  );
                })}
              </div>
            </section>

            {/* Breakdown by Payment Method */}
            <section className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
              <h3 className="font-bold text-[#0F2A43]">2. Revenue by Payment Channel</h3>
              <p className="mt-0.5 text-xs text-slate-500">M-Pesa vs. Bank Transfer vs. Cash</p>
              <div className="mt-4 space-y-4">
                {(paymentsReport?.by_method || []).length === 0 ? (
                  <p className="text-xs text-slate-500">No completed transactions for this filter.</p>
                ) : (
                  (paymentsReport?.by_method || []).map((item) => {
                    const count = Number(item.transaction_count || 0);
                    const methodLabel =
                      item.payment_method === "mobile_money"
                        ? "M-Pesa / Mobile Money"
                        : (item.payment_method || "cash").replace(/_/g, " ");
                    return (
                      <div key={`${item.payment_method}-${item.currency}`}>
                        <div className="flex items-center justify-between text-xs">
                          <span className="font-semibold capitalize text-[#0F2A43]">
                            {methodLabel} <span className="text-slate-400">({count})</span>
                          </span>
                          <span className="font-bold text-[#0F2A43]">
                            {formatMoney(item.total_amount, item.currency)}
                          </span>
                        </div>
                      </div>
                    );
                  })
                )}
              </div>
            </section>

            {/* Breakdown by Payment Type / Purpose */}
            <section className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
              <h3 className="font-bold text-[#0F2A43]">3. Revenue by Purpose</h3>
              <p className="mt-0.5 text-xs text-slate-500">Deposits, installments, rent &amp; booking fees</p>
              <div className="mt-4 divide-y divide-slate-100">
                {(paymentsReport?.by_type || []).length === 0 ? (
                  <p className="text-xs text-slate-500">No completed transactions for this filter.</p>
                ) : (
                  (paymentsReport?.by_type || []).map((item) => (
                    <div key={`${item.payment_type}-${item.currency}`} className="flex items-center justify-between py-2 text-xs">
                      <span className="font-semibold capitalize text-slate-700">
                        {(item.payment_type || "payment").replace(/_/g, " ")}{" "}
                        <span className="text-slate-400">({item.transaction_count})</span>
                      </span>
                      <span className="font-bold text-[#0F2A43]">
                        {formatMoney(item.total_amount, item.currency)}
                      </span>
                    </div>
                  ))
                )}
              </div>
            </section>
          </div>

          {/* Sale Installment Receivables & Overdue Aging */}
          <section className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 p-5">
              <div>
                <h3 className="flex items-center gap-2 font-bold text-[#0F2A43]">
                  <AlertTriangle size={17} className="text-amber-600" />
                  Buyer Installment Aging &amp; Schedule ({overdueInstallments.length} Overdue ·{" "}
                  {upcomingInstallments.length} Upcoming)
                </h3>
                <p className="mt-0.5 text-xs text-slate-500">
                  Track overdue and upcoming buyer installments, inspect official sale statements, or send reminders.
                </p>
              </div>
            </div>

            {overdueInstallments.length === 0 && upcomingInstallments.length === 0 ? (
              <p className="p-6 text-sm text-slate-500">No pending or overdue sale installments found.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-195 text-left text-sm">
                  <thead className="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                      <th className="px-5 py-3 font-bold">Buyer &amp; Property</th>
                      <th className="px-5 py-3 font-bold">Installment</th>
                      <th className="px-5 py-3 font-bold">Due Date &amp; Aging</th>
                      <th className="px-5 py-3 font-bold">Amount Due</th>
                      <th className="px-5 py-3 font-bold">Balance</th>
                      <th className="px-5 py-3 font-bold print:hidden">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {[
                      ...overdueInstallments.map((item) => ({ ...item, _isOverdue: true })),
                      ...upcomingInstallments.map((item) => ({ ...item, _isOverdue: false })),
                    ].map((inst) => (
                      <tr key={inst.id} className="hover:bg-slate-50/60">
                        <td className="px-5 py-3.5">
                          <p className="font-semibold text-[#0F2A43]">{inst.buyer_name}</p>
                          <p className="text-xs text-slate-500">{inst.property_title}</p>
                        </td>
                        <td className="px-5 py-3.5 text-xs font-semibold text-slate-700">
                          {Number(inst.installment_number) === 0
                            ? "Deposit (#0)"
                            : `Installment #${inst.installment_number}`}
                        </td>
                        <td className="px-5 py-3.5">
                          <p className="text-xs font-medium text-slate-700">{formatDate(inst.due_date)}</p>
                          {inst._isOverdue ? (
                            <span className="mt-0.5 inline-flex rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-bold text-red-700">
                              Overdue by {inst.days_overdue} day{Number(inst.days_overdue) === 1 ? "" : "s"}
                            </span>
                          ) : (
                            <span className="mt-0.5 inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-bold text-blue-700">
                              Upcoming
                            </span>
                          )}
                        </td>
                        <td className="px-5 py-3.5 font-semibold text-[#0F2A43]">
                          {formatMoney(inst.amount_due, inst.currency || "KES")}
                        </td>
                        <td className="px-5 py-3.5 font-bold text-amber-800">
                          {formatMoney(inst.outstanding_balance ?? inst.amount_due, inst.currency || "KES")}
                        </td>
                        <td className="px-5 py-3.5 print:hidden">
                          <div className="flex flex-wrap items-center gap-2">
                            <button
                              type="button"
                              onClick={() => setStatementSaleId(inst.sale_id)}
                              className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-[#0F2A43] hover:bg-slate-50"
                            >
                              <FileText size={13} /> Statement
                            </button>
                            {onSendReminder && (
                              <button
                                type="button"
                                onClick={() =>
                                  onSendReminder({
                                    id: inst.sale_id,
                                    buyerName: inst.buyer_name,
                                  })
                                }
                                className="inline-flex items-center gap-1 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100"
                              >
                                <BellRing size={13} /> Remind
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          {/* Rental & BnB Arrears Table */}
          <section className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <div className="border-b border-slate-100 p-5">
              <h3 className="font-bold text-[#0F2A43]">
                Long-Term Rental &amp; Short-Term BnB Arrears ({unpaidTenancies.length + unpaidBookings.length})
              </h3>
              <p className="mt-0.5 text-xs text-slate-500">
                Tenants and BnB guests with an unpaid balance remaining on their lease or reservation.
              </p>
            </div>
            {unpaidTenancies.length === 0 && unpaidBookings.length === 0 ? (
              <p className="p-6 text-sm text-emerald-700 font-medium">
                All active rental leases and BnB reservations are paid up in full.
              </p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-180 text-left text-sm">
                  <thead className="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                      <th className="px-5 py-3 font-bold">Stream</th>
                      <th className="px-5 py-3 font-bold">Tenant / Guest</th>
                      <th className="px-5 py-3 font-bold">Property</th>
                      <th className="px-5 py-3 font-bold">Total Billed</th>
                      <th className="px-5 py-3 font-bold">Paid</th>
                      <th className="px-5 py-3 font-bold">Balance Due</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {unpaidTenancies.map((t) => {
                      const billed = Math.max(Number(t.total_charged || 0), Number(t.monthly_rent || 0));
                      const paid = Number(t.rent_paid ?? t.total_paid ?? 0);
                      const bal = Math.max(0, billed - paid);
                      return (
                        <tr key={`ten-${t.id}`}>
                          <td className="px-5 py-3.5">
                            <span className="rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-bold text-emerald-700 border border-emerald-200">
                              Long-Term Rent
                            </span>
                          </td>
                          <td className="px-5 py-3.5 font-semibold text-[#0F2A43]">
                            {t.tenant_name}
                            <span className="block text-xs font-normal text-slate-500">{t.tenant_email}</span>
                          </td>
                          <td className="px-5 py-3.5 text-slate-600">{t.property_title}</td>
                          <td className="px-5 py-3.5 font-medium text-slate-700">{formatMoney(billed, "KES")}</td>
                          <td className="px-5 py-3.5 font-semibold text-emerald-700">{formatMoney(paid, "KES")}</td>
                          <td className="px-5 py-3.5 font-bold text-red-600">{formatMoney(bal, "KES")}</td>
                        </tr>
                      );
                    })}
                    {unpaidBookings.map((b) => {
                      const billed = Number(b.total_amount || 0);
                      const paid = Number(b.total_paid || 0);
                      const bal = Math.max(0, billed - paid);
                      return (
                        <tr key={`bnb-${b.id}`}>
                          <td className="px-5 py-3.5">
                            <span className="rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-bold text-amber-800 border border-amber-200">
                              Short-Term BnB
                            </span>
                          </td>
                          <td className="px-5 py-3.5 font-semibold text-[#0F2A43]">
                            {b.guest_name}
                            <span className="block text-xs font-normal text-slate-500">{b.guest_email}</span>
                          </td>
                          <td className="px-5 py-3.5 text-slate-600">{b.property_title}</td>
                          <td className="px-5 py-3.5 font-medium text-slate-700">
                            {formatMoney(billed, b.currency || "KES")}
                          </td>
                          <td className="px-5 py-3.5 font-semibold text-emerald-700">
                            {formatMoney(paid, b.currency || "KES")}
                          </td>
                          <td className="px-5 py-3.5 font-bold text-red-600">
                            {formatMoney(bal, b.currency || "KES")}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </div>
      )}

      {showEditor && (
        <PaymentEditor
          sales={sales}
          existingPayments={payments}
          user={user}
          initialSaleId={highlightedSaleId}
          onClose={() => setShowEditor(false)}
          onSave={onRecordPayment}
        />
      )}
      {statementSaleId && (
        <SaleStatementModal
          saleId={statementSaleId}
          companyProfile={companyProfile}
          onClose={() => setStatementSaleId(null)}
        />
      )}
      {selectedReceipt && (
        <Modal
          title="Payment receipt"
          description={`Receipt reference ${selectedReceipt.reference}`}
          onClose={() => setSelectedReceipt(null)}
          size="max-w-lg"
        >
          <div className="print-receipt p-6 sm:p-8">
            <CompanyBrand profile={companyProfile} />
            <div className="mb-6 border-b border-slate-200 pb-5">
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-[#B88912]">Official Payment Receipt</p>
              <h3 className="mt-2 text-2xl font-bold text-[#0F2A43]">Payment received</h3>
              <p className="mt-2 font-mono text-sm text-slate-500">{selectedReceipt.reference}</p>
            </div>
            {[
              [
                "Revenue stream",
                selectedReceipt.scope === "rental"
                  ? "Long-Term Rental"
                  : selectedReceipt.scope === "bnb"
                    ? "Short-Term BnB Reservation"
                    : "Property Sale",
              ],
              ["Payment purpose", selectedReceipt.paymentType || "Payment"],
              [
                selectedReceipt.scope === "rental"
                  ? "Received from Tenant"
                  : selectedReceipt.scope === "bnb"
                    ? "Received from Guest"
                    : "Received from Buyer",
                selectedReceipt.buyerName,
              ],
              ["Property", selectedReceipt.propertyTitle],
              ["Payment date", formatDate(selectedReceipt.date)],
              ["Payment method", selectedReceipt.method],
              ["Transaction reference", selectedReceipt.note || "—"],
              ["Recorded by (Audit trail)", selectedReceipt.recordedBy || "Administrator"],
            ].map(([label, value]) => (
              <div key={label} className="flex justify-between gap-4 border-b border-slate-100 py-3 text-sm">
                <span className="text-slate-500">{label}</span>
                <span className="text-right font-semibold text-[#0F2A43]">{value}</span>
              </div>
            ))}
            <div className="mt-5 flex justify-between rounded-xl bg-slate-50 p-4">
              <span className="font-bold text-[#0F2A43]">Amount received</span>
              <strong className="text-lg text-[#0F2A43]">
                {formatMoney(selectedReceipt.amount, selectedReceipt.currency || "KES")}
              </strong>
            </div>
            <p className="mt-3 flex items-center gap-1.5 text-[11px] text-slate-400">
              <Lock size={12} /> Confirmed payment record is locked in the audit trail and cannot be edited or deleted.
            </p>
            <button
              type="button"
              onClick={() => printDocument("receipt")}
              className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white print:hidden"
            >
              <Download size={16} /> Print receipt
            </button>
          </div>
        </Modal>
      )}
      {showExpenseEditor && (
        <ExpenseEditor
          year={reportYear}
          onClose={() => setShowExpenseEditor(false)}
          onSave={handleSaveExpense}
        />
      )}
    </div>
  );
}

export default Payments;
