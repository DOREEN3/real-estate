import { useState } from "react";
import { ArrowRight, CalendarClock, CircleDollarSign, FileText, Plus, ReceiptText } from "lucide-react";
import Modal from "../components/Modal";
import PageHeading from "../components/PageHeading";
import { formatCurrencyTotals, formatDate, formatMoney, inputClass } from "../lib/demo";

function SaleEditor({ properties, enquiries, onClose, onSave }) {
  const availableProperties = properties.filter((property) => property.status === "Available");
  const [form, setForm] = useState({ propertyId: availableProperties[0]?.id || "", buyerName: "", buyerEmail: "", salePrice: "", deposit: "", installmentAmount: "", installmentsTotal: "12", nextDueDate: "" });
  const [selectedEnquiry, setSelectedEnquiry] = useState("");
  const selectedProperty = availableProperties.find((property) => String(property.id) === String(form.propertyId));
  const currency = selectedProperty?.currency || "KSH";

  function selectEnquiry(event) {
    const enquiry = enquiries.find((item) => String(item.id) === event.target.value);
    setSelectedEnquiry(event.target.value);
    if (enquiry) setForm((current) => ({ ...current, buyerName: enquiry.name, buyerEmail: enquiry.email }));
  }

  function submit(event) {
    event.preventDefault();
    onSave({ ...form, propertyId: Number(form.propertyId) });
    onClose();
  }

  return (
    <Modal title="Register a sale" description="Record the buyer, agreed price, deposit and payment plan." onClose={onClose}>
      <form onSubmit={submit} className="space-y-4 p-5 sm:p-7">
        <label className="block text-sm font-semibold text-slate-700">Available property
          <select name="propertyId" value={form.propertyId} onChange={(event) => setForm({ ...form, propertyId: event.target.value })} required className={`${inputClass} mt-2`}>
            {availableProperties.map((property) => <option key={property.id} value={property.id}>{property.title} · {formatMoney(property.price, property.currency)}</option>)}
          </select>
        </label>
        <label className="block text-sm font-semibold text-slate-700">Use an existing lead (optional)
          <select value={selectedEnquiry} onChange={selectEnquiry} className={`${inputClass} mt-2`}><option value="">Enter buyer details below</option>{enquiries.map((enquiry) => <option key={enquiry.id} value={enquiry.id}>{enquiry.name} · {enquiry.email}</option>)}</select>
        </label>
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="text-sm font-semibold text-slate-700">Buyer name<input required name="buyerName" value={form.buyerName} onChange={(event) => setForm({ ...form, buyerName: event.target.value })} className={`${inputClass} mt-2`} /></label>
          <label className="text-sm font-semibold text-slate-700">Buyer email<input required type="email" name="buyerEmail" value={form.buyerEmail} onChange={(event) => setForm({ ...form, buyerEmail: event.target.value })} className={`${inputClass} mt-2`} /></label>
          <label className="text-sm font-semibold text-slate-700">Agreed sale price ({currency})<input required min="1" type="number" name="salePrice" value={form.salePrice} onChange={(event) => setForm({ ...form, salePrice: event.target.value })} className={`${inputClass} mt-2`} /></label>
          <label className="text-sm font-semibold text-slate-700">Deposit collected ({currency})<input required min="1" type="number" name="deposit" value={form.deposit} onChange={(event) => setForm({ ...form, deposit: event.target.value })} className={`${inputClass} mt-2`} /></label>
          <label className="text-sm font-semibold text-slate-700">Installment amount ({currency})<input min="0" type="number" name="installmentAmount" value={form.installmentAmount} onChange={(event) => setForm({ ...form, installmentAmount: event.target.value })} className={`${inputClass} mt-2`} placeholder="0 for no installments" /></label>
          <label className="text-sm font-semibold text-slate-700">Number of installments<input min="1" type="number" name="installmentsTotal" value={form.installmentsTotal} onChange={(event) => setForm({ ...form, installmentsTotal: event.target.value })} className={`${inputClass} mt-2`} /></label>
          <label className="text-sm font-semibold text-slate-700 sm:col-span-2">Next installment due<input type="date" name="nextDueDate" value={form.nextDueDate} onChange={(event) => setForm({ ...form, nextDueDate: event.target.value })} className={`${inputClass} mt-2`} /></label>
        </div>
        {availableProperties.length === 0 && <p className="text-sm text-red-700">There are no available properties to register as a sale.</p>}
        <div className="flex justify-end gap-3 border-t border-slate-100 pt-5">
          <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600">Cancel</button>
          <button type="submit" disabled={availableProperties.length === 0} className="rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Save sale and receipt deposit</button>
        </div>
      </form>
    </Modal>
  );
}

