import { useState, useMemo } from "react";
import {
  AlertTriangle,
  ArrowRight,
  BadgeAlert,
  BadgeCheck,
  Building2,
  Calendar,
  Check,
  ChevronDown,
  ChevronUp,
  Copy,
  ExternalLink,
  Eye,
  FileCheck2,
  FileText,
  Filter,
  HelpCircle,
  Mail,
  MapPin,
  MessageSquare,
  Phone,
  Plus,
  Search,
  Send,
  ShieldAlert,
  ShieldCheck,
  Trash2,
  UserCheck,
  UserRound,
  UserRoundCheck,
  Users,
  X,
} from "lucide-react";
import Modal from "../components/Modal";
import PageHeading from "../components/PageHeading";
import { formatDate, formatMoney, inputClass } from "../lib/utils";

// ----------------------------------------------------------------------
// 1. Send Self-Registration Invite Modal
// ----------------------------------------------------------------------
function RegistrationInviteModal({ kind, onClose, onSendInvite }) {
  const [form, setForm] = useState({ name: "", email: "" });
  const [copied, setCopied] = useState(false);
  const isAgent = kind === "Agents";
  const label = isAgent ? "real estate agent" : "property owner";
  const role = isAgent ? "agent" : "owner";

  const inviteUrl = `${window.location.origin}${window.location.pathname}?mode=register&role=${role}${
    form.email ? `&email=${encodeURIComponent(form.email.trim())}` : ""
  }`;

  function copyLink() {
    if (navigator.clipboard) {
      navigator.clipboard.writeText(inviteUrl);
      setCopied(true);
      setTimeout(() => setCopied(false), 2500);
    }
  }

  function submit(event) {
    event.preventDefault();
    onSendInvite({
      email: form.email,
      role,
      full_name: form.name,
    });
    onClose();
  }

  return (
    <Modal
      title={`Send ${label} registration form`}
      description={`Email a direct self-registration link to the ${label}. Once they complete the form, you can verify and approve their account here.`}
      onClose={onClose}
      size="max-w-lg"
    >
      <form onSubmit={submit} className="space-y-4 p-5 sm:p-7">
        <label className="block text-sm font-semibold text-slate-700">
          Recipient Email Address
          <input
            type="email"
            name="email"
            value={form.email}
            onChange={(event) => setForm({ ...form, email: event.target.value })}
            required
            placeholder={isAgent ? "agent@example.com" : "owner@example.com"}
            className={`${inputClass} mt-2`}
          />
        </label>
        <label className="block text-sm font-semibold text-slate-700">
          Full Name / Business Name <span className="font-normal text-slate-400">(optional)</span>
          <input
            name="name"
            value={form.name}
            onChange={(event) => setForm({ ...form, name: event.target.value })}
            placeholder="e.g. Jane Doe"
            className={`${inputClass} mt-2`}
          />
        </label>

        <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs font-semibold text-slate-700">Direct Self-Registration Link</span>
            <button
              type="button"
              onClick={copyLink}
              className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-[#0F2A43] hover:bg-slate-100"
            >
              <Copy size={12} /> {copied ? "Copied!" : "Copy link"}
            </button>
          </div>
          <p className="mt-1.5 break-all font-mono text-[11px] text-slate-500">{inviteUrl}</p>
        </div>

        <p className="text-xs leading-5 text-slate-500">
          The recipient will receive an email with this private link to complete their registration. Their account will appear under <strong>Awaiting verification</strong> once submitted. In development mode, you can also copy the link above and open it in an incognito window to test registration immediately.
        </p>
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
            className="inline-flex items-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60]"
          >
            <Send size={16} /> Send registration form
          </button>
        </div>
      </form>
    </Modal>
  );
}

// ----------------------------------------------------------------------
// 2. Suspend / Reactivate Confirmation Modal
// ----------------------------------------------------------------------
function AccountStatusConfirmModal({ person, action, onClose, onConfirm }) {
  const isSuspend = action === "suspend";

  return (
    <Modal
      title={isSuspend ? "Suspend account" : "Reactivate account"}
      description={
        isSuspend
          ? `Temporarily block access for ${person.name || person.full_name}.`
          : `Restore access for ${person.name || person.full_name}.`
      }
      onClose={onClose}
      size="max-w-md"
    >
      <div className="space-y-4 p-5 sm:p-6">
        <div
          className={`flex items-start gap-3 rounded-2xl border p-4 text-xs leading-5 ${
            isSuspend
              ? "border-amber-200 bg-amber-50 text-amber-900"
              : "border-emerald-200 bg-emerald-50 text-emerald-900"
          }`}
        >
          {isSuspend ? (
            <AlertTriangle className="mt-0.5 shrink-0 text-amber-700" size={18} />
          ) : (
            <Check className="mt-0.5 shrink-0 text-emerald-700" size={18} />
          )}
          <div className="space-y-1">
            <p className="font-bold">
              {isSuspend ? "What happens when you suspend this account?" : "What happens upon reactivation?"}
            </p>
            {isSuspend ? (
              <ul className="list-disc pl-4 space-y-1">
                <li>Immediate login block: {person.name} cannot sign in.</li>
                <li>All active listings and records remain intact for legitimate recordkeeping.</li>
                <li>An administrator can reactivate this account at any time.</li>
              </ul>
            ) : (
              <ul className="list-disc pl-4 space-y-1">
                <li>Login access is restored immediately.</li>
                <li>The user can resume managing properties and receiving leads.</li>
              </ul>
            )}
          </div>
        </div>

        <div className="rounded-xl border border-slate-100 bg-slate-50 p-3 text-xs text-slate-600">
          <p className="font-semibold text-[#0F2A43]">{person.name || person.full_name}</p>
          <p className="text-slate-500">{person.email}</p>
          <p className="mt-1 capitalize text-slate-400">Role: {person.role || "User"}</p>
        </div>

        <div className="flex justify-end gap-3 border-t border-slate-100 pt-4">
          <button
            type="button"
            onClick={onClose}
            className="rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={() => {
              onConfirm(person.id, action);
              onClose();
            }}
            className={`inline-flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-xs font-semibold text-white transition ${
              isSuspend ? "bg-red-600 hover:bg-red-700" : "bg-emerald-700 hover:bg-emerald-800"
            }`}
          >
            {isSuspend ? <X size={14} /> : <Check size={14} />}
            {isSuspend ? "Confirm suspension" : "Confirm reactivation"}
          </button>
        </div>
      </div>
    </Modal>
  );
}

