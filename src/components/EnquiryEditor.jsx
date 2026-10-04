import { useState } from "react";
import { inputClass } from "../lib/demo";
import Modal from "./Modal";

function EnquiryEditor({ property, onClose, onSave }) {
  const [form, setForm] = useState({
    name: "",
    phone: "",
    email: "",
    source: "Website",
    contactMethod: "WhatsApp",
    message: "",
    currency: property?.currency || "KSH",
    country: property?.location.country || "Kenya",
    city: property?.location.city || "",
    area: property?.location.area || "",
    listingType: property?.listingType || "",
    propertyType: property?.type || "",
    budgetMin: "",
    budgetMax: "",
  });

  function handleChange(event) {
    const { name, value } = event.target;
    setForm((current) => ({ ...current, [name]: value }));
  }

  function handleSubmit(event) {
    event.preventDefault();
    onSave(form, property);
  }

  return (
    <Modal
      title={property ? "Record a buyer enquiry" : "Add buyer requirements"}
      description={property ? `Enquiry about ${property.title}` : "Capture what your lead is looking for and match them to suitable listings."}
      onClose={onClose}
    >
      <form onSubmit={handleSubmit} className="space-y-5 p-5 sm:p-7">
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="text-sm font-semibold text-slate-700 sm:col-span-2">Full name<input name="name" value={form.name} onChange={handleChange} required className={`${inputClass} mt-2`} placeholder="Buyer name" /></label>
          <label className="text-sm font-semibold text-slate-700">Phone<input type="tel" name="phone" value={form.phone} onChange={handleChange} required className={`${inputClass} mt-2`} placeholder="+254 700 000 000" /></label>
          <label className="text-sm font-semibold text-slate-700">Email<input type="email" name="email" value={form.email} onChange={handleChange} required className={`${inputClass} mt-2`} placeholder="buyer@example.com" /></label>
          <label className="text-sm font-semibold text-slate-700">Enquiry source
            <select name="source" value={form.source} onChange={handleChange} className={`${inputClass} mt-2`}>
              {["Website", "WhatsApp", "SMS", "Email"].map((source) => <option key={source}>{source}</option>)}
            </select>
          </label>
          <label className="text-sm font-semibold text-slate-700">Preferred contact
            <select name="contactMethod" value={form.contactMethod} onChange={handleChange} className={`${inputClass} mt-2`}>
              {["WhatsApp", "Phone", "Email"].map((method) => <option key={method}>{method}</option>)}
            </select>
          </label>
          <label className="text-sm font-semibold text-slate-700">Listing type
            <select name="listingType" value={form.listingType} onChange={handleChange} className={`${inputClass} mt-2`}>
              <option value="">Any listing</option><option>For Sale</option><option>For Rent</option><option>BnB</option>
            </select>
          </label>
          <label className="text-sm font-semibold text-slate-700">Property type
            <select name="propertyType" value={form.propertyType} onChange={handleChange} className={`${inputClass} mt-2`}>
              <option value="">Any type</option>
              {["House", "Apartment", "Villa", "Studio", "Townhouse", "Commercial", "Land"].map((type) => <option key={type}>{type}</option>)}
            </select>
          </label>
          <label className="text-sm font-semibold text-slate-700">Budget currency
            <select name="currency" value={form.currency} onChange={handleChange} className={`${inputClass} mt-2`}>
              {["KSH", "USD", "EUR", "GBP", "AED"].map((currency) => <option key={currency}>{currency}</option>)}
            </select>
          </label>
          <label className="text-sm font-semibold text-slate-700">Country<input name="country" value={form.country} onChange={handleChange} className={`${inputClass} mt-2`} placeholder="Any country" /></label>
          <label className="text-sm font-semibold text-slate-700">City<input name="city" value={form.city} onChange={handleChange} className={`${inputClass} mt-2`} placeholder="Any city" /></label>
          <label className="text-sm font-semibold text-slate-700">Area / neighbourhood<input name="area" value={form.area} onChange={handleChange} className={`${inputClass} mt-2`} placeholder="Any area" /></label>
          <div className="grid grid-cols-2 gap-3">
            <label className="text-sm font-semibold text-slate-700">Min budget<input type="number" min="0" name="budgetMin" value={form.budgetMin} onChange={handleChange} className={`${inputClass} mt-2`} placeholder="Min" /></label>
            <label className="text-sm font-semibold text-slate-700">Max budget<input type="number" min="0" name="budgetMax" value={form.budgetMax} onChange={handleChange} className={`${inputClass} mt-2`} placeholder="Max" /></label>
          </div>
        </div>
        <label className="block text-sm font-semibold text-slate-700">Buyer message<textarea name="message" value={form.message} onChange={handleChange} required rows="3" className={`${inputClass} mt-2 resize-y`} placeholder="Tell us what the buyer needs" /></label>
        <p className="text-xs text-slate-400">New leads stay in this browser demo. Matching alerts and contact messages are simulated, not delivered externally.</p>
        <div className="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
          <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">Cancel</button>
          <button type="submit" className="rounded-xl bg-[#0F2A43] px-6 py-3 text-sm font-semibold text-white hover:bg-[#173e60]">Save enquiry</button>
        </div>
      </form>
    </Modal>
  );
}

export default EnquiryEditor;