function Sales({ sales, properties, enquiries, payments, onAddSale, onSendReminder, onGoPayments, canRecordPayment = true }) {
  const [showEditor, setShowEditor] = useState(false);
  const [statementSale, setStatementSale] = useState(null);
  const totalValue = formatCurrencyTotals(sales);
  const collected = formatCurrencyTotals(payments);
  const currencyForSale = (sale) => sale.currency || "KSH";

  function salePayments(saleId) {
    return payments.filter((payment) => payment.saleId === saleId);
  }

  return (
    <div>
      <PageHeading eyebrow="Deal management" title="Sales" description="Register completed agreements, track installment plans and contact buyers about upcoming payments." action={<button type="button" onClick={() => setShowEditor(true)} className="inline-flex items-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60]"><Plus size={18} /> Register sale</button>} />
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        {[
          ["Sales pipeline", totalValue, `${sales.length} registered deals`, CircleDollarSign],
          ["Payments received", collected, "Across all sale accounts", ReceiptText],
          ["Installments due", sales.filter((sale) => sale.nextDueDate).length, "Scheduled payment plans", CalendarClock],
        ].map(([title, value, caption, Icon]) => <div key={title} className="flex items-start justify-between rounded-2xl border border-slate-100 bg-white p-5 shadow-sm"><div><p className="text-sm text-slate-500">{title}</p><p className="mt-2 text-2xl font-bold text-[#0F2A43]">{value}</p><p className="mt-1 text-xs text-slate-400">{caption}</p></div><span className="rounded-xl bg-[#f8f0d8] p-3 text-[#0F2A43]"><Icon size={20} /></span></div>)}
      </div>
      <section className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
        <div className="border-b border-slate-100 p-5"><h2 className="font-bold text-[#0F2A43]">Sale agreements</h2><p className="mt-1 text-xs text-slate-500">Payments update the statement balance and receipts.</p></div>
        {sales.length === 0 ? <div className="p-12 text-center"><p className="font-semibold text-[#0F2A43]">No sales registered yet</p><p className="mt-1 text-sm text-slate-500">Register your first agreement to start tracking payments.</p></div> : <div className="divide-y divide-slate-100">{sales.map((sale) => {
          const paid = salePayments(sale.id).reduce((sum, payment) => sum + Number(payment.amount), 0);
          const balance = Math.max(0, Number(sale.salePrice) - paid);
          const progress = Math.min(100, Math.round((paid / Number(sale.salePrice)) * 100));
          return <article key={sale.id} className="p-5 sm:p-6">
            <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2"><h3 className="font-bold text-[#0F2A43]">{sale.propertyTitle}</h3><span className="rounded-full bg-blue-50 px-2.5 py-1 text-[11px] font-bold text-blue-700">{sale.status}</span></div>
                <p className="mt-1 text-sm text-slate-500">Buyer: {sale.buyerName} · Agreement #{sale.id}</p>
                <div className="mt-4 grid gap-3 sm:grid-cols-3">
                  {[["Agreed price", formatMoney(sale.salePrice, currencyForSale(sale))], ["Received", formatMoney(paid, currencyForSale(sale))], ["Remaining", formatMoney(balance, currencyForSale(sale))]].map(([label, amount]) => <div key={label}><p className="text-[11px] uppercase tracking-wider text-slate-400">{label}</p><p className="mt-1 text-sm font-bold text-[#0F2A43]">{amount}</p></div>)}
                </div>
                <div className="mt-4 h-2 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-emerald-600" style={{ width: `${progress}%` }} /></div>
                <p className="mt-1.5 text-xs text-slate-400">{progress}% received{sale.nextDueDate ? ` · Next due ${formatDate(sale.nextDueDate)}` : ""}</p>
              </div>
              <div className="flex flex-wrap gap-2 xl:justify-end">
                <button type="button" onClick={() => setStatementSale(sale)} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-[#0F2A43] hover:bg-slate-50"><FileText size={15} /> Statement</button>
                {canRecordPayment && <button type="button" onClick={() => onGoPayments(sale.id)} className="inline-flex items-center gap-1.5 rounded-lg bg-[#0F2A43] px-3 py-2 text-xs font-semibold text-white hover:bg-[#173e60]"><Plus size={14} /> Record payment</button>}
                {balance > 0 && <button type="button" onClick={() => onSendReminder(sale)} className="inline-flex items-center gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-100"><ArrowRight size={14} /> Send reminder</button>}
              </div>
            </div>
          </article>;
        })}</div>}
      </section>
      {showEditor && <SaleEditor properties={properties} enquiries={enquiries} onClose={() => setShowEditor(false)} onSave={onAddSale} />}
      {statementSale && <Modal title="Account statement" description={`${statementSale.buyerName} · ${statementSale.propertyTitle}`} onClose={() => setStatementSale(null)} size="max-w-xl">
        <div className="print-statement p-5 sm:p-7">
          <div className="rounded-2xl bg-slate-50 p-4"><div className="flex justify-between py-2 text-sm"><span className="text-slate-500">Agreed sale price</span><strong>{formatMoney(statementSale.salePrice, currencyForSale(statementSale))}</strong></div><div className="flex justify-between py-2 text-sm"><span className="text-slate-500">Total received</span><strong>{formatMoney(salePayments(statementSale.id).reduce((sum, payment) => sum + Number(payment.amount), 0), currencyForSale(statementSale))}</strong></div><div className="mt-2 flex justify-between border-t border-slate-200 pt-3 text-sm"><span className="font-bold text-[#0F2A43]">Outstanding balance</span><strong className="text-[#0F2A43]">{formatMoney(Math.max(0, statementSale.salePrice - salePayments(statementSale.id).reduce((sum, payment) => sum + Number(payment.amount), 0)), currencyForSale(statementSale))}</strong></div></div>
          <h3 className="mb-2 mt-5 text-sm font-bold text-[#0F2A43]">Payment history</h3>
          {salePayments(statementSale.id).map((payment) => <div key={payment.id} className="flex justify-between border-b border-slate-100 py-3 text-sm"><span className="text-slate-500">{formatDate(payment.date)} · {payment.reference}</span><strong>{formatMoney(payment.amount)}</strong></div>)}
          <button type="button" onClick={() => window.print()} className="mt-5 w-full rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white">Print statement</button>
        </div>
      </Modal>}
    </div>
  );
}

export default Sales;
