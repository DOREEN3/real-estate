import { useState } from "react";
import { CalendarClock, CheckCircle2, CircleDollarSign, Plus, ReceiptText } from "lucide-react";
import Modal from "../components/Modal";
import PageHeading from "../components/PageHeading";
import { formatCurrencyTotals, formatDate, formatMoney, inputClass } from "../lib/demo";

function RentalEditor({ properties, rentals, onClose, onSave }) {
  const availableProperties = properties.filter((property) =>
    property.listingType === "For Rent" &&
    property.status === "Available" &&
    !rentals.some((rental) => rental.propertyId === property.id && rental.status === "Active")
  );
  const [form, setForm] = useState({
    propertyId: String(availableProperties[0]?.id || ""),
    tenantName: "",
    tenantEmail: "",
    monthlyRent: String(availableProperties[0]?.price || ""),
    dueDay: "5",
  });
  const selectedProperty = availableProperties.find((property) => String(property.id) === form.propertyId);
  const currency = selectedProperty?.currency || "KSH";

  function submit(event) {
    event.preventDefault();
    onSave({ ...form, propertyId: Number(form.propertyId) });
    onClose();
  }

  return (
    <Modal title="Register a rental" description="Link a tenant to an available rental property and set the monthly rent." onClose={onClose}>
      <form onSubmit={submit} className="space-y-4 p-5 sm:p-7">
        <label className="block text-sm font-semibold text-slate-700">Available rental property
          <select required value={form.propertyId} onChange={(event) => {
            const property = availableProperties.find((item) => String(item.id) === event.target.value);
            setForm({ ...form, propertyId: event.target.value, monthlyRent: String(property?.price || "") });
          }} className={`${inputClass} mt-2`}>
            {availableProperties.map((property) => <option key={property.id} value={property.id}>{property.title} · {formatMoney(property.price, property.currency)}</option>)}
          </select>
        </label>
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="text-sm font-semibold text-slate-700">Tenant name<input required value={form.tenantName} onChange={(event) => setForm({ ...form, tenantName: event.target.value })} className={`${inputClass} mt-2`} /></label>
          <label className="text-sm font-semibold text-slate-700">Tenant email<input required type="email" value={form.tenantEmail} onChange={(event) => setForm({ ...form, tenantEmail: event.target.value })} className={`${inputClass} mt-2`} /></label>
          <label className="text-sm font-semibold text-slate-700">Monthly rent ({currency})<input required min="1" type="number" value={form.monthlyRent} onChange={(event) => setForm({ ...form, monthlyRent: event.target.value })} className={`${inputClass} mt-2`} /></label>
          <label className="text-sm font-semibold text-slate-700">Rent due day (1–28)<input required min="1" max="28" type="number" value={form.dueDay} onChange={(event) => setForm({ ...form, dueDay: event.target.value })} className={`${inputClass} mt-2`} /></label>
        </div>
        {availableProperties.length === 0 && <p className="text-sm text-amber-800">There are no available rental properties to register.</p>}
        <div className="flex justify-end gap-3 border-t border-slate-100 pt-5">
          <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600">Cancel</button>
          <button type="submit" disabled={!availableProperties.length} className="rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">Save rental</button>
        </div>
      </form>
    </Modal>
  );
}

function RentPaymentEditor({ rentals, initialRentalId, onClose, onSave }) {
  const today = new Date();
  const todayString = today.toISOString().slice(0, 10);
  const [form, setForm] = useState({
    rentalId: String(initialRentalId || rentals[0]?.id || ""),
    amount: String(rentals.find((item) => item.id === initialRentalId)?.monthlyRent || rentals[0]?.monthlyRent || ""),
    period: today.toISOString().slice(0, 7),
    date: todayString,
    method: "M-Pesa",
    note: "",
  });
  const rental = rentals.find((item) => String(item.id) === form.rentalId);

  function submit(event) {
    event.preventDefault();
    onSave({ ...form, rentalId: Number(form.rentalId) });
    onClose();
  }

  return (
    <Modal title="Record rent payment" description="Record a payment against a tenant and rent month. A receipt will be issued." onClose={onClose} size="max-w-xl">
      <form onSubmit={submit} className="space-y-4 p-5 sm:p-7">
        <label className="block text-sm font-semibold text-slate-700">Tenant / rental account
          <select required value={form.rentalId} onChange={(event) => {
            const selected = rentals.find((item) => String(item.id) === event.target.value);
            setForm({ ...form, rentalId: event.target.value, amount: String(selected?.monthlyRent || "") });
          }} className={`${inputClass} mt-2`}>
            {rentals.map((item) => <option key={item.id} value={item.id}>{item.tenantName} · {item.propertyTitle}</option>)}
          </select>
        </label>
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="text-sm font-semibold text-slate-700">Rent month<input required type="month" value={form.period} onChange={(event) => setForm({ ...form, period: event.target.value })} className={`${inputClass} mt-2`} /></label>
          <label className="text-sm font-semibold text-slate-700">Amount ({rental?.currency || "KSH"})<input required min="1" type="number" value={form.amount} onChange={(event) => setForm({ ...form, amount: event.target.value })} className={`${inputClass} mt-2`} placeholder={`Monthly rent ${formatMoney(rental?.monthlyRent || 0, rental?.currency || "KSH")}`} /></label>
          <label className="text-sm font-semibold text-slate-700">Payment date<input required type="date" value={form.date} onChange={(event) => setForm({ ...form, date: event.target.value })} className={`${inputClass} mt-2`} /></label>
          <label className="text-sm font-semibold text-slate-700">Method<select value={form.method} onChange={(event) => setForm({ ...form, method: event.target.value })} className={`${inputClass} mt-2`}>{["M-Pesa", "Bank transfer", "Cash", "Card", "Other"].map((method) => <option key={method}>{method}</option>)}</select></label>
          <label className="text-sm font-semibold text-slate-700 sm:col-span-2">Note / transaction reference<input value={form.note} onChange={(event) => setForm({ ...form, note: event.target.value })} className={`${inputClass} mt-2`} placeholder="Optional payment reference" /></label>
        </div>
        <div className="flex justify-end gap-3 border-t border-slate-100 pt-5">
          <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600">Cancel</button>
          <button type="submit" disabled={!rentals.length} className="rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">Save payment &amp; issue receipt</button>
        </div>
      </form>
    </Modal>
  );
}

