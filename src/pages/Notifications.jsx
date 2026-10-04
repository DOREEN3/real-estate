import { useState } from "react";
import { BellRing, Mail, MessageCircle, MessageSquareText } from "lucide-react";
import PageHeading from "../components/PageHeading";
import { formatDate } from "../lib/demo";

const channelIcons = { Email: Mail, SMS: MessageSquareText, WhatsApp: MessageCircle };

function Notifications({ notifications }) {
  const [filter, setFilter] = useState("All");
  const filtered = notifications.filter((item) => filter === "All" || item.type === filter);

  return (
    <div>
      <PageHeading eyebrow="Workflow automation" title="Notification activity" description="A transparent log of demo alerts that would be sent through connected messaging providers in a production system." />
      <div className="mb-5 flex flex-wrap gap-2">{["All", "Property", "Match", "Enquiry", "Payment"].map((type) => <button key={type} type="button" onClick={() => setFilter(type)} className={`rounded-full px-4 py-2 text-xs font-semibold ${filter === type ? "bg-[#0F2A43] text-white" : "bg-white text-slate-500 ring-1 ring-slate-200 hover:bg-slate-50"}`}>{type}</button>)}</div>
      <section className="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
        {filtered.length ? <div className="divide-y divide-slate-100">{filtered.map((item) => <article key={item.id} className="flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6"><div className="flex gap-4"><span className="rounded-xl bg-[#f8f0d8] p-3 text-[#0F2A43]"><BellRing size={19} /></span><div><div className="flex flex-wrap items-center gap-2"><h2 className="font-bold text-[#0F2A43]">{item.title}</h2><span className="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-600">{item.type}</span></div><p className="mt-1 text-sm leading-6 text-slate-600">{item.message}</p><p className="mt-2 text-xs text-slate-400">{formatDate(item.createdAt)} · Audience: {item.audience}</p><div className="mt-3 flex flex-wrap gap-2">{(item.channels || []).map((channel) => { const Icon = channelIcons[channel] || BellRing; return <span key={channel} className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 px-2.5 py-1 text-[11px] font-medium text-slate-500"><Icon size={13} /> {channel}</span>; })}</div></div></div><span className="shrink-0 self-start rounded-full bg-amber-50 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wide text-amber-800">Simulated</span></article>)}</div> : <div className="p-12 text-center"><BellRing className="mx-auto mb-3 text-slate-300" size={26} /><p className="font-semibold text-[#0F2A43]">No activity in this category</p></div>}
      </section>
      <p className="mt-4 text-xs leading-5 text-slate-400">Production email, SMS and WhatsApp delivery requires provider integrations, contact consent, sender configuration and server-side credentials. This prototype does not send external messages.</p>
    </div>
  );
}

export default Notifications;
