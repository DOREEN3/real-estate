import { useEffect, useState } from "react";
import { ArrowRight, CalendarClock, CircleDollarSign, FileText, Plus, ReceiptText } from "lucide-react";
import Modal from "../components/Modal";
import PageHeading from "../components/PageHeading";
import { getSaleInstallments } from "../api/sales";
import { formatCurrencyTotals, formatDate, formatMoney, inputClass } from "../lib/utils";

function getLocalToday() {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
}

function SaleEditor({ properties, sales = [], enquiries, onClose, onSave }) {
  const soldPropertyIds = new Set(sales.map((s) => Number(s.propertyId)));
  const availableProperties = properties.filter(
    (property) =>
      property.listingType === "For Sale" &&
      property.status === "Available" &&
      !soldPropertyIds.has(Number(property.id))
  );
  const firstProp = availableProperties[0];
  const defaultPrice = firstProp?.price ? String(firstProp.price) : "";
  const defaultDeposit = firstProp?.price ? String(Math.round(Number(firstProp.price) * 0.2)) : "";
  const defaultInstallment =
    firstProp?.price
      ? String(Math.max(0, Math.round((Number(firstProp.price) - Math.round(Number(firstProp.price) * 0.2)) / 12)))
      : "";

  const [form, setForm] = useState({
    propertyId: firstProp?.id ? String(firstProp.id) : "",
    buyerName: "",
    buyerEmail: "",
    salePrice: defaultPrice,
    deposit: defaultDeposit,
    installmentAmount: defaultInstallment,
    installmentsTotal: "12",
    nextDueDate: getLocalToday(),
  });
  const [selectedEnquiry, setSelectedEnquiry] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState("");
  const selectedProperty = availableProperties.find((property) => String(property.id) === String(form.propertyId));
  const currency = selectedProperty?.currency || "KES";

  function recomputeInstallment(salePriceVal, depositVal, countVal) {
    const priceNum = Number(salePriceVal) || 0;
    const depNum = Number(depositVal) || 0;
    const countNum = Number(countVal) || 0;
    if (countNum <= 0 || priceNum <= depNum) return "0";
    return String(Math.round((priceNum - depNum) / countNum));
  }

  function selectEnquiry(event) {
    const enquiry = enquiries.find((item) => String(item.id) === event.target.value);
    setSelectedEnquiry(event.target.value);
    if (enquiry) setForm((current) => ({ ...current, buyerName: enquiry.name, buyerEmail: enquiry.email }));
  }

  async function submit(event) {
    event.preventDefault();
    setFormError("");

    const salePriceNum = Number(form.salePrice);
    const depositNum = Number(form.deposit || 0);
    const installmentsNum = Number(form.installmentsTotal || 0);

    if (!form.propertyId) {
      setFormError("Please select an available property for sale.");
      return;
    }
    if (!form.buyerName.trim() || !form.buyerEmail.trim()) {
      setFormError("Please enter the buyer's name and email address.");
      return;
    }
    if (!Number.isFinite(salePriceNum) || salePriceNum <= 0) {
      setFormError("Agreed sale price must be greater than 0.");
      return;
    }
    if (depositNum < 0 || depositNum > salePriceNum) {
      setFormError("Deposit collected cannot exceed the agreed sale price.");
      return;
    }
    if (installmentsNum > 0 && depositNum <= 0) {
      setFormError("An installment plan requires a positive deposit amount.");
      return;
    }

    setSubmitting(true);
    try {
      const res = await onSave({
        ...form,
        propertyId: Number(form.propertyId),
        enquiryId: selectedEnquiry ? Number(selectedEnquiry) : null,
        currency,
      });
      if (res === false || (res && res.success === false)) {
        setFormError(res?.message || "Unable to register sale. Please check the fields and try again.");
        return;
      }
      onClose();
    } catch (err) {
      setFormError(err?.message || "Unable to register sale.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal title="Register a sale" description="Record the buyer, agreed price, deposit and payment plan." onClose={onClose}>
      <form onSubmit={submit} noValidate className="space-y-4 p-5 sm:p-7">
        {formError && (
          <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
            {formError}
          </div>
        )}
        <label className="block text-sm font-semibold text-slate-700">
          Available property
          <select
            name="propertyId"
            value={form.propertyId}
            onChange={(event) => {
              const prop = availableProperties.find((item) => String(item.id) === event.target.value);
              const nextPrice = prop?.price ? String(prop.price) : form.salePrice;
              const nextDeposit = prop?.price ? String(Math.round(Number(prop.price) * 0.2)) : form.deposit;
              setForm({
                ...form,
                propertyId: event.target.value,
                salePrice: nextPrice,
                deposit: nextDeposit,
                installmentAmount: recomputeInstallment(nextPrice, nextDeposit, form.installmentsTotal),
              });
            }}
            required
            className={`${inputClass} mt-2`}
          >
            {availableProperties.map((property) => (
              <option key={property.id} value={property.id}>
                {property.title} · {formatMoney(property.price, property.currency)}
              </option>
            ))}
          </select>
        </label>
        <label className="block text-sm font-semibold text-slate-700">
          Use an existing lead (optional)
          <select value={selectedEnquiry} onChange={selectEnquiry} className={`${inputClass} mt-2`}>
            <option value="">Enter buyer details below</option>
            {enquiries.map((enquiry) => (
              <option key={enquiry.id} value={enquiry.id}>
                {enquiry.name} · {enquiry.email}
              </option>
            ))}
          </select>
        </label>
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="text-sm font-semibold text-slate-700">
            Buyer name
            <input
              required
              name="buyerName"
              value={form.buyerName}
              onChange={(event) => setForm({ ...form, buyerName: event.target.value })}
              className={`${inputClass} mt-2`}
            />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Buyer email
            <input
              required
              type="email"
              name="buyerEmail"
              value={form.buyerEmail}
              onChange={(event) => setForm({ ...form, buyerEmail: event.target.value })}
              className={`${inputClass} mt-2`}
            />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Agreed sale price ({currency})
            <input
              required
              min="1"
              type="number"
              name="salePrice"
              value={form.salePrice}
              onChange={(event) => {
                const nextPrice = event.target.value;
                setForm({
                  ...form,
                  salePrice: nextPrice,
                  installmentAmount: recomputeInstallment(nextPrice, form.deposit, form.installmentsTotal),
                });
              }}
              className={`${inputClass} mt-2`}
            />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Deposit collected ({currency})
            <input
              required
              min="0"
              type="number"
              name="deposit"
              value={form.deposit}
              onChange={(event) => {
                const nextDeposit = event.target.value;
                setForm({
                  ...form,
                  deposit: nextDeposit,
                  installmentAmount: recomputeInstallment(form.salePrice, nextDeposit, form.installmentsTotal),
                });
              }}
              className={`${inputClass} mt-2`}
            />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Installment amount ({currency})
            <input
              min="0"
              type="number"
              name="installmentAmount"
              value={form.installmentAmount}
              onChange={(event) => setForm({ ...form, installmentAmount: event.target.value })}
              className={`${inputClass} mt-2`}
              placeholder="Auto-calculated from balance"
            />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Number of installments
            <input
              min="0"
              max="120"
              type="number"
              name="installmentsTotal"
              value={form.installmentsTotal}
              onChange={(event) => {
                const nextCount = event.target.value;
                setForm({
                  ...form,
                  installmentsTotal: nextCount,
                  installmentAmount: recomputeInstallment(form.salePrice, form.deposit, nextCount),
                });
              }}
              className={`${inputClass} mt-2`}
            />
          </label>
          <label className="text-sm font-semibold text-slate-700 sm:col-span-2">
            Schedule start date
            <input
              type="date"
              min={getLocalToday()}
              name="nextDueDate"
              value={form.nextDueDate}
              onChange={(event) => setForm({ ...form, nextDueDate: event.target.value })}
              className={`${inputClass} mt-2`}
            />
          </label>
        </div>
        {availableProperties.length === 0 && (
          <p className="text-sm text-red-700">There are no available properties to register as a sale.</p>
        )}
        <div className="flex justify-end gap-3 border-t border-slate-100 pt-5">
          <button
            type="button"
            onClick={onClose}
            className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={availableProperties.length === 0 || submitting}
            className="rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
          >
            {submitting ? "Saving sale..." : "Save sale and receipt deposit"}
          </button>
        </div>
      </form>
    </Modal>
  );
}

function Sales({
  sales,
  properties,
  enquiries,
  payments,
  onAddSale,
  onSendReminder,
  onGoPayments,
  canRecordPayment = true,
  canViewPaymentHistory = true,
}) {
  const [showEditor, setShowEditor] = useState(false);
  const [statementSale, setStatementSale] = useState(null);
  const [installments, setInstallments] = useState([]);
  const totalValue = formatCurrencyTotals(sales);
  const collected = formatCurrencyTotals(payments);
  const currencyForSale = (sale) => sale.currency || "KES";

  useEffect(() => {
    if (statementSale?.id) {
      getSaleInstallments(statementSale.id).then((res) => {
        if (res.success && Array.isArray(res.data?.data)) {
          setInstallments(res.data.data);
        } else {
          setInstallments([]);
        }
      });
    } else {
      setInstallments([]);
    }
  }, [statementSale?.id]);

  function salePayments(saleId) {
    return payments.filter((payment) => payment.saleId === saleId);
  }

  return (
    <div>
      <PageHeading
        eyebrow="Deal management"
        title="Sales"
        description={
          canViewPaymentHistory
            ? "Register completed agreements, track installment plans and contact buyers about upcoming payments."
            : "Register assigned-property agreements and view their installment schedules."
        }
        action={
          <button
            type="button"
            onClick={() => setShowEditor(true)}
            className="inline-flex items-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60]"
          >
            <Plus size={18} /> Register sale
          </button>
        }
      />
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        {[
          ["Sales pipeline", totalValue, `${sales.length} registered deals`, CircleDollarSign],
          ...(canViewPaymentHistory
            ? [["Payments received", collected, "Across all sale accounts", ReceiptText]]
            : [
                [
                  "Active agreements",
                  sales.filter((sale) => sale.status !== "Completed").length,
                  "Related sale accounts",
                  CircleDollarSign,
                ],
              ]),
          ["Installments due", sales.filter((sale) => sale.nextDueDate).length, "Scheduled payment plans", CalendarClock],
        ].map(([title, value, caption, Icon]) => (
          <div key={title} className="flex items-start justify-between rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <div>
              <p className="text-sm text-slate-500">{title}</p>
              <p className="mt-2 text-2xl font-bold text-[#0F2A43]">{value}</p>
              <p className="mt-1 text-xs text-slate-400">{caption}</p>
            </div>
            <span className="rounded-xl bg-[#f8f0d8] p-3 text-[#0F2A43]">
              <Icon size={20} />
            </span>
          </div>
        ))}
      </div>
      <section className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
        <div className="border-b border-slate-100 p-5">
          <h2 className="font-bold text-[#0F2A43]">Sale agreements</h2>
          <p className="mt-1 text-xs text-slate-500">
            {canViewPaymentHistory
              ? "Payments update the statement balance and receipts."
              : "Assigned-property agreements and installment schedules."}
          </p>
        </div>
        {sales.length === 0 ? (
          <div className="p-12 text-center">
            <p className="font-semibold text-[#0F2A43]">No sales registered yet</p>
            <p className="mt-1 text-sm text-slate-500">
              {canViewPaymentHistory
                ? "Register your first agreement to start tracking payments."
                : "Register your first agreement for an assigned property."}
            </p>
          </div>
        ) : (
          <div className="divide-y divide-slate-100">
            {sales.map((sale) => {
              const paid = canViewPaymentHistory
                ? salePayments(sale.id).reduce((sum, payment) => sum + Number(payment.amount), 0)
                : 0;
              const balance = Math.max(0, Number(sale.salePrice) - paid);
              const progress = Math.min(100, Math.round((paid / Number(sale.salePrice)) * 100));
              const hasOutstandingBalance = canViewPaymentHistory ? balance > 0 : sale.status !== "Completed";
              return (
                <article key={sale.id} className="p-5 sm:p-6">
                  <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <h3 className="font-bold text-[#0F2A43]">{sale.propertyTitle}</h3>
                        <span className="rounded-full bg-blue-50 px-2.5 py-1 text-[11px] font-bold text-blue-700">
                          {sale.status}
                        </span>
                      </div>
                      <p className="mt-1 text-sm text-slate-500">
                        Buyer: {sale.buyerName} · Agreement #{sale.id}
                      </p>
                      <div className="mt-4 grid gap-3 sm:grid-cols-3">
                        {(canViewPaymentHistory
                          ? [
                              ["Agreed price", formatMoney(sale.salePrice, currencyForSale(sale))],
                              ["Received", formatMoney(paid, currencyForSale(sale))],
                              ["Remaining", formatMoney(balance, currencyForSale(sale))],
                            ]
                          : [
                              ["Agreed price", formatMoney(sale.salePrice, currencyForSale(sale))],
                              ["Installment plan", formatMoney(sale.installmentAmount, currencyForSale(sale))],
                              ["Next due", sale.nextDueDate ? formatDate(sale.nextDueDate) : "No date scheduled"],
                            ]
                        ).map(([label, amount]) => (
                          <div key={label}>
                            <p className="text-[11px] uppercase tracking-wider text-slate-400">{label}</p>
                            <p className="mt-1 text-sm font-bold text-[#0F2A43]">{amount}</p>
                          </div>
                        ))}
                      </div>
                      {canViewPaymentHistory ? (
                        <>
                          <div className="mt-4 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div className="h-full rounded-full bg-emerald-600" style={{ width: `${progress}%` }} />
                          </div>
                          <p className="mt-1.5 text-xs text-slate-400">
                            {progress}% received{sale.nextDueDate ? ` · Next due ${formatDate(sale.nextDueDate)}` : ""}
                          </p>
                        </>
                      ) : (
                        sale.nextDueDate && (
                          <p className="mt-1.5 text-xs text-slate-400">
                            Next installment due {formatDate(sale.nextDueDate)}
                          </p>
                        )
                      )}
                    </div>
                    <div className="flex flex-wrap gap-2 xl:justify-end">
                      {canViewPaymentHistory && (
                        <button
                          type="button"
                          onClick={() => setStatementSale(sale)}
                          className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-[#0F2A43] hover:bg-slate-50"
                        >
                          <FileText size={15} /> Statement
                        </button>
                      )}
                      {canRecordPayment && (
                        <button
                          type="button"
                          onClick={() => onGoPayments(sale.id)}
                          className="inline-flex items-center gap-1.5 rounded-lg bg-[#0F2A43] px-3 py-2 text-xs font-semibold text-white hover:bg-[#173e60]"
                        >
                          <Plus size={14} /> Record payment
                        </button>
                      )}
                      {hasOutstandingBalance && (
                        <button
                          type="button"
                          onClick={() => onSendReminder(sale)}
                          className="inline-flex items-center gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-100"
                        >
                          <ArrowRight size={14} /> Send reminder
                        </button>
                      )}
                    </div>
                  </div>
                </article>
              );
            })}
          </div>
        )}
      </section>
      {showEditor && (
        <SaleEditor
          properties={properties}
          sales={sales}
          enquiries={enquiries}
          onClose={() => setShowEditor(false)}
          onSave={onAddSale}
        />
      )}
      {statementSale && (
        <Modal
          title="Account statement"
          description={`${statementSale.buyerName} · ${statementSale.propertyTitle}`}
          onClose={() => setStatementSale(null)}
          size="max-w-xl"
        >
          <div className="print-statement p-5 sm:p-7">
            <div className="rounded-2xl bg-slate-50 p-4">
              <div className="flex justify-between py-2 text-sm">
                <span className="text-slate-500">Agreed sale price</span>
                <strong>{formatMoney(statementSale.salePrice, currencyForSale(statementSale))}</strong>
              </div>
              <div className="flex justify-between py-2 text-sm">
                <span className="text-slate-500">Total received</span>
                <strong>
                  {formatMoney(
                    salePayments(statementSale.id).reduce((sum, payment) => sum + Number(payment.amount), 0),
                    currencyForSale(statementSale)
                  )}
                </strong>
              </div>
              <div className="mt-2 flex justify-between border-t border-slate-200 pt-3 text-sm">
                <span className="font-bold text-[#0F2A43]">Outstanding balance</span>
                <strong className="text-[#0F2A43]">
                  {formatMoney(
                    Math.max(
                      0,
                      statementSale.salePrice -
                        salePayments(statementSale.id).reduce((sum, payment) => sum + Number(payment.amount), 0)
                    ),
                    currencyForSale(statementSale)
                  )}
                </strong>
              </div>
            </div>
            <h3 className="mb-2 mt-5 text-sm font-bold text-[#0F2A43]">Payment history</h3>
            {salePayments(statementSale.id).map((payment) => (
              <div key={payment.id} className="flex justify-between border-b border-slate-100 py-3 text-sm">
                <span className="text-slate-500">
                  {formatDate(payment.date)} · {payment.reference}
                </span>
                <strong>{formatMoney(payment.amount, payment.currency || currencyForSale(statementSale))}</strong>
              </div>
            ))}
            {installments.length > 0 && (
              <>
                <h3 className="mb-2 mt-5 text-sm font-bold text-[#0F2A43]">Installment schedule</h3>
                <div className="max-h-48 overflow-y-auto divide-y divide-slate-100 rounded-xl border border-slate-100 px-3">
                  {installments.map((inst) => (
                    <div key={inst.id} className="flex items-center justify-between py-2.5 text-xs">
                      <span className="text-slate-600">
                        Installment #{inst.installment_number} · Due {formatDate(inst.due_date)}
                      </span>
                      <span className="font-semibold text-[#0F2A43]">
                        {formatMoney(inst.amount_due, currencyForSale(statementSale))} ({inst.status})
                      </span>
                    </div>
                  ))}
                </div>
              </>
            )}
            <button
              type="button"
              onClick={() => window.print()}
              className="mt-5 w-full rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white"
            >
              Print statement
            </button>
          </div>
        </Modal>
      )}
    </div>
  );
}

export default Sales;