// ----------------------------------------------------------------------
// 3. User Full Profile Modal
// ----------------------------------------------------------------------
function UserProfileModal({ user, onClose, onOpenProperty, onUpdateStatus }) {
  const [activeTab, setActiveTab] = useState("overview");

  const properties = user.properties || [];
  const ownedProps = user.owned_properties || [];
  const managedProps = user.managed_properties || [];
  const counts = user.property_counts || {
    total: properties.length,
    owned: ownedProps.length,
    managed: managedProps.length,
    active_listings: 0,
    pending_listings: 0,
    verified_owned: 0,
  };

  // Collect all enquiries from all associated properties
  const allEnquiries = useMemo(() => {
    const list = [];
    properties.forEach((p) => {
      if (Array.isArray(p.enquiries)) {
        p.enquiries.forEach((e) => {
          list.push({ ...e, propertyTitle: p.title, propertyId: p.id });
        });
      }
    });
    return list;
  }, [properties]);

  return (
    <Modal
      title={user.name || user.full_name}
      description={`${user.role === "agent" ? "Real estate agent" : "Property owner"} · ${user.email}`}
      onClose={onClose}
      size="max-w-3xl"
    >
      <div className="p-5 sm:p-7 space-y-6">
        {/* Profile Card Header */}
        <div className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-100 bg-slate-50/80 p-4">
          <div className="flex items-center gap-3.5">
            <span className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-[#0F2A43] text-xl font-bold text-white shadow-sm">
              {(user.name || user.full_name || "U")
                .split(/\s+/)
                .map((p) => p[0])
                .slice(0, 2)
                .join("")
                .toUpperCase()}
            </span>
            <div>
              <div className="flex items-center gap-2">
                <h3 className="text-base font-bold text-[#0F2A43]">{user.name || user.full_name}</h3>
                <span
                  className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-bold ${
                    user.status === "approved"
                      ? "bg-emerald-50 text-emerald-700 border border-emerald-200"
                      : user.status === "pending"
                        ? "bg-amber-50 text-amber-800 border border-amber-200"
                        : user.status === "suspended"
                          ? "bg-slate-100 text-slate-600 border border-slate-200"
                          : "bg-red-50 text-red-700 border border-red-200"
                  }`}
                >
                  {user.status === "approved" && <BadgeCheck size={12} />}
                  {user.status === "pending" && <ShieldAlert size={12} />}
                  {user.status === "approved" ? "Verified" : user.status}
                </span>
              </div>
              <p className="mt-0.5 text-xs text-slate-500">
                {user.email} · Registered {formatDate(user.created_at)}
              </p>
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {user.status === "approved" && (
              <button
                type="button"
                onClick={() => onUpdateStatus(user, "suspend")}
                className="rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-red-50 hover:text-red-600 transition"
              >
                Suspend account
              </button>
            )}
            {user.status === "suspended" && (
              <button
                type="button"
                onClick={() => onUpdateStatus(user, "reactivate")}
                className="rounded-xl bg-emerald-700 px-3.5 py-2 text-xs font-semibold text-white hover:bg-emerald-800 transition"
              >
                Reactivate account
              </button>
            )}
            {user.status === "pending" && (
              <>
                <button
                  type="button"
                  onClick={() => onUpdateStatus(user, "approve")}
                  className="rounded-xl bg-emerald-700 px-3.5 py-2 text-xs font-semibold text-white hover:bg-emerald-800 transition"
                >
                  Verify &amp; approve
                </button>
                <button
                  type="button"
                  onClick={() => onUpdateStatus(user, "reject")}
                  className="rounded-xl border border-red-200 bg-white px-3.5 py-2 text-xs font-semibold text-red-600 hover:bg-red-50 transition"
                >
                  Reject
                </button>
              </>
            )}
          </div>
        </div>

        {/* Breakdown counters */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div className="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
            <p className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
              {user.role === "agent" ? "Managed properties" : "Owned properties"}
            </p>
            <p className="mt-1 text-2xl font-bold text-[#0F2A43]">
              {user.role === "agent" ? counts.managed : counts.owned}
            </p>
            <p className="mt-0.5 text-[11px] text-slate-400">
              {user.role === "agent" ? "Management assigned" : `${counts.verified_owned} verified legal ownership`}
            </p>
          </div>

          <div className="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
            <p className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Active listings</p>
            <p className="mt-1 text-2xl font-bold text-emerald-700">{counts.active_listings}</p>
            <p className="mt-0.5 text-[11px] text-slate-400">Live on platform</p>
          </div>

          <div className="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
            <p className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Pending listings</p>
            <p className="mt-1 text-2xl font-bold text-amber-700">{counts.pending_listings}</p>
            <p className="mt-0.5 text-[11px] text-slate-400">Awaiting admin review</p>
          </div>

          <div className="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm">
            <p className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Leads &amp; Enquiries</p>
            <p className="mt-1 text-2xl font-bold text-blue-700">{allEnquiries.length}</p>
            <p className="mt-0.5 text-[11px] text-slate-400">Across all listings</p>
          </div>
        </div>

        {/* Modal Navigation Tabs */}
        <div className="flex border-b border-slate-200">
          <button
            type="button"
            onClick={() => setActiveTab("overview")}
            className={`px-4 py-2.5 text-xs font-bold border-b-2 transition ${
              activeTab === "overview"
                ? "border-[#0F2A43] text-[#0F2A43]"
                : "border-transparent text-slate-500 hover:text-slate-800"
            }`}
          >
            Associated Properties ({properties.length})
          </button>
          <button
            type="button"
            onClick={() => setActiveTab("enquiries")}
            className={`px-4 py-2.5 text-xs font-bold border-b-2 transition ${
              activeTab === "enquiries"
                ? "border-[#0F2A43] text-[#0F2A43]"
                : "border-transparent text-slate-500 hover:text-slate-800"
            }`}
          >
            Client Enquiries ({allEnquiries.length})
          </button>
          <button
            type="button"
            onClick={() => setActiveTab("compliance")}
            className={`px-4 py-2.5 text-xs font-bold border-b-2 transition ${
              activeTab === "compliance"
                ? "border-[#0F2A43] text-[#0F2A43]"
                : "border-transparent text-slate-500 hover:text-slate-800"
            }`}
          >
            Verification &amp; Documents
          </button>
        </div>

        {/* Tab 1: Properties */}
        {activeTab === "overview" && (
          <div className="space-y-4">
            <div className="flex items-center justify-between text-xs text-slate-500">
              <span className="font-semibold text-slate-700">
                {user.role === "agent"
                  ? "Properties managed by this agent (distinct from legal owner)"
                  : "Properties legally owned and/or managed"}
              </span>
              <span>{properties.length} listing(s)</span>
            </div>

            {properties.length === 0 ? (
              <div className="rounded-2xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-400">
                No properties associated with this account yet.
              </div>
            ) : (
              <div className="grid gap-3 sm:grid-cols-2">
                {properties.map((property) => (
                  <div
                    key={property.id}
                    className="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-3.5 shadow-sm hover:border-[#D4A72C] transition"
                  >
                    <div>
                      <div className="flex items-start justify-between gap-2">
                        <span
                          className={`rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ${
                            property.transaction_type === "rent"
                              ? "bg-blue-50 text-blue-700"
                              : property.transaction_type === "bnb"
                                ? "bg-emerald-50 text-emerald-700"
                                : "bg-purple-50 text-purple-700"
                          }`}
                        >
                          {property.transaction_type === "rent"
                            ? "For rent"
                            : property.transaction_type === "bnb"
                              ? "BnB"
                              : "For sale"}
                        </span>
                        <span
                          className={`rounded-md px-2 py-0.5 text-[10px] font-semibold ${
                            property.property_status === "approved"
                              ? "bg-emerald-50 text-emerald-700"
                              : "bg-amber-50 text-amber-800"
                          }`}
                        >
                          {property.property_status}
                        </span>
                      </div>

                      <h4 className="mt-2 text-sm font-bold text-[#0F2A43] line-clamp-1">{property.title}</h4>
                      <p className="mt-0.5 text-xs text-slate-500 flex items-center gap-1">
                        <MapPin size={12} /> {property.city || "Nairobi"}
                      </p>

                      <div className="mt-2.5 flex flex-wrap gap-1.5 text-[11px]">
                        {Number(property.owner_id) === Number(user.id) ? (
                          <span className="inline-flex items-center gap-1 rounded bg-amber-50 px-2 py-0.5 font-semibold text-amber-800 border border-amber-200">
                            Legal owner
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 rounded bg-slate-100 px-2 py-0.5 font-medium text-slate-600">
                            Owner: {property.owner_name || `#${property.owner_id}`}
                          </span>
                        )}

                        {property.managed_by && Number(property.managed_by) === Number(user.id) ? (
                          <span className="inline-flex items-center gap-1 rounded bg-blue-50 px-2 py-0.5 font-semibold text-blue-800 border border-blue-200">
                            Managing agent
                          </span>
                        ) : property.managed_by ? (
                          <span className="inline-flex items-center gap-1 rounded bg-slate-100 px-2 py-0.5 font-medium text-slate-600">
                            Manager: {property.manager_name || `#${property.managed_by}`}
                          </span>
                        ) : null}

                        {property.verification_status === "verified" && (
                          <span className="inline-flex items-center gap-1 rounded bg-emerald-50 px-2 py-0.5 font-bold text-emerald-700">
                            <BadgeCheck size={11} /> Ownership verified
                          </span>
                        )}
                      </div>
                    </div>

                    <button
                      type="button"
                      onClick={() => onOpenProperty(property)}
                      className="mt-3 inline-flex items-center justify-between rounded-lg bg-slate-50 px-3 py-1.5 text-xs font-semibold text-[#0F2A43] hover:bg-slate-100 transition"
                    >
                      <span>View property details</span>
                      <ExternalLink size={13} />
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Tab 2: Enquiries */}
        {activeTab === "enquiries" && (
          <div className="space-y-3">
            {allEnquiries.length === 0 ? (
              <div className="rounded-2xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-400">
                No client enquiries recorded for this user&apos;s properties yet.
              </div>
            ) : (
              allEnquiries.map((enq) => (
                <div key={enq.id} className="rounded-xl border border-slate-100 bg-white p-3.5 shadow-sm space-y-1.5">
                  <div className="flex items-center justify-between text-xs">
                    <span className="font-bold text-[#0F2A43]">{enq.name || "Client"}</span>
                    <span className="text-slate-400">{formatDate(enq.created_at)}</span>
                  </div>
                  <p className="text-xs text-slate-500">
                    Property: <strong>{enq.propertyTitle}</strong>
                  </p>
                  <p className="text-xs text-slate-600 bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                    &ldquo;{enq.message}&rdquo;
                  </p>
                  <div className="flex items-center gap-3 text-[11px] text-slate-400 pt-1">
                    {enq.email && <span>Email: {enq.email}</span>}
                    {enq.phone && <span>Phone: {enq.phone}</span>}
                  </div>
                </div>
              ))
            )}
          </div>
        )}

        {/* Tab 3: Verification & Compliance */}
        {activeTab === "compliance" && (
          <div className="space-y-4">
            <div className="rounded-xl border border-blue-100 bg-blue-50/70 p-4 text-xs leading-5 text-blue-900">
              <h4 className="font-bold">Kenyan Real Estate &amp; Title Deed Verification</h4>
              <p className="mt-1">
                GoldERP Real Estate records title deeds, allotment letters, and ownership deeds for each registered property to preserve clear legal provenance.
              </p>
            </div>

            <div className="space-y-3">
              {properties.map((prop) => (
                <div key={prop.id} className="rounded-xl border border-slate-200 bg-white p-4 space-y-2">
                  <div className="flex items-start justify-between">
                    <div>
                      <h4 className="font-bold text-sm text-[#0F2A43]">{prop.title}</h4>
                      <p className="text-xs text-slate-500">{prop.city} · Property #{prop.id}</p>
                    </div>
                    <span
                      className={`rounded-full px-2.5 py-0.5 text-xs font-bold ${
                        prop.verification_status === "verified"
                          ? "bg-emerald-50 text-emerald-700"
                          : prop.verification_status === "documents_submitted"
                            ? "bg-blue-50 text-blue-700"
                            : "bg-amber-50 text-amber-800"
                      }`}
                    >
                      {prop.verification_status || "Unverified"}
                    </span>
                  </div>

                  <div className="text-xs text-slate-600 pt-1 flex items-center justify-between border-t border-slate-100">
                    <span>
                      Ownership Document:{" "}
                      {prop.document_available ? (
                        <strong className="text-emerald-700">Document Uploaded &amp; Archived</strong>
                      ) : (
                        <span className="text-slate-400">No document attached</span>
                      )}
                    </span>
                    <button
                      type="button"
                      onClick={() => onOpenProperty(prop)}
                      className="font-semibold text-[#0F2A43] hover:underline"
                    >
                      View details →
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        <div className="flex justify-end border-t border-slate-100 pt-4">
          <button
            type="button"
            onClick={onClose}
            className="rounded-xl border border-slate-200 px-5 py-2.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
          >
            Close profile
          </button>
        </div>
      </div>
    </Modal>
  );
}

// ----------------------------------------------------------------------
// 4. Property Details Quick-View Modal
// ----------------------------------------------------------------------
function PropertyDetailsModal({ property, onClose }) {
  if (!property) return null;

  return (
    <Modal
      title={property.title}
      description={`${property.city || "Nairobi"} · ${
        property.transaction_type === "rent"
          ? "For rent"
          : property.transaction_type === "bnb"
            ? "BnB"
            : "For sale"
      }`}
      onClose={onClose}
      size="max-w-2xl"
    >
      <div className="p-5 sm:p-7 space-y-5">
        {property.primary_image_url && (
          <div className="overflow-hidden rounded-2xl border border-slate-200 bg-slate-100">
            <img
              src={property.primary_image_url}
              alt={property.title}
              className="h-60 w-full object-cover"
            />
          </div>
        )}

        <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
          <div className="rounded-xl border border-slate-100 bg-slate-50 p-3">
            <p className="text-[11px] font-semibold uppercase text-slate-400">Price / Rent</p>
            <p className="mt-1 text-lg font-bold text-[#0F2A43]">
              {formatMoney(property.price, property.currency || "KES")}
            </p>
          </div>
          <div className="rounded-xl border border-slate-100 bg-slate-50 p-3">
            <p className="text-[11px] font-semibold uppercase text-slate-400">Legal Owner</p>
            <p className="mt-1 text-sm font-bold text-[#0F2A43]">
              {property.owner_name || `User #${property.owner_id}`}
            </p>
          </div>
          <div className="rounded-xl border border-slate-100 bg-slate-50 p-3">
            <p className="text-[11px] font-semibold uppercase text-slate-400">Managing Agent</p>
            <p className="mt-1 text-sm font-bold text-[#0F2A43]">
              {property.manager_name || (property.managed_by ? `Agent #${property.managed_by}` : "Direct / None")}
            </p>
          </div>
        </div>

        <div className="rounded-xl border border-slate-100 bg-slate-50/80 p-4 space-y-2 text-xs leading-5">
          <div className="flex justify-between border-b border-slate-200 pb-2">
            <span className="text-slate-500">Ownership Verification</span>
            <span className="font-bold capitalize text-emerald-700">{property.verification_status || "Unverified"}</span>
          </div>
          <div className="flex justify-between border-b border-slate-200 pb-2">
            <span className="text-slate-500">Property Status</span>
            <span className="font-bold capitalize text-slate-800">{property.property_status}</span>
          </div>
          <div className="flex justify-between border-b border-slate-200 pb-2">
            <span className="text-slate-500">Availability</span>
            <span className="font-bold capitalize text-slate-800">{property.availability_status}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-slate-500">City / Location</span>
            <span className="font-bold text-slate-800">{property.city}</span>
          </div>
        </div>

        <div className="flex justify-end border-t border-slate-100 pt-4">
          <button
            type="button"
            onClick={onClose}
            className="rounded-xl border border-slate-200 px-5 py-2.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
          >
            Close
          </button>
        </div>
      </div>
    </Modal>
  );
}

// ----------------------------------------------------------------------
// 5. Admin Deletion Request Review Modal
// ----------------------------------------------------------------------
function DeletionRequestReviewModal({ request, onClose, onApprove, onReject }) {
  const [adminNotes, setAdminNotes] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const obligations = request.obligations || { blocking: [], warnings: [], records: {} };
  const hasBlocking = obligations.blocking && obligations.blocking.length > 0;

  async function handleApprove() {
    setSubmitting(true);
    await onApprove(request.id, adminNotes);
    setSubmitting(false);
    onClose();
  }

  async function handleReject() {
    setSubmitting(true);
    await onReject(request.id, adminNotes);
    setSubmitting(false);
    onClose();
  }

  return (
    <Modal
      title="Review Account Deletion Request"
      description={`Request from ${request.user_full_name_snapshot} (${request.user_email_snapshot})`}
      onClose={onClose}
      size="max-w-2xl"
    >
      <div className="p-5 sm:p-7 space-y-5">
        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 space-y-2 text-xs">
          <div className="flex justify-between border-b border-slate-200 pb-2">
            <span className="text-slate-500">Requested At</span>
            <span className="font-semibold text-[#0F2A43]">{formatDate(request.requested_at)}</span>
          </div>
          <div className="flex justify-between border-b border-slate-200 pb-2">
            <span className="text-slate-500">Role</span>
            <span className="font-semibold uppercase text-[#0F2A43]">{request.role}</span>
          </div>
          {request.reason && (
            <div className="pt-1">
              <span className="text-slate-500 block mb-1">Reason Provided by Requester:</span>
              <p className="bg-white p-2.5 rounded-lg border border-slate-200 text-slate-700 italic">
                &ldquo;{request.reason}&rdquo;
              </p>
            </div>
          )}
        </div>

        {/* Obligations Assessment */}
        <div className="space-y-3">
          <h4 className="text-xs font-bold uppercase tracking-wider text-slate-500">
            GoldERP Obligation &amp; Record Assessment
          </h4>

          {hasBlocking ? (
            <div className="rounded-2xl border border-red-200 bg-red-50 p-4 space-y-2 text-xs text-red-900">
              <div className="flex items-center gap-2 font-bold text-red-700">
                <ShieldAlert size={16} />
                <span>Blocking Obligations Found ({obligations.blocking.length})</span>
              </div>
              <p className="text-red-800">
                This account cannot be permanently deleted until the following obligations are resolved or reassigned:
              </p>
              <ul className="list-disc pl-5 space-y-1">
                {obligations.blocking.map((item, idx) => (
                  <li key={idx} className="font-medium">
                    {item}
                  </li>
                ))}
              </ul>
            </div>
          ) : (
            <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3.5 text-xs text-emerald-800 flex items-center gap-2">
              <Check size={16} className="text-emerald-700 shrink-0" />
              <span>No blocking commitments detected. Safe for deactivation and personal data anonymization.</span>
            </div>
          )}

          {obligations.warnings && obligations.warnings.length > 0 && (
            <div className="rounded-xl border border-amber-200 bg-amber-50 p-3.5 space-y-1.5 text-xs text-amber-900">
              <div className="flex items-center gap-2 font-bold text-amber-800">
                <AlertTriangle size={15} />
                <span>Record Retention Disclosures:</span>
              </div>
              <ul className="list-disc pl-5 space-y-1 text-amber-800/90">
                {obligations.warnings.map((item, idx) => (
                  <li key={idx}>{item}</li>
                ))}
              </ul>
            </div>
          )}
        </div>

        <label className="block text-xs font-semibold text-slate-700">
          Administrator Review Notes <span className="font-normal text-slate-400">(optional)</span>
          <textarea
            value={adminNotes}
            onChange={(e) => setAdminNotes(e.target.value)}
            rows={2}
            placeholder="Document administrative verification or justification..."
            className={`${inputClass} mt-1`}
          />
        </label>

        <div className="flex flex-col-reverse sm:flex-row justify-between gap-3 border-t border-slate-100 pt-5">
          <button
            type="button"
            onClick={onClose}
            className="rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
          >
            Cancel
          </button>

          <div className="flex gap-2">
            <button
              type="button"
              disabled={submitting}
              onClick={handleReject}
              className="rounded-xl border border-slate-300 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50"
            >
              Reject &amp; restore account
            </button>
            <button
              type="button"
              disabled={submitting || hasBlocking}
              onClick={handleApprove}
              className="inline-flex items-center gap-1.5 rounded-xl bg-red-600 px-4 py-2.5 text-xs font-semibold text-white hover:bg-red-700 disabled:opacity-50"
            >
              <Trash2 size={14} />
              Approve &amp; anonymize data
            </button>
          </div>
        </div>
      </div>
    </Modal>
  );
}

// ----------------------------------------------------------------------
// MAIN COMPONENT: Upgraded Agents & Owners Management Section
// ----------------------------------------------------------------------
function People({
  kind = "Agents",
  people = [],
  allUsers = [],
  admins = [],
  deletionRequests = [],
  onUpdateVerification,
  onInvitePerson,
  onListProperty,
  onReviewDeletionRequest,
}) {
  const [showInviteModal, setShowInviteModal] = useState(false);
  const [selectedProfileUser, setSelectedProfileUser] = useState(null);
  const [selectedPropertyDetails, setSelectedPropertyDetails] = useState(null);
  const [confirmModalData, setConfirmModalData] = useState(null);
  const [selectedDeletionRequest, setSelectedDeletionRequest] = useState(null);

  // Expanded rows state (keyed by user ID)
  const [expandedRowIds, setExpandedRowIds] = useState(new Set());

  // Filters & Search
  const [activeTab, setActiveTab] = useState(kind === "Owners" ? "owners" : "agents");
  const [searchQuery, setSearchQuery] = useState("");
  const [viewMode, setViewMode] = useState("table"); // 'table' or 'grid'

  // Master users list (fallback to people if allUsers is empty)
  const masterList = allUsers.length > 0 ? allUsers : people;

  const totalUsersCount = masterList.length;
  const agentsCount = masterList.filter((u) => u.role === "agent").length;
  const ownersCount = masterList.filter((u) => u.role === "owner").length;
  const verifiedCount = masterList.filter((u) => u.verified || u.account_status === "approved").length;
  const pendingCount = masterList.filter(
    (u) => u.status === "pending" || u.account_status === "pending"
  ).length;
  const deletionRequestsCount = deletionRequests.length;

  function toggleRowExpand(userId) {
    setExpandedRowIds((prev) => {
      const next = new Set(prev);
      if (next.has(userId)) {
        next.delete(userId);
      } else {
        next.add(userId);
      }
      return next;
    });
  }

  // Filtered users
  const filteredUsers = useMemo(() => {
    return masterList.filter((user) => {
      const role = user.role || (kind === "Agents" ? "agent" : "owner");
      const status = user.status || user.account_status || (user.verified ? "approved" : "pending");

      // Tab filter
      if (activeTab === "agents" && role !== "agent") return false;
      if (activeTab === "owners" && role !== "owner") return false;
      if (activeTab === "pending" && status !== "pending") return false;
      if (activeTab === "deletion_requested" && status !== "deletion_requested") return false;

      // Search query
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase();
        const nameMatch = (user.name || user.full_name || "").toLowerCase().includes(q);
        const emailMatch = (user.email || "").toLowerCase().includes(q);
        const cityMatch = (user.city || "").toLowerCase().includes(q);
        const propMatch = (user.properties || []).some((p) => (p.title || "").toLowerCase().includes(q));
        if (!nameMatch && !emailMatch && !cityMatch && !propMatch) {
          return false;
        }
      }

      return true;
    });
  }, [masterList, activeTab, searchQuery, kind]);

  function handleActionClick(user, action) {
    if (action === "suspend" || action === "reactivate") {
      setConfirmModalData({ person: user, action });
    } else {
      onUpdateVerification(user.id, action);
    }
  }

  return (
    <div className="space-y-6">
      <PageHeading
        eyebrow="Directory & Portfolio Governance"
        title="Registered agents & owners"
        description="Understand who owns properties, who manages properties, and which listings are associated with each verified partner."
        action={
          <div className="flex flex-wrap items-center gap-2.5">
            <button
              type="button"
              onClick={() => setShowInviteModal(true)}
              className="inline-flex items-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60] transition shadow-sm"
            >
              <Send size={16} /> Send registration form
            </button>
          </div>
        }
      />

      {/* Summary KPI Cards as recommended */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div className="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
          <p className="text-xs font-semibold uppercase text-slate-400 tracking-wider">Total users</p>
          <p className="mt-1.5 text-3xl font-extrabold text-[#0F2A43]">{totalUsersCount}</p>
          <p className="mt-1 text-[11px] text-slate-400">All registered network</p>
        </div>

        <div className="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
          <p className="text-xs font-semibold uppercase text-slate-400 tracking-wider">Agents</p>
          <p className="mt-1.5 text-3xl font-extrabold text-blue-700">{agentsCount}</p>
          <p className="mt-1 text-[11px] text-slate-400">Managing &amp; listing agents</p>
        </div>

        <div className="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
          <p className="text-xs font-semibold uppercase text-slate-400 tracking-wider">Owners</p>
          <p className="mt-1.5 text-3xl font-extrabold text-amber-700">{ownersCount}</p>
          <p className="mt-1 text-[11px] text-slate-400">Verified legal property owners</p>
        </div>

        <div className="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
          <p className="text-xs font-semibold uppercase text-slate-400 tracking-wider">Verified</p>
          <p className="mt-1.5 text-3xl font-extrabold text-emerald-700">{verifiedCount}</p>
          <p className="mt-1 text-[11px] text-slate-400">Active approved accounts</p>
        </div>

        <div className="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
          <p className="text-xs font-semibold uppercase text-slate-400 tracking-wider">Awaiting verification</p>
          <p className="mt-1.5 text-3xl font-extrabold text-amber-600">{pendingCount}</p>
          <p className="mt-1 text-[11px] text-slate-400">Requires admin approval</p>
        </div>
      </div>

      {/* Deletion requests alert banner if any are pending */}
      {deletionRequestsCount > 0 && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-xs text-red-900 shadow-sm">
          <div className="flex items-center gap-2.5">
            <Trash2 className="text-red-700 shrink-0" size={18} />
            <div>
              <p className="font-bold text-red-900">
                {deletionRequestsCount} Account Deletion Request(s) Awaiting Review
              </p>
              <p className="text-red-700">
                Users have submitted deletion requests. Review obligations before deactivating and anonymizing personal data.
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={() => setActiveTab("deletion_requested")}
            className="rounded-xl bg-red-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-red-700 transition"
          >
            Review requests →
          </button>
        </div>
      )}

      {/* Tabs and Search Bar */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-2xl border border-slate-200/80 bg-white p-3 shadow-sm">
        <div className="flex flex-wrap items-center gap-1.5">
          <button
            type="button"
            onClick={() => setActiveTab("all")}
            className={`rounded-xl px-3.5 py-2 text-xs font-bold transition ${
              activeTab === "all"
                ? "bg-[#0F2A43] text-white"
                : "bg-slate-50 text-slate-600 hover:bg-slate-100"
            }`}
          >
            All registered ({totalUsersCount})
          </button>
          <button
            type="button"
            onClick={() => setActiveTab("agents")}
            className={`rounded-xl px-3.5 py-2 text-xs font-bold transition ${
              activeTab === "agents"
                ? "bg-[#0F2A43] text-white"
                : "bg-slate-50 text-slate-600 hover:bg-slate-100"
            }`}
          >
            Agents ({agentsCount})
          </button>
          <button
            type="button"
            onClick={() => setActiveTab("owners")}
            className={`rounded-xl px-3.5 py-2 text-xs font-bold transition ${
              activeTab === "owners"
                ? "bg-[#0F2A43] text-white"
                : "bg-slate-50 text-slate-600 hover:bg-slate-100"
            }`}
          >
            Owners ({ownersCount})
          </button>
          <button
            type="button"
            onClick={() => setActiveTab("pending")}
            className={`rounded-xl px-3.5 py-2 text-xs font-bold transition ${
              activeTab === "pending"
                ? "bg-amber-600 text-white"
                : "bg-slate-50 text-slate-600 hover:bg-slate-100"
            }`}
          >
            Awaiting verification ({pendingCount})
          </button>
          {deletionRequestsCount > 0 && (
            <button
              type="button"
              onClick={() => setActiveTab("deletion_requested")}
              className={`rounded-xl px-3.5 py-2 text-xs font-bold transition ${
                activeTab === "deletion_requested"
                  ? "bg-red-600 text-white"
                  : "bg-red-50 text-red-700 hover:bg-red-100"
              }`}
            >
              Deletion requests ({deletionRequestsCount})
            </button>
          )}
        </div>

        <div className="relative min-w-[240px]">
          <Search size={15} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="Search by name, email, city..."
            className="w-full rounded-xl border border-slate-200 bg-slate-50/70 pl-9 pr-3 py-2 text-xs text-slate-800 outline-none focus:border-[#0F2A43] focus:bg-white transition"
          />
        </div>
      </div>

      {/* Main Table View with Expandable Property Details */}
      {filteredUsers.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
          <Mail className="mx-auto mb-3 text-slate-300" size={28} />
          <h3 className="font-bold text-[#0F2A43]">No records found</h3>
          <p className="mt-1 text-sm text-slate-500">
            {searchQuery
              ? "No agents or owners match your search query."
              : "No partners found in this section."}
          </p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50/75 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                  <th className="py-3.5 pl-5 pr-3">User &amp; Contact</th>
                  <th className="px-3 py-3.5">Role &amp; Status</th>
                  <th className="px-3 py-3.5">Registration</th>
                  <th className="px-3 py-3.5">Property Portfolio</th>
                  <th className="px-3 py-3.5">Associated Properties</th>
                  <th className="py-3.5 pl-3 pr-5 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredUsers.map((user) => {
                  const isExpanded = expandedRowIds.has(user.id);
                  const isAgent = user.role === "agent";
                  const status =
                    user.status || user.account_status || (user.verified ? "approved" : "pending");

                  const properties = user.properties || [];
                  const counts = user.property_counts || {
                    total: properties.length,
                    owned: user.owned_properties?.length || 0,
                    managed: user.managed_properties?.length || 0,
                    active_listings: 0,
                    pending_listings: 0,
                  };

                  return (
                    <tr
                      key={user.id}
                      className="group transition hover:bg-slate-50/50"
                    >
                      {/* Column 1: User & Contact */}
                      <td className="py-4 pl-5 pr-3 align-top">
                        <div className="flex items-start gap-3">
                          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#0F2A43] text-sm font-bold text-white shadow-sm">
                            {(user.name || user.full_name || "U")
                              .split(/\s+/)
                              .map((p) => p[0])
                              .slice(0, 2)
                              .join("")
                              .toUpperCase()}
                          </span>
                          <div className="min-w-0">
                            <p className="font-bold text-[#0F2A43] text-sm truncate">
                              {user.name || user.full_name}
                            </p>
                            <p className="text-slate-500 truncate flex items-center gap-1 mt-0.5">
                              <Mail size={12} className="shrink-0 text-slate-400" />
                              {user.email}
                            </p>
                            {user.phone && user.phone !== "—" && (
                              <p className="text-slate-400 truncate flex items-center gap-1 mt-0.5 text-[11px]">
                                <Phone size={11} className="shrink-0" />
                                {user.phone}
                              </p>
                            )}
                          </div>
                        </div>
                      </td>

                      {/* Column 2: Role & Status */}
                      <td className="px-3 py-4 align-top">
                        <div className="space-y-1.5">
                          <span
                            className={`inline-block rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ${
                              isAgent
                                ? "bg-blue-50 text-blue-700 border border-blue-200"
                                : "bg-amber-50 text-amber-800 border border-amber-200"
                            }`}
                          >
                            {isAgent ? "Real estate agent" : "Property owner"}
                          </span>
                          <div>
                            {status === "approved" && (
                              <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">
                                <BadgeCheck size={12} /> Verified
                              </span>
                            )}
                            {status === "pending" && (
                              <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-800">
                                <ShieldAlert size={12} /> Pending verification
                              </span>
                            )}
                            {status === "suspended" && (
                              <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">
                                Suspended
                              </span>
                            )}
                            {status === "deletion_requested" && (
                              <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-bold text-red-700">
                                Deletion requested
                              </span>
                            )}
                            {status === "rejected" && (
                              <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-bold text-red-600">
                                Rejected
                              </span>
                            )}
                          </div>
                        </div>
                      </td>

                      {/* Column 3: Registration date */}
                      <td className="px-3 py-4 align-top text-slate-500 whitespace-nowrap">
                        <p className="font-medium text-slate-700">{formatDate(user.created_at)}</p>
                        <p className="text-[11px] text-slate-400 mt-0.5">Joined</p>
                      </td>

                      {/* Column 4: Property Portfolio Counts */}
                      <td className="px-3 py-4 align-top">
                        <div className="flex flex-wrap items-center gap-1.5">
                          {isAgent ? (
                            <>
                              <span className="rounded-lg bg-blue-50 px-2 py-1 font-semibold text-blue-800">
                                {counts.managed} Managed
                              </span>
                              <span className="rounded-lg bg-emerald-50 px-2 py-1 font-semibold text-emerald-700">
                                {counts.active_listings} Active
                              </span>
                              {counts.pending_listings > 0 && (
                                <span className="rounded-lg bg-amber-50 px-2 py-1 font-semibold text-amber-800">
                                  {counts.pending_listings} Pending
                                </span>
                              )}
                            </>
                          ) : (
                            <>
                              <span className="rounded-lg bg-amber-50 px-2 py-1 font-semibold text-amber-800">
                                {counts.owned} Owned
                              </span>
                              {counts.managed > 0 && (
                                <span className="rounded-lg bg-blue-50 px-2 py-1 font-semibold text-blue-800">
                                  {counts.managed} Managed
                                </span>
                              )}
                              <span className="rounded-lg bg-emerald-50 px-2 py-1 font-semibold text-emerald-700">
                                {counts.active_listings} Active
                              </span>
                            </>
                          )}
                          <span className="rounded-lg bg-slate-100 px-2 py-1 text-slate-600 font-medium">
                            {user.city || "Nairobi"}
                          </span>
                        </div>
                      </td>

                      {/* Column 5: Associated Properties Toggle */}
                      <td className="px-3 py-4 align-top">
                        <button
                          type="button"
                          onClick={() => toggleRowExpand(user.id)}
                          className={`inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold transition ${
                            isExpanded
                              ? "bg-[#0F2A43] text-white"
                              : "bg-slate-100 text-slate-700 hover:bg-slate-200"
                          }`}
                        >
                          <Building2 size={13} />
                          <span>
                            {properties.length} {properties.length === 1 ? "Property" : "Properties"}
                          </span>
                          {isExpanded ? <ChevronUp size={13} /> : <ChevronDown size={13} />}
                        </button>
                      </td>

                      {/* Column 6: Actions */}
                      <td className="py-4 pl-3 pr-5 align-top text-right whitespace-nowrap">
                        <div className="flex items-center justify-end gap-2">
                          <button
                            type="button"
                            onClick={() => setSelectedProfileUser(user)}
                            className="inline-flex items-center gap-1 text-xs font-semibold text-[#0F2A43] hover:underline"
                          >
                            <Eye size={13} /> View profile
                          </button>

                          <span className="text-slate-300">·</span>

                          {status === "approved" && (
                            <button
                              type="button"
                              onClick={() => handleActionClick(user, "suspend")}
                              className="text-xs font-semibold text-slate-500 hover:text-red-600 transition"
                            >
                              Suspend
                            </button>
                          )}

                          {status === "suspended" && (
                            <button
                              type="button"
                              onClick={() => handleActionClick(user, "reactivate")}
                              className="text-xs font-semibold text-emerald-700 hover:underline"
                            >
                              Reactivate
                            </button>
                          )}

                          {status === "pending" && (
                            <div className="inline-flex items-center gap-1">
                              <button
                                type="button"
                                onClick={() => handleActionClick(user, "approve")}
                                className="rounded-lg bg-emerald-700 px-2 py-1 text-[11px] font-semibold text-white hover:bg-emerald-800"
                              >
                                Approve
                              </button>
                              <button
                                type="button"
                                onClick={() => handleActionClick(user, "reject")}
                                className="rounded-lg border border-red-200 px-2 py-1 text-[11px] font-semibold text-red-600 hover:bg-red-50"
                              >
                                Reject
                              </button>
                            </div>
                          )}

                          {status === "deletion_requested" && (
                            <button
                              type="button"
                              onClick={() => {
                                const req = deletionRequests.find(
                                  (r) => Number(r.user_id) === Number(user.id)
                                );
                                if (req) setSelectedDeletionRequest(req);
                              }}
                              className="rounded-lg bg-red-600 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-red-700"
                            >
                              Review deletion
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {/* Expandable associated properties accordion display */}
          {Array.from(expandedRowIds).map((userId) => {
            const user = filteredUsers.find((u) => u.id === userId);
            if (!user) return null;
            const properties = user.properties || [];
            const isAgent = user.role === "agent";

            return (
              <div
                key={`expanded-${userId}`}
                className="border-t border-b border-slate-200 bg-slate-50/70 p-5 space-y-3"
              >
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <Building2 className="text-[#0F2A43]" size={16} />
                    <h4 className="text-xs font-bold uppercase tracking-wider text-[#0F2A43]">
                      Associated properties for {user.name || user.full_name} ({properties.length})
                    </h4>
                  </div>
                  <span className="text-[11px] text-slate-500">
                    {isAgent
                      ? "Showing properties managed by this agent (legal ownership belongs to the registered property owner)."
                      : "Showing properties legally owned and managed."}
                  </span>
                </div>

                {properties.length === 0 ? (
                  <p className="rounded-xl bg-white p-4 text-center text-xs text-slate-400">
                    No properties currently associated with this partner.
                  </p>
                ) : (
                  <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {properties.map((property) => (
                      <div
                        key={property.id}
                        className="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-3.5 shadow-sm hover:border-[#D4A72C] transition"
                      >
                        <div>
                          {/* Image thumbnail + header tags */}
                          <div className="flex items-start gap-3">
                            <div className="h-14 w-14 shrink-0 overflow-hidden rounded-xl bg-slate-100 border border-slate-100">
                              {property.primary_image_url ? (
                                <img
                                  src={property.primary_image_url}
                                  alt={property.title}
                                  className="h-full w-full object-cover"
                                />
                              ) : (
                                <div className="flex h-full w-full items-center justify-center text-slate-300">
                                  <Building2 size={20} />
                                </div>
                              )}
                            </div>

                            <div className="min-w-0 flex-1">
                              <h5 className="font-bold text-[#0F2A43] truncate text-xs">
                                {property.title}
                              </h5>
                              <p className="text-[11px] text-slate-500 truncate flex items-center gap-1 mt-0.5">
                                <MapPin size={11} className="shrink-0 text-slate-400" />
                                {property.city || "Nairobi"} ·{" "}
                                <span className="capitalize font-medium text-slate-700">
                                  {property.transaction_type === "rent"
                                    ? "For rent"
                                    : property.transaction_type === "bnb"
                                      ? "BnB"
                                      : "For sale"}
                                </span>
                              </p>
                              <div className="mt-1 flex items-center gap-1.5">
                                <span
                                  className={`rounded px-1.5 py-0.5 text-[9px] font-bold ${
                                    property.property_status === "approved"
                                      ? "bg-emerald-50 text-emerald-700"
                                      : "bg-amber-50 text-amber-800"
                                  }`}
                                >
                                  {property.property_status}
                                </span>
                                {property.verification_status === "verified" && (
                                  <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-[9px] font-bold text-emerald-700 flex items-center gap-0.5">
                                    <BadgeCheck size={10} /> Verified owner
                                  </span>
                                )}
                              </div>
                            </div>
                          </div>

                          {/* Relationship tags */}
                          <div className="mt-3 flex flex-wrap gap-1 border-t border-slate-100 pt-2 text-[10px]">
                            {Number(property.owner_id) === Number(user.id) ? (
                              <span className="rounded bg-amber-50 px-2 py-0.5 font-bold text-amber-800 border border-amber-200">
                                Legal owner
                              </span>
                            ) : (
                              <span className="rounded bg-slate-100 px-2 py-0.5 text-slate-600">
                                Owner: {property.owner_name || `#${property.owner_id}`}
                              </span>
                            )}

                            {property.managed_by && Number(property.managed_by) === Number(user.id) ? (
                              <span className="rounded bg-blue-50 px-2 py-0.5 font-bold text-blue-800 border border-blue-200">
                                Managing agent
                              </span>
                            ) : property.managed_by ? (
                              <span className="rounded bg-slate-100 px-2 py-0.5 text-slate-600">
                                Manager: {property.manager_name || `#${property.managed_by}`}
                              </span>
                            ) : null}
                          </div>
                        </div>

                        <button
                          type="button"
                          onClick={() => setSelectedPropertyDetails(property)}
                          className="mt-3 flex items-center justify-between rounded-lg bg-slate-50 px-3 py-1.5 text-[11px] font-semibold text-[#0F2A43] hover:bg-slate-100 transition"
                        >
                          <span>Open property details</span>
                          <ArrowRight size={12} />
                        </button>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      {/* System Administrators section */}
      {admins.length > 0 && (
        <div className="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
          <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
            <div className="flex items-center gap-2">
              <ShieldCheck size={18} className="text-[#0F2A43]" />
              <h3 className="text-sm font-bold text-[#0F2A43]">
                System Administrators ({admins.length})
              </h3>
            </div>
            <span className="text-xs text-slate-500">Full back-office access</span>
          </div>
          <div className="mt-3 flex flex-wrap gap-3">
            {admins.map((admin) => (
              <div
                key={admin.id}
                className="inline-flex items-center gap-2.5 rounded-xl border border-slate-100 bg-slate-50/80 px-3.5 py-2 text-xs"
              >
                <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-[#0F2A43] font-bold text-white text-[11px]">
                  {(admin.name || "A")
                    .split(/\s+/)
                    .map((p) => p[0])
                    .slice(0, 2)
                    .join("")
                    .toUpperCase()}
                </span>
                <div>
                  <p className="font-bold text-[#0F2A43]">{admin.name}</p>
                  <p className="text-slate-500 text-[11px]">{admin.email}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Guidance Note */}
      <div className="flex items-start gap-3 rounded-2xl border border-blue-100 bg-blue-50/70 p-4 text-xs leading-5 text-blue-900">
        <UserRoundCheck size={18} className="mt-0.5 shrink-0" />
        <p>
          <strong>Property Ownership vs Management:</strong> An agent managing a property is distinct from its legal owner. In GoldERP Real Estate, properties can be assigned an approved agent as manager while legal title and receipts remain under the registered property owner.
        </p>
      </div>

      {/* MODALS */}
      {showInviteModal && (
        <RegistrationInviteModal
          kind={kind}
          onClose={() => setShowInviteModal(false)}
          onSendInvite={onInvitePerson}
        />
      )}

      {confirmModalData && (
        <AccountStatusConfirmModal
          person={confirmModalData.person}
          action={confirmModalData.action}
          onClose={() => setConfirmModalData(null)}
          onConfirm={onUpdateVerification}
        />
      )}

      {selectedProfileUser && (
        <UserProfileModal
          user={selectedProfileUser}
          onClose={() => setSelectedProfileUser(null)}
          onOpenProperty={(p) => setSelectedPropertyDetails(p)}
          onUpdateStatus={handleActionClick}
        />
      )}

      {selectedPropertyDetails && (
        <PropertyDetailsModal
          property={selectedPropertyDetails}
          onClose={() => setSelectedPropertyDetails(null)}
        />
      )}

      {selectedDeletionRequest && (
        <DeletionRequestReviewModal
          request={selectedDeletionRequest}
          onClose={() => setSelectedDeletionRequest(null)}
          onApprove={(id, notes) =>
            onReviewDeletionRequest ? onReviewDeletionRequest(id, "approve", notes) : null
          }
          onReject={(id, notes) =>
            onReviewDeletionRequest ? onReviewDeletionRequest(id, "reject", notes) : null
          }
        />
      )}
    </div>
  );
}

export default People;
