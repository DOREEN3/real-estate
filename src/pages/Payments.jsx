import { useState } from "react";
import { ArrowDownLeft, CalendarClock, Download, Plus, ReceiptText } from "lucide-react";
import Modal from "../components/Modal";
import PageHeading from "../components/PageHeading";
import { formatCurrencyTotals, formatDate, formatMoney, inputClass } from "../lib/demo";

function PaymentEditor({ sales, onClose, onSave }) {
  const [form, setForm] = useState({ saleId: sales[0]?.id || "", amount: "", method: "M-Pesa", date: new Date().toISOString().slice(0, 10), note: "" });
  const selectedSale = sales.find((sale) => String(sale.id) === String(form.saleId));
  const currency = selectedSale?.currency || "KSH";

  function submit(event) {
    event.preventDefault();
    onSave({ ...form, saleId: Number(form.saleId) });
    onClose();
  }

  return (
    <Modal title="Record a payment" description="Record a receipt against a sale account." onClose={onClose} size="max-w-xl">
      <form onSubmit={submit} className="space-y-4 p-5 sm:p-7">
        {sales.length === 0 ? <p className="rounded-xl bg-amber-50 p-4 text-sm text-amber-800">Register a sale before recording payments.</p> : <>
          <label className="block text-sm font-semibold text-slate-700">Sale account<select value={form.saleId} onChange={(event) => setForm({ ...form, saleId: event.target.value })} className={`${inputClass} mt-2`}>{sales.map((sale) => <option key={sale.id} value={sale.id}>{sale.buyerName} · {sale.propertyTitle}</option>)}</select></label>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="text-sm font-semibold text-slate-700">Amount ({currency})<input required min="1" type="number" value={form.amount} onChange={(event) => setForm({ ...form, amount: event.target.value })} className={`${inputClass} mt-2`} /></label>
            <label className="text-sm font-semibold text-slate-700">Payment date<input required type="date" value={form.date} onChange={(event) => setForm({ ...form, date: event.target.value })} className={`${inputClass} mt-2`} /></label>
            <label className="text-sm font-semibold text-slate-700 sm:col-span-2">Method<select value={form.method} onChange={(event) => setForm({ ...form, method: event.target.value })} className={`${inputClass} mt-2`}>{["M-Pesa", "Bank transfer", "Cash", "Card", "Other"].map((method) => <option key={method}>{method}</option>)}</select></label>
            <label className="text-sm font-semibold text-slate-700 sm:col-span-2">Note / transaction reference<input value={form.note} onChange={(event) => setForm({ ...form, note: event.target.value })} className={`${inputClass} mt-2`} placeholder="Optional payment reference" /></label>
          </div>
        </>}
        <div className="flex justify-end gap-3 border-t border-slate-100 pt-5">
          <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600">Cancel</button>
          <button type="submit" disabled={!sales.length} className="rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">Save payment & issue receipt</button>
        </div>
      </form>
    </Modal>
  );
}

