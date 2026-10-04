import { useState } from "react";
import { BadgeCheck, Building2, Check, Mail, MapPin, Phone, Plus, ShieldCheck, UserRoundCheck, X } from "lucide-react";
import Modal from "../components/Modal";
import PageHeading from "../components/PageHeading";
import { inputClass } from "../lib/demo";

function PersonEditor({ kind, onClose, onSave }) {
  const [form, setForm] = useState({ name: "", email: "", phone: "", city: "", country: "", agency: "" });
  const label = kind === "Agents" ? "agent" : "property owner";

  function submit(event) {
    event.preventDefault();
    onSave(form);
    onClose();
  }

  return (
    <Modal title={`Register ${label}`} description="New registrations start as pending verification." onClose={onClose} size="max-w-xl">
      <form onSubmit={submit} className="space-y-4 p-5 sm:p-7">
        <label className="block text-sm font-semibold text-slate-700">Full name / business name<input name="name" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required className={`${inputClass} mt-2`} /></label>
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="text-sm font-semibold text-slate-700">Email<input type="email" name="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} required className={`${inputClass} mt-2`} /></label>
          <label className="text-sm font-semibold text-slate-700">Phone<input type="tel" name="phone" value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} required className={`${inputClass} mt-2`} /></label>
          <label className="text-sm font-semibold text-slate-700">City<input name="city" value={form.city} onChange={(event) => setForm({ ...form, city: event.target.value })} className={`${inputClass} mt-2`} placeholder="City / market" /></label>
          <label className="text-sm font-semibold text-slate-700">Country<input name="country" value={form.country} onChange={(event) => setForm({ ...form, country: event.target.value })} className={`${inputClass} mt-2`} placeholder="Country" /></label>
          {kind === "Agents" && <label className="text-sm font-semibold text-slate-700">Agency<input name="agency" value={form.agency} onChange={(event) => setForm({ ...form, agency: event.target.value })} className={`${inputClass} mt-2`} placeholder="Agency name" /></label>}
        </div>
        <div className="flex justify-end gap-3 border-t border-slate-100 pt-5">
          <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600">Cancel</button>
          <button type="submit" className="rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white">Submit for verification</button>
        </div>
      </form>
    </Modal>
  );
}

function People({ kind, people, onUpdateVerification, onAddPerson, onListProperty }) {
  const [showEditor, setShowEditor] = useState(false);
  const isAgent = kind === "Agents";
  const verifiedCount = people.filter((person) => person.verified).length;

  return (
    <div>
      <PageHeading
        eyebrow="Verified network"
        title={isAgent ? "Agents" : "Property owners"}
        description={isAgent ? "Manage agent registrations, verification and listing access." : "Keep property owners verified and their listings connected to the team."}
        action={<button type="button" onClick={() => setShowEditor(true)} className="inline-flex items-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60]"><Plus size={18} /> Register {isAgent ? "agent" : "owner"}</button>}
      />
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">Registered</p><p className="mt-2 text-3xl font-bold text-[#0F2A43]">{people.length}</p></div>
        <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">Verified</p><p className="mt-2 text-3xl font-bold text-emerald-700">{verifiedCount}</p></div>
        <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm"><p className="text-sm text-slate-500">Awaiting review</p><p className="mt-2 text-3xl font-bold text-amber-700">{people.length - verifiedCount}</p></div>
      </div>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {people.map((person) => (
          <article key={person.id} className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <div className="flex items-start justify-between gap-3">
              <div className="flex min-w-0 items-center gap-3">
                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[#f8f0d8] text-lg font-bold text-[#0F2A43]">{person.name.split(/\s+/).map((part) => part[0]).slice(0, 2).join("").toUpperCase()}</span>
                <div className="min-w-0"><h2 className="truncate font-bold text-[#0F2A43]">{person.name}</h2><p className="mt-1 truncate text-xs text-slate-500">{isAgent ? person.agency || "Independent agent" : "Property owner"}</p></div>
              </div>
              {person.verified ? <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-bold text-emerald-700"><BadgeCheck size={14} /> Verified</span> : <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-bold text-amber-800"><ShieldCheck size={13} /> Pending</span>}
            </div>
            <div className="my-5 space-y-2 text-sm text-slate-500">
              <p className="flex items-center gap-2"><Mail size={15} />{person.email}</p>
              <p className="flex items-center gap-2"><Phone size={15} />{person.phone}</p>
              <p className="flex items-center gap-2"><MapPin size={15} />{person.city || "Location not set"}</p>
              <p className="flex items-center gap-2"><Building2 size={15} />{isAgent ? `${person.listings || 0} active listings` : `${person.properties || 0} properties`}</p>
            </div>
            <div className="flex gap-2">
              <button type="button" onClick={() => onUpdateVerification(person.id, !person.verified)} className={`inline-flex flex-1 items-center justify-center gap-2 rounded-xl px-3 py-2.5 text-xs font-semibold transition ${person.verified ? "border border-slate-200 text-slate-600 hover:bg-slate-50" : "bg-emerald-700 text-white hover:bg-emerald-800"}`}>
                {person.verified ? <><X size={15} /> Revoke verification</> : <><Check size={15} /> Verify identity</>}
              </button>
              {person.verified && <button type="button" onClick={() => onListProperty(kind, person)} className="inline-flex flex-1 items-center justify-center gap-2 rounded-xl bg-[#0F2A43] px-3 py-2.5 text-xs font-semibold text-white hover:bg-[#173e60]"><Plus size={14} /> List property</button>}
            </div>
          </article>
        ))}
      </div>
      <div className="mt-6 flex items-start gap-3 rounded-2xl border border-blue-100 bg-blue-50/70 p-4 text-sm text-blue-900"><UserRoundCheck size={19} className="mt-0.5 shrink-0" /><p>Only verified agents and owners can be selected as direct listing sources. Buyer contact requests remain routed to the admin team.</p></div>
      {showEditor && <PersonEditor kind={kind} onClose={() => setShowEditor(false)} onSave={(person) => onAddPerson(kind, person)} />}
    </div>
  );
}

export default People;