function Rentals({ rentals, properties, rentPayments, onAddRental, onRecordRentPayment, canRecordPayment = true }) {
  const [showRentalEditor, setShowRentalEditor] = useState(false);
  const [paymentRentalId, setPaymentRentalId] = useState(null);
  const [selectedReceipt, setSelectedReceipt] = useState(null);
  const currentPeriod = new Date().toISOString().slice(0, 7);

  function rentalPayments(rentalId, period) {
    return rentPayments.filter((payment) => payment.rentalId === rentalId && (!period || payment.period === period));
  }

  function amountPaid(rental, period) {
    return rentalPayments(rental.id, period).reduce((sum, payment) => sum + Number(payment.amount), 0);
  }

  function rentStatus(rental) {
    const paid = amountPaid(rental, currentPeriod);
    if (paid >= Number(rental.monthlyRent)) return "Paid";
    if (paid > 0) return "Partially paid";
    return new Date().getDate() > Number(rental.dueDay) ? "Overdue" : "Due";
  }

  const paidThisMonth = rentals.filter((rental) => rentStatus(rental) === "Paid").length;
  const outstanding = formatCurrencyTotals(rentals.map((rental) => ({
    currency: rental.currency || "KSH",
    amount: Math.max(0, Number(rental.monthlyRent) - amountPaid(rental, currentPeriod)),
  })));
  const statusStyle = {
    Paid: "bg-emerald-50 text-emerald-700",
    "Partially paid": "bg-amber-50 text-amber-800",
    Due: "bg-blue-50 text-blue-700",
    Overdue: "bg-red-50 text-red-700",
  };

  return (
    <div>
      <PageHeading eyebrow="Tenancies and rent collection" title="Rentals" description="Manage tenants, track monthly rent due and paid, and issue payment receipts." action={<button type="button" onClick={() => setShowRentalEditor(true)} className="inline-flex items-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60]"><Plus size={18} /> Register rental</button>} />
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        {[
          ["Active rentals", rentals.length, "Tenant accounts", CircleDollarSign],
          ["Rent paid this month", `${paidThisMonth} / ${rentals.length}`, currentPeriod, CheckCircle2],
          ["Rent outstanding", outstanding, "Remaining for this month", CalendarClock],
        ].map(([label, value, caption, Icon]) => <div key={label} className="flex items-start justify-between rounded-2xl border border-slate-100 bg-white p-5 shadow-sm"><div><p className="text-sm text-slate-500">{label}</p><p className="mt-2 text-2xl font-bold text-[#0F2A43]">{value}</p><p className="mt-1 text-xs text-slate-400">{caption}</p></div><span className="rounded-xl bg-[#f8f0d8] p-3 text-[#0F2A43]"><Icon size={20} /></span></div>)}
      </div>
      <section className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
        <div className="border-b border-slate-100 p-5"><h2 className="font-bold text-[#0F2A43]">Tenant accounts</h2><p className="mt-1 text-xs text-slate-500">Current-month status changes to Paid when recorded payments meet the monthly rent.</p></div>
        {rentals.length ? <div className="divide-y divide-slate-100">{rentals.map((rental) => {
          const paid = amountPaid(rental, currentPeriod);
          const status = rentStatus(rental);
          return <article key={rental.id} className="flex flex-col justify-between gap-4 p-5 sm:flex-row sm:items-center">
            <div>
              <div className="flex flex-wrap items-center gap-2"><h3 className="font-bold text-[#0F2A43]">{rental.propertyTitle}</h3><span className={`rounded-full px-2.5 py-1 text-[11px] font-bold ${statusStyle[status]}`}>{status}</span></div>
              <p className="mt-1 text-sm text-slate-500">{rental.tenantName} · {rental.tenantEmail}</p>
              <p className="mt-2 text-xs text-slate-500">Monthly rent {formatMoney(rental.monthlyRent, rental.currency)} · Due on day {rental.dueDay} · {currentPeriod}: {formatMoney(paid, rental.currency)} paid of {formatMoney(rental.monthlyRent, rental.currency)}</p>
            </div>
            {canRecordPayment && <button type="button" onClick={() => setPaymentRentalId(rental.id)} className="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-[#0F2A43] px-3 py-2 text-xs font-semibold text-white hover:bg-[#173e60]"><Plus size={14} /> Record rent</button>}
          </article>;
        })}</div> : <div className="p-12 text-center"><p className="font-semibold text-[#0F2A43]">No rental accounts yet</p><p className="mt-1 text-sm text-slate-500">Register a tenant on an available For Rent property to start tracking monthly rent.</p></div>}
      </section>
      <section className="mt-6 overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
        <div className="border-b border-slate-100 p-5"><h2 className="font-bold text-[#0F2A43]">Rent payment receipts</h2></div>
        {rentPayments.length ? <div className="overflow-x-auto"><table className="w-full min-w-[760px] text-left">
          <thead className="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500"><tr>{["Receipt", "Tenant / property", "Rent month", "Date", "Method", "Amount", ""].map((heading) => <th key={heading} className="px-5 py-3 font-bold">{heading}</th>)}</tr></thead>
          <tbody className="divide-y divide-slate-100">{rentPayments.map((payment) => <tr key={payment.id} className="text-sm hover:bg-slate-50/60"><td className="px-5 py-4 font-mono text-xs font-semibold text-[#0F2A43]">{payment.reference}</td><td className="px-5 py-4"><p className="font-semibold text-[#0F2A43]">{payment.tenantName}</p><p className="mt-1 text-xs text-slate-500">{payment.propertyTitle}</p></td><td className="px-5 py-4 text-slate-500">{formatDate(`${payment.period}-01`)}</td><td className="px-5 py-4 text-slate-500">{formatDate(payment.date)}</td><td className="px-5 py-4 text-slate-500">{payment.method}</td><td className="px-5 py-4 font-bold text-[#0F2A43]">{formatMoney(payment.amount, payment.currency)}</td><td className="px-5 py-4"><button type="button" onClick={() => setSelectedReceipt(payment)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-[#0F2A43] hover:bg-slate-100"><ReceiptText size={14} /> Receipt</button></td></tr>)}</tbody>
        </table></div> : <div className="p-10 text-center text-sm text-slate-500">No rent payments recorded yet.</div>}
      </section>
      {showRentalEditor && <RentalEditor properties={properties} rentals={rentals} onClose={() => setShowRentalEditor(false)} onSave={onAddRental} />}
      {paymentRentalId !== null && <RentPaymentEditor rentals={rentals} initialRentalId={paymentRentalId} onClose={() => setPaymentRentalId(null)} onSave={onRecordRentPayment} />}
      {selectedReceipt && <Modal title="Rent payment receipt" description={`Receipt reference ${selectedReceipt.reference}`} onClose={() => setSelectedReceipt(null)} size="max-w-lg">
        <div className="print-receipt p-6 sm:p-8">
          <div className="mb-6 border-b border-slate-200 pb-5"><p className="text-xs font-bold uppercase tracking-[0.2em] text-[#B88912]">GoldERP · Rent receipt</p><h3 className="mt-2 text-2xl font-bold text-[#0F2A43]">Rent payment received</h3><p className="mt-2 font-mono text-sm text-slate-500">{selectedReceipt.reference}</p></div>
          {[["Received from", selectedReceipt.tenantName], ["Property", selectedReceipt.propertyTitle], ["Rent month", formatDate(`${selectedReceipt.period}-01`)], ["Payment date", formatDate(selectedReceipt.date)], ["Payment method", selectedReceipt.method], ["Transaction note", selectedReceipt.note || "—"]].map(([label, value]) => <div key={label} className="flex justify-between gap-4 border-b border-slate-100 py-3 text-sm"><span className="text-slate-500">{label}</span><span className="text-right font-semibold text-[#0F2A43]">{value}</span></div>)}
          <div className="mt-5 flex justify-between rounded-xl bg-slate-50 p-4"><span className="font-bold text-[#0F2A43]">Amount received</span><strong className="text-lg text-[#0F2A43]">{formatMoney(selectedReceipt.amount, selectedReceipt.currency)}</strong></div>
          <button type="button" onClick={() => window.print()} className="mt-5 w-full rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white print:hidden">Print receipt</button>
        </div>
      </Modal>}
    </div>
  );
}

export default Rentals;
