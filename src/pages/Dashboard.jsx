import { ArrowRight, BellRing, Building2, CircleDollarSign, Handshake, MessageSquareText, Plus, Sparkles, UsersRound } from "lucide-react";
import PageHeading from "../components/PageHeading";
import PropertyCard from "../components/PropertyCard";
import { formatCurrencyTotals, formatDate, formatMoney, getMatches } from "../lib/demo";

function Dashboard({ properties, enquiries, agents, sales, payments, notifications, onNavigate, onAddProperty, user }) {
  const newEnquiries = enquiries.filter((enquiry) => enquiry.status === "New").length;
  const available = properties.filter((property) => property.status === "Available").length;
  const matches = properties.reduce((count, property) => count + getMatches(property, enquiries).length, 0);
  const collected = formatCurrencyTotals(payments);
  const collectionMetric = user.role === "admin"
    ? { label: "Collections", value: collected, note: `${sales.length} sale accounts`, target: "Payments" }
    : user.role === "agent"
      ? { label: "Collections", value: collected, note: `${sales.length} sale accounts`, target: "Sales" }
      : { label: "Owned properties", value: properties.length, note: "Listings linked to your account", target: "Properties" };
  const metrics = [
    { label: "Available listings", value: available, note: `${properties.length} total properties`, icon: Building2, tone: "bg-blue-50 text-blue-700", target: "Properties" },
    { label: "Active enquiries", value: enquiries.filter((enquiry) => enquiry.status !== "Closed").length, note: `${newEnquiries} new leads to follow up`, icon: MessageSquareText, tone: "bg-violet-50 text-violet-700", target: "Enquiries" },
    { label: "Potential matches", value: matches, note: "Lead-to-property matches", icon: Sparkles, tone: "bg-emerald-50 text-emerald-700", target: "Enquiries" },
    { ...collectionMetric, icon: user.role === "owner" ? Building2 : CircleDollarSign, tone: "bg-amber-50 text-amber-800" },
  ];

  return (
    <div>
      <PageHeading eyebrow="Portfolio overview" title={`Welcome back, ${user.name}`} description={`Your ${user.role} workspace at a glance.`} action={<button type="button" onClick={onAddProperty} className="inline-flex items-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60]"><Plus size={18} /> Add property</button>} />
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {metrics.map(({ label, value, note, icon: Icon, tone, target }) => <button type="button" key={label} onClick={() => onNavigate(target)} className="rounded-2xl border border-slate-100 bg-white p-5 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"><div className="flex items-start justify-between"><div><p className="text-sm font-medium text-slate-500">{label}</p><p className="mt-3 text-2xl font-bold text-[#0F2A43]">{value}</p></div><span className={`rounded-xl p-3 ${tone}`}><Icon size={20} /></span></div><p className="mt-3 text-xs text-slate-400">{note}</p></button>)}
      </div>
      <div className="mt-6 grid gap-6 xl:grid-cols-[1.65fr_1fr]">
        <section>
          <div className="mb-4 flex items-end justify-between"><div><h2 className="text-lg font-bold text-[#0F2A43]">Featured properties</h2><p className="mt-1 text-sm text-slate-500">Latest available properties in the portfolio</p></div><button type="button" onClick={() => onNavigate("Properties")} className="inline-flex items-center gap-1 text-sm font-semibold text-[#0F2A43] hover:text-[#B88912]">View all <ArrowRight size={15} /></button></div>
          <div className="grid gap-4 md:grid-cols-2">{properties.filter((property) => property.status === "Available").slice(0, 2).map((property) => <PropertyCard key={property.id} property={property} onView={() => onNavigate("Properties")} />)}</div>
          {properties.length === 0 && <p className="rounded-2xl bg-white p-8 text-center text-sm text-slate-500">No properties saved yet.</p>}
        </section>

        <aside className="space-y-6">
          {user.role === "admin" && <section className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <div className="mb-4 flex items-center justify-between"><div><h2 className="font-bold text-[#0F2A43]">Recent activity</h2><p className="mt-1 text-xs text-slate-500">Latest workflow updates</p></div><button type="button" onClick={() => onNavigate("Notifications")} aria-label="View all notifications" className="rounded-lg p-2 text-slate-400 hover:bg-slate-100"><BellRing size={17} /></button></div>
            <div className="space-y-4">
              {notifications.slice(0, 4).map((item) => <div key={item.id} className="flex gap-3"><span className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-[#D4A72C]" /><div className="min-w-0"><p className="text-sm font-semibold text-[#0F2A43]">{item.title}</p><p className="mt-1 line-clamp-2 text-xs leading-5 text-slate-500">{item.message}</p><p className="mt-1 text-[11px] text-slate-400">{formatDate(item.createdAt)} · Demo activity</p></div></div>)}
              {!notifications.length && <p className="text-sm text-slate-500">No recent activity.</p>}
            </div>
            <button type="button" onClick={() => onNavigate("Notifications")} className="mt-4 w-full rounded-lg border border-slate-200 py-2.5 text-xs font-semibold text-[#0F2A43] hover:bg-slate-50">Open automation activity</button>
          </section>}

          {user.role === "admin" && <section className="rounded-2xl bg-[#0F2A43] p-5 text-white shadow-sm">
            <div className="flex items-start justify-between"><div><p className="text-xs font-bold uppercase tracking-wider text-[#eac95f]">Network</p><h2 className="mt-2 text-lg font-bold">Trusted partners</h2></div><UsersRound className="text-[#eac95f]" size={22} /></div>
            <div className="mt-4 flex justify-between border-t border-white/15 pt-4 text-sm"><span className="text-white/70">Verified agents</span><strong>{agents.filter((agent) => agent.verified).length}</strong></div>
            <button type="button" onClick={() => onNavigate("Agents")} className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-[#eac95f]">Manage agent network <ArrowRight size={15} /></button>
          </section>}
          {sales.length > 0 && <section className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm"><div className="flex items-start justify-between"><div><p className="text-xs font-bold uppercase tracking-wider text-[#B88912]">Next collection</p><h2 className="mt-2 font-bold text-[#0F2A43]">{sales[0].buyerName}</h2><p className="mt-1 text-xs text-slate-500">{sales[0].propertyTitle}</p></div><Handshake className="text-emerald-700" size={20} /></div><p className="mt-4 text-sm text-slate-600">{formatMoney(sales[0].installmentAmount)} · {sales[0].nextDueDate ? formatDate(sales[0].nextDueDate) : "No date scheduled"}</p><button type="button" onClick={() => onNavigate("Sales")} className="mt-3 text-xs font-semibold text-[#0F2A43]">View sale account →</button></section>}
        </aside>
      </div>
      <div className="mt-6 rounded-2xl border border-amber-100 bg-amber-50/70 p-4 text-xs leading-5 text-amber-900"><strong>Prototype notice:</strong> Changes save in this browser only. Email, SMS and WhatsApp automations are simulated activity; no messages are sent.</div>
    </div>
  );
}

export default Dashboard;