function Payments({ payments, sales, onRecordPayment, highlightedSaleId }) {
  const [showEditor, setShowEditor] = useState(false);
  const [selectedReceipt, setSelectedReceipt] = useState(null);
  const total = formatCurrencyTotals(payments);
  const highlightedSale = sales.find((sale) => sale.id === highlightedSaleId);

  function saleBalance(sale) {
    const received = payments.filter((payment) => payment.saleId === sale.id).reduce((sum, payment) => sum + Number(payment.amount), 0);
    return Math.max(0, sale.salePrice - received);
  }

  return (
    <div>
      <PageHeading eyebrow="Receipting and collections" title="Payments" description="Maintain payment records, view outstanding balances and issue receipts for every collection." action={<button type="button" onClick={() => setShowEditor(true)} className="inline-flex items-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60]"><Plus size={18} /> Record payment</button>} />
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        {[[ "Received to date", total, "Receipted collections", ArrowDownLeft], ["Receipts issued", payments.length, "Unique transaction records", ReceiptText], ["Open balances", sales.filter((sale) => saleBalance(sale) > 0).length, "Sale accounts with a balance", CalendarClock]].map(([label, value, caption, Icon]) => <div key={label} className="flex items-start justify-between rounded-2xl border border-slate-100 bg-white p-5 shadow-sm"><div><p className="text-sm text-slate-500">{label}</p><p className="mt-2 text-2xl font-bold text-[#0F2A43]">{value}</p><p className="mt-1 text-xs text-slate-400">{caption}</p></div><span className="rounded-xl bg-[#f8f0d8] p-3 text-[#0F2A43]"><Icon size={20} /></span></div>)}
      </div>
      {highlightedSale && <div className="mb-5 flex flex-col justify-between gap-3 rounded-2xl border border-blue-100 bg-blue-50 p-4 sm:flex-row sm:items-center"><div><p className="text-xs font-bold uppercase tracking-wider text-blue-700">Selected sale account</p><p className="mt-1 font-bold text-[#0F2A43]">{highlightedSale.buyerName} · {highlightedSale.propertyTitle}</p><p className="mt-1 text-sm text-slate-600">Balance due {formatMoney(saleBalance(highlightedSale))}</p></div><button type="button" onClick={() => setShowEditor(true)} className="rounded-lg bg-[#0F2A43] px-4 py-2.5 text-sm font-semibold text-white">Add payment</button></div>}
      <section className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
        <div className="border-b border-slate-100 p-5"><h2 className="font-bold text-[#0F2A43]">Receipts and account balances</h2></div>
        {payments.length ? <div className="overflow-x-auto"><table className="w-full min-w-[720px] text-left">
          <thead className="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500"><tr>{["Receipt", "Payer / account", "Date", "Method", "Amount", ""].map((heading) => <th key={heading} className="px-5 py-3 font-bold">{heading}</th>)}</tr></thead>
          <tbody className="divide-y divide-slate-100">{payments.map((payment) => <tr key={payment.id} className="text-sm hover:bg-slate-50/60"><td className="px-5 py-4 font-mono text-xs font-semibold text-[#0F2A43]">{payment.reference}</td><td className="px-5 py-4"><p className="font-semibold text-[#0F2A43]">{payment.buyerName}</p><p className="mt-1 text-xs text-slate-500">{payment.propertyTitle}</p></td><td className="px-5 py-4 text-slate-500">{formatDate(payment.date)}</td><td className="px-5 py-4 text-slate-500">{payment.method}</td><td className="px-5 py-4 font-bold text-[#0F2A43]">{formatMoney(payment.amount, payment.currency || "KSH")}</td><td className="px-5 py-4"><button type="button" onClick={() => setSelectedReceipt(payment)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-[#0F2A43] hover:bg-slate-100"><ReceiptText size={14} /> Receipt</button></td></tr>)}</tbody>
        </table></div> : <div className="p-12 text-center"><p className="font-semibold text-[#0F2A43]">No payments recorded</p><p className="mt-1 text-sm text-slate-500">Once a sale has been registered, add payments and issue receipts here.</p></div>}
      </section>
      <section className="mt-6 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm"><h2 className="mb-4 font-bold text-[#0F2A43]">Outstanding sale balances</h2>      {sales.length ? <div className="divide-y divide-slate-100">{sales.map((sale) => <div key={sale.id} className="flex flex-col justify-between gap-2 py-3 sm:flex-row sm:items-center"><div><p className="text-sm font-semibold text-[#0F2A43]">{sale.buyerName} · {sale.propertyTitle}</p><p className="mt-1 text-xs text-slate-500">Agreed {formatMoney(sale.salePrice, sale.currency || "KSH")} · {sale.nextDueDate ? `Next installment ${formatDate(sale.nextDueDate)}` : "No future installment scheduled"}</p></div><p className="font-bold text-amber-800">{formatMoney(saleBalance(sale), sale.currency || "KSH")} due</p></div>)}</div> : <p className="text-sm text-slate-500">No sale accounts created yet.</p>}</section>

      {showEditor && <PaymentEditor sales={sales} onClose={() => setShowEditor(false)} onSave={onRecordPayment} />}
      {selectedReceipt && <Modal title="Payment receipt" description={`Receipt reference ${selectedReceipt.reference}`} onClose={() => setSelectedReceipt(null)} size="max-w-lg">
        <div className="print-receipt p-6 sm:p-8">
          <div className="mb-6 border-b border-slate-200 pb-5"><p className="text-xs font-bold uppercase tracking-[0.2em] text-[#B88912]">GoldERP · Receipt</p><h3 className="mt-2 text-2xl font-bold text-[#0F2A43]">Payment received</h3><p className="mt-2 font-mono text-sm text-slate-500">{selectedReceipt.reference}</p></div>
          {[["Received from", selectedReceipt.buyerName], ["Property", selectedReceipt.propertyTitle], ["Payment date", formatDate(selectedReceipt.date)], ["Payment method", selectedReceipt.method], ["Transaction note", selectedReceipt.note || "—"]].map(([label, value]) => <div key={label} className="flex justify-between gap-4 border-b border-slate-100 py-3 text-sm"><span className="text-slate-500">{label}</span><span className="text-right font-semibold text-[#0F2A43]">{value}</span></div>)}
          <div className="mt-5 flex justify-between rounded-xl bg-slate-50 p-4"><span className="font-bold text-[#0F2A43]">Amount received</span><strong className="text-lg text-[#0F2A43]">{formatMoney(selectedReceipt.amount, selectedReceipt.currency || "KSH")}</strong></div>
          <button type="button" onClick={() => window.print()} className="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white print:hidden"><Download size={16} /> Print receipt</button>
        </div>
      </Modal>}
    </div>
  );
}

export default Payments;
