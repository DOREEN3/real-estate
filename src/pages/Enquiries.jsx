import { useState } from "react";
import { ArrowRight, BellRing, Mail, MapPin, MessageSquare, Plus, Sparkles } from "lucide-react";
import EnquiryEditor from "../components/EnquiryEditor";
import PageHeading from "../components/PageHeading";
import { formatDate, formatMoney, getMatches } from "../lib/demo";

const statusStyles = {
  New: "bg-blue-50 text-blue-700",
  Contacted: "bg-amber-50 text-amber-800",
  "Follow Up": "bg-violet-50 text-violet-700",
  Matched: "bg-emerald-50 text-emerald-700",
  Closed: "bg-slate-100 text-slate-600",
};

function Enquiries({ enquiries, properties, onAddEnquiry, onUpdateStatus, onNotifyMatch, readOnly = false }) {
  const [showEditor, setShowEditor] = useState(false);
  const [statusFilter, setStatusFilter] = useState("All");
  const [sourceFilter, setSourceFilter] = useState("All");
  const [expandedId, setExpandedId] = useState(null);
  const statuses = ["All", "New", "Contacted", "Follow Up", "Matched", "Closed"];
  const sources = ["All", "WhatsApp", "SMS", "Email", "Website"];
  const visibleEnquiries = enquiries.filter((enquiry) =>
    (statusFilter === "All" || enquiry.status === statusFilter) &&
    (sourceFilter === "All" || (enquiry.source || "Website") === sourceFilter)
  );

  return (
    <div>
      <PageHeading
        eyebrow="Lead management"
        title="Buyer enquiries"
        description="Capture buyer needs, track follow-up and find suitable properties across your portfolio."
        action={!readOnly && <button type="button" onClick={() => setShowEditor(true)} className="inline-flex items-center justify-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60]"><Plus size={18} /> Add enquiry</button>}
      />

      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        {[
          ["Active leads", enquiries.filter((enquiry) => enquiry.status !== "Closed").length, "Currently being followed up"],
          ["New enquiries", enquiries.filter((enquiry) => enquiry.status === "New").length, "Waiting for a first follow-up"],
          ["Property matches", enquiries.reduce((count, enquiry) => count + properties.filter((property) => getMatches(property, [enquiry]).length > 0).length, 0), "Potential matches across listings"],
        ].map(([title, value, caption]) => (
          <div key={title} className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <p className="text-sm font-medium text-slate-500">{title}</p>
            <p className="mt-2 text-3xl font-bold text-[#0F2A43]">{value}</p>
            <p className="mt-1 text-xs text-slate-400">{caption}</p>
          </div>
        ))}
      </div>

      <section className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
        <div className="flex flex-col gap-4 border-b border-slate-100 p-5 sm:flex-row sm:items-center sm:justify-between">
          <div><h2 className="font-bold text-[#0F2A43]">Enquiry pipeline</h2><p className="mt-1 text-xs text-slate-500">{visibleEnquiries.length} leads shown</p></div>
          <div className="space-y-3">
            <div role="group" aria-label="Filter enquiries by status" className="flex flex-wrap gap-2">
              {statuses.map((status) => <button key={status} type="button" aria-pressed={statusFilter === status} onClick={() => setStatusFilter(status)} className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${statusFilter === status ? "bg-[#0F2A43] text-white" : "bg-slate-100 text-slate-500 hover:bg-slate-200"}`}>{status}</button>)}
            </div>
            <div role="group" aria-label="Filter enquiries by source" className="flex flex-wrap gap-2">
              <span className="self-center pr-1 text-xs font-semibold text-slate-500">Source</span>
              {sources.map((source) => <button key={source} type="button" aria-pressed={sourceFilter === source} onClick={() => setSourceFilter(source)} className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${sourceFilter === source ? "bg-[#D4A72C] text-[#0F2A43]" : "bg-slate-100 text-slate-500 hover:bg-slate-200"}`}>{source}</button>)}
            </div>
          </div>
        </div>

        {visibleEnquiries.length === 0 ? (
          <div className="p-12 text-center"><MessageSquare className="mx-auto mb-3 text-slate-300" size={28} /><p className="font-semibold text-[#0F2A43]">No enquiries match these filters</p><p className="mt-1 text-sm text-slate-500">{readOnly ? "Try another source or status filter." : "Try another source or status filter, or add a buyer enquiry."}</p></div>
        ) : (
          <div className="divide-y divide-slate-100">
            {visibleEnquiries.map((enquiry) => {
              const matches = properties.filter((property) => getMatches(property, [enquiry]).length > 0);
              const isExpanded = expandedId === enquiry.id;
              return (
                <article key={enquiry.id} className="p-5 transition hover:bg-slate-50/50 sm:p-6">
                  <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <h3 className="font-bold text-[#0F2A43]">{enquiry.name}</h3>
                        <span className={`rounded-full px-2.5 py-1 text-[11px] font-bold ${statusStyles[enquiry.status] || statusStyles.New}`}>{enquiry.status}</span>
                        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-600">{enquiry.source || "Website"}</span>
                        {matches.length > 0 && <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-bold text-emerald-700"><Sparkles size={12} /> {matches.length} matches</span>}
                      </div>
                      <p className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-500">
                        <span className="inline-flex items-center gap-1.5"><Mail size={14} />{enquiry.email}</span>
                        <span className="inline-flex items-center gap-1.5"><MessageSquare size={14} />{enquiry.phone}</span>
                      </p>
                      <p className="mt-3 text-sm leading-6 text-slate-600">{enquiry.message}</p>
                      <div className="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-xs text-slate-500">
                        <span className="inline-flex items-center gap-1"><MapPin size={13} />{[enquiry.area, enquiry.city, enquiry.country].filter(Boolean).join(", ") || "Any location"}</span>
                        <span>{enquiry.listingType || "Any listing"}{enquiry.propertyType ? ` · ${enquiry.propertyType}` : ""}</span>
                        <span>{enquiry.budgetMax ? `${formatMoney(enquiry.budgetMin, enquiry.currency)} – ${formatMoney(enquiry.budgetMax, enquiry.currency)}` : "Flexible budget"}</span>
                        <span>Added {formatDate(enquiry.createdAt)}</span>
                      </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                      {!readOnly && <select aria-label={`Update ${enquiry.name} status`} value={enquiry.status} onChange={(event) => onUpdateStatus(enquiry.id, event.target.value)} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 outline-none focus:border-[#D4A72C]">
                        {statuses.filter((status) => status !== "All").map((status) => <option key={status}>{status}</option>)}
                      </select>}
                      <button type="button" onClick={() => setExpandedId(isExpanded ? null : enquiry.id)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-[#0F2A43] hover:bg-slate-100">{isExpanded ? "Hide matches" : "View matches"} <ArrowRight size={14} className={isExpanded ? "rotate-90" : ""} /></button>
                      {!readOnly && matches.length > 0 && <button type="button" onClick={() => onNotifyMatch(enquiry, matches)} className="inline-flex items-center gap-1 rounded-lg bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-800"><BellRing size={14} /> Alert parties</button>}
                    </div>
                  </div>
                  {isExpanded && <div className="mt-5 rounded-xl bg-slate-50 p-4">
                    <p className="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">Best matches · notifications are simulated</p>
                    {matches.length === 0 ? <p className="text-sm text-slate-500">No listings meet these requirements yet. New listings will be checked automatically.</p> : <div className="grid gap-3 sm:grid-cols-2">{matches.map((property) => <div key={property.id} className="flex items-center justify-between gap-3 rounded-xl border border-slate-100 bg-white p-3"><div className="min-w-0"><p className="truncate text-sm font-bold text-[#0F2A43]">{property.title}</p><p className="mt-1 text-xs text-slate-500">{property.location.area}, {property.location.city} · {formatMoney(property.price, property.currency)}</p></div><span className="shrink-0 rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700">Available</span></div>)}</div>}
                  </div>}
                </article>
              );
            })}
          </div>
        )}
      </section>

      {showEditor && <EnquiryEditor onClose={() => setShowEditor(false)} onSave={(form) => { onAddEnquiry(form); setShowEditor(false); }} />}
    </div>
  );
}

export default Enquiries;
