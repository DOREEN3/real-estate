import { useEffect, useMemo, useState } from "react";
import {
  Archive,
  CalendarClock,
  CheckCircle2,
  Download,
  FileCheck2,
  MapPin,
  Plus,
  Search,
  ShieldCheck,
  SlidersHorizontal,
  Upload,
  X,
} from "lucide-react";
import EnquiryEditor from "../components/EnquiryEditor";
import Modal from "../components/Modal";
import PageHeading from "../components/PageHeading";
import PropertyCard from "../components/PropertyCard";
import {
  approveProperty,
  archiveProperty,
  downloadVerificationDocument,
  getAdminPropertyVerifications,
  getMyVerificationVisits,
  getPropertyVerifications,
  recordVerificationVisitOutcome,
  rejectProperty,
  requestPropertyVerification,
  reviewPropertyVerification,
  scheduleVerificationVisit,
  uploadVerificationDocument,
} from "../api/properties";
import { formatDate, formatMoney, getMatches, getPropertyImages, inputClass, sameListingType } from "../lib/utils";

const blankFilters = {
  search: "",
  listingType: "",
  type: "",
  status: "",
  approvalStatus: "",
  currency: "",
  minPrice: "",
  maxPrice: "",
};

const verificationBadgeStyles = {
  pending: "bg-amber-50 text-amber-800 border-amber-200",
  documents_submitted: "bg-blue-50 text-blue-700 border-blue-200",
  under_review: "bg-violet-50 text-violet-700 border-violet-200",
  visit_required: "bg-orange-50 text-orange-800 border-orange-200",
  verified: "bg-emerald-50 text-emerald-700 border-emerald-200",
  rejected: "bg-red-50 text-red-700 border-red-200",
};

function Properties({
  properties,
  enquiries,
  agents = [],
  user = { role: "admin" },
  onRequestAdd,
  onEditProperty,
  onUpdateStatus,
  onSaveEnquiry,
  onReload,
  onToast,
}) {
  const [filters, setFilters] = useState(blankFilters);
  const [selectedProperty, setSelectedProperty] = useState(null);
  const [enquiryProperty, setEnquiryProperty] = useState(null);
  const [activeImageIdx, setActiveImageIdx] = useState(0);

  // Property-level verification state inside modal
  const [verifications, setVerifications] = useState([]);
  const [loadingVerifications, setLoadingVerifications] = useState(false);
  const [uploadingDoc, setUploadingDoc] = useState(false);
  const [reviewForm, setReviewForm] = useState({
    verification_status: "verified",
    review_notes: "",
  });
  const [visitForm, setVisitForm] = useState({
    assigned_to: "",
    scheduled_at: "",
    visit_notes: "",
  });

  // Portfolio-level verification & site visits panel
  const [showVerificationDesk, setShowVerificationDesk] = useState(false);
  const [adminVerifications, setAdminVerifications] = useState([]);
  const [myVisits, setMyVisits] = useState([]);
  const [visitOutcomeNotes, setVisitOutcomeNotes] = useState({});

  const isAdmin = user?.role === "admin";
  const isAgent = user?.role === "agent";

  const filteredProperties = useMemo(
    () =>
      properties.filter((property) => {
        const search = filters.search.trim().toLowerCase();
        const location = [property.location.area, property.location.city, property.location.country]
          .join(" ")
          .toLowerCase();
        return (
          (!search || property.title.toLowerCase().includes(search) || location.includes(search)) &&
          (!filters.listingType || sameListingType(property.listingType, filters.listingType)) &&
          (!filters.type || property.type === filters.type) &&
          (!filters.status || property.status === filters.status) &&
          (!filters.approvalStatus ||
            (property.propertyStatus || "approved").toLowerCase() === filters.approvalStatus.toLowerCase()) &&
          (!filters.currency || property.currency === filters.currency) &&
          ((!filters.minPrice && !filters.maxPrice) ||
            (Boolean(filters.currency) &&
              (!filters.minPrice || Number(property.price) >= Number(filters.minPrice)) &&
              (!filters.maxPrice || Number(property.price) <= Number(filters.maxPrice))))
        );
      }),
    [filters, properties]
  );

  function updateFilter(event) {
    const { name, value } = event.target;
    setFilters((current) => ({ ...current, [name]: value }));
  }

  async function loadPropertyVerification(propertyId) {
    if (!propertyId) return;
    setLoadingVerifications(true);
    try {
      const res = await getPropertyVerifications(propertyId);
      if (res.success && Array.isArray(res.data?.data)) {
        setVerifications(res.data.data);
        const latest = res.data.data[0];
        if (latest) {
          setReviewForm({
            verification_status:
              latest.verification_status === "verified" || latest.verification_status === "rejected"
                ? latest.verification_status
                : "verified",
            review_notes: latest.review_notes || "",
          });
        }
      } else {
        setVerifications([]);
      }
    } finally {
      setLoadingVerifications(false);
    }
  }

  async function loadVerificationDesk() {
    if (isAdmin) {
      const vRes = await getAdminPropertyVerifications();
      if (vRes.success && Array.isArray(vRes.data?.data)) {
        setAdminVerifications(vRes.data.data);
      }
    }
    if (isAdmin || isAgent) {
      const visitsRes = await getMyVerificationVisits();
      if (visitsRes.success && Array.isArray(visitsRes.data?.data)) {
        setMyVisits(visitsRes.data.data);
      }
    }
  }

  useEffect(() => {
    if (selectedProperty?.id) {
      setActiveImageIdx(0);
      loadPropertyVerification(selectedProperty.id);
    } else {
      setVerifications([]);
    }
  }, [selectedProperty?.id]);

  useEffect(() => {
    if (showVerificationDesk) {
      loadVerificationDesk();
    }
  }, [showVerificationDesk]);

  async function handleUploadVerificationFile(event) {
    const file = event.target.files?.[0];
    if (!file || !selectedProperty?.id) return;
    setUploadingDoc(true);
    try {
      let verificationId = verifications[0]?.id;
      if (!verificationId || verifications[0]?.verification_status === "verified") {
        const reqRes = await requestPropertyVerification(selectedProperty.id);
        if (!reqRes.success) {
          onToast?.(reqRes.message || "Unable to initiate property verification.");
          return;
        }
        verificationId = reqRes.data?.verification?.id;
      }

      const upRes = await uploadVerificationDocument(verificationId, file);
      if (upRes.success) {
        onToast?.("Verification document uploaded and submitted for review.");
        await loadPropertyVerification(selectedProperty.id);
      } else {
        onToast?.(upRes.message || "Failed to upload verification document.");
      }
    } finally {
      setUploadingDoc(false);
      event.target.value = "";
    }
  }

  async function handleDownloadDocument(verificationId) {
    const res = await downloadVerificationDocument(verificationId);
    if (!res.success) {
      onToast?.(res.message || "Unable to download verification document.");
    }
  }

  async function handleAdminReview(verificationId) {
    const res = await reviewPropertyVerification(verificationId, reviewForm);
    if (res.success) {
      onToast?.(`Verification status updated to ${reviewForm.verification_status.replace(/_/g, " ")}.`);
      await loadPropertyVerification(selectedProperty?.id);
      if (showVerificationDesk) await loadVerificationDesk();
      onReload?.();
    } else {
      onToast?.(res.message || "Unable to submit verification review.");
    }
  }

  async function handleScheduleVisit(event, verificationId) {
    event.preventDefault();
    if (!visitForm.assigned_to) {
      onToast?.("Please select an agent or administrator for the site visit.");
      return;
    }
    let formattedDate = null;
    if (visitForm.scheduled_at) {
      formattedDate = visitForm.scheduled_at.replace("T", " ");
      if (formattedDate.length === 16) formattedDate += ":00";
    }
    const res = await scheduleVerificationVisit(verificationId, {
      assigned_to: visitForm.assigned_to,
      scheduled_at: formattedDate,
      visit_notes: visitForm.visit_notes,
    });
    if (res.success) {
      onToast?.("Site verification visit scheduled.");
      setVisitForm({ assigned_to: "", scheduled_at: "", visit_notes: "" });
      await loadPropertyVerification(selectedProperty?.id);
      if (showVerificationDesk) await loadVerificationDesk();
    } else {
      onToast?.(res.message || "Unable to schedule site visit.");
    }
  }

  async function handleVisitOutcome(visitId, status) {
    const notes = (visitOutcomeNotes[visitId] || "").trim() || (status === "completed" ? "Site inspection completed and verified." : "Visit cancelled.");
    const res = await recordVerificationVisitOutcome(visitId, {
      visit_status: status,
      visit_notes: notes,
    });
    if (res.success) {
      onToast?.(`Site visit marked as ${status}.`);
      await loadVerificationDesk();
    } else {
      onToast?.(res.message || "Unable to record visit outcome.");
    }
  }

  async function handleArchiveProperty(property) {
    const res = await archiveProperty(property.id);
    if (res.success) {
      onToast?.("Property archived; its history and records were retained.");
      setSelectedProperty(null);
      onReload?.();
    } else {
      onToast?.(res.message || "Unable to archive property.");
    }
  }

  async function handleListingReview(property, decision) {
    if (
      decision === "approve" &&
      latestVerification &&
      latestVerification.verification_status !== "verified"
    ) {
      if (latestVerification.document_available) {
        const verifyRes = await reviewPropertyVerification(latestVerification.id, {
          verification_status: "verified",
          review_notes:
            reviewForm.review_notes || "Ownership document verified and approved by administrator.",
        });
        if (!verifyRes.success) {
          onToast?.(verifyRes.message || "Unable to verify ownership document prior to approval.");
          return;
        }
      } else {
        onToast?.(
          "Please upload and verify the property's ownership document before approving the listing."
        );
        return;
      }
    }

    const res =
      decision === "approve" ? await approveProperty(property.id) : await rejectProperty(property.id);
    if (res.success) {
      onToast?.(`Property listing ${decision}d.`);
      setSelectedProperty(null);
      onReload?.();
    } else {
      onToast?.(res.message || `Unable to ${decision} property.`);
    }
  }

  const latestVerification = verifications[0] || null;
  const selectedImages = selectedProperty ? getPropertyImages(selectedProperty) : [];

  return (
    <div>
      <PageHeading
        eyebrow="Property portfolio"
        title="Properties"
        description="Explore listings across markets, verify ownership documents, schedule site visits and manage inventory."
        action={
          <div className="flex flex-wrap items-center gap-2.5">
            {(isAdmin || isAgent) && (
              <button
                type="button"
                onClick={() => setShowVerificationDesk((prev) => !prev)}
                className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-[#0F2A43] shadow-sm transition hover:bg-slate-50"
              >
                <ShieldCheck size={18} />
                {showVerificationDesk ? "Hide verification desk" : "Document & visit verification"}
              </button>
            )}
            <button
              type="button"
              onClick={onRequestAdd}
              className="inline-flex items-center justify-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#173e60]"
            >
              <Plus size={18} /> Add property
            </button>
          </div>
        }
      />

      {showVerificationDesk && (isAdmin || isAgent) && (
        <section className="mb-6 space-y-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-4">
            <div>
              <h2 className="flex items-center gap-2 font-bold text-[#0F2A43]">
                <FileCheck2 size={18} /> Property Document Verification &amp; Site Inspection Desk
              </h2>
              <p className="mt-1 text-xs text-slate-500">
                Review ownership documents, download submitted title deeds, and record physical site inspection outcomes.
              </p>
            </div>
            <button
              type="button"
              onClick={loadVerificationDesk}
              className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-[#0F2A43] hover:bg-slate-50"
            >
              Refresh queue
            </button>
          </div>

          {isAdmin && (
            <div>
              <h3 className="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">
                Verification submissions ({adminVerifications.length})
              </h3>
              {adminVerifications.length === 0 ? (
                <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">
                  No property verification requests submitted yet. Open any property and upload a verification document to start.
                </p>
              ) : (
                <div className="grid gap-3 md:grid-cols-2">
                  {adminVerifications.map((item) => (
                    <div key={item.id} className="rounded-xl border border-slate-100 bg-slate-50/70 p-4 text-sm">
                      <div className="flex items-start justify-between gap-2">
                        <div>
                          <p className="font-bold text-[#0F2A43]">{item.property_title || `Property #${item.property_id}`}</p>
                          <p className="text-xs text-slate-500">
                            Owner: {item.owner_name || `#${item.owner_id}`} · Submitted {formatDate(item.created_at)}
                          </p>
                        </div>
                        <span
                          className={`rounded-full border px-2.5 py-0.5 text-[11px] font-bold uppercase ${
                            verificationBadgeStyles[item.verification_status] || verificationBadgeStyles.pending
                          }`}
                        >
                          {(item.verification_status || "pending").replace(/_/g, " ")}
                        </span>
                      </div>
                      {item.review_notes && (
                        <p className="mt-2 rounded-lg bg-white p-2 text-xs text-slate-600">Notes: {item.review_notes}</p>
                      )}
                      <div className="mt-3 flex flex-wrap items-center gap-2">
                        {item.document_available && (
                          <button
                            type="button"
                            onClick={() => handleDownloadDocument(item.id)}
                            className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-[#0F2A43] hover:bg-slate-100"
                          >
                            <Download size={13} /> Download document
                          </button>
                        )}
                        <button
                          type="button"
                          onClick={() => {
                            const found = properties.find((p) => Number(p.id) === Number(item.property_id));
                            if (found) setSelectedProperty(found);
                          }}
                          className="rounded-lg bg-[#0F2A43] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#173e60]"
                        >
                          Open &amp; review
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          <div>
            <h3 className="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">
              Assigned site inspection visits ({myVisits.length})
            </h3>
            {myVisits.length === 0 ? (
              <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">
                No site inspection visits scheduled yet.
              </p>
            ) : (
              <div className="grid gap-3 md:grid-cols-2">
                {myVisits.map((visit) => (
                  <div key={visit.id} className="rounded-xl border border-slate-100 bg-slate-50/70 p-4 text-sm">
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <p className="font-bold text-[#0F2A43]">{visit.property_title}</p>
                        <p className="text-xs text-slate-500">
                          Assigned to: {visit.assigned_to_name} ·{" "}
                          {visit.scheduled_at ? `Scheduled ${formatDate(visit.scheduled_at)}` : "Unscheduled"}
                        </p>
                      </div>
                      <span className="rounded-full bg-white px-2.5 py-0.5 text-[11px] font-bold uppercase text-[#0F2A43]">
                        {visit.visit_status}
                      </span>
                    </div>
                    {visit.visit_notes && <p className="mt-2 text-xs text-slate-600">{visit.visit_notes}</p>}
                    {visit.visit_status === "scheduled" && (
                      <div className="mt-3 space-y-2">
                        <input
                          value={visitOutcomeNotes[visit.id] || ""}
                          onChange={(e) =>
                            setVisitOutcomeNotes((curr) => ({ ...curr, [visit.id]: e.target.value }))
                          }
                          placeholder="Inspection outcome notes (required to complete)"
                          className={`${inputClass} py-2 text-xs`}
                        />
                        <div className="flex gap-2">
                          <button
                            type="button"
                            onClick={() => handleVisitOutcome(visit.id, "completed")}
                            className="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800"
                          >
                            Complete visit
                          </button>
                          <button
                            type="button"
                            onClick={() => handleVisitOutcome(visit.id, "cancelled")}
                            className="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50"
                          >
                            Cancel visit
                          </button>
                        </div>
                      </div>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        </section>
      )}

      <section className="mb-6 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm sm:p-5">
        <div className="mb-4 flex items-center gap-2 text-sm font-bold text-[#0F2A43]">
          <SlidersHorizontal size={17} /> Search and filters
        </div>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <div className="relative sm:col-span-2 xl:col-span-1">
            <Search size={17} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              aria-label="Search by property or location"
              name="search"
              value={filters.search}
              onChange={updateFilter}
              className={`${inputClass} pl-10`}
              placeholder="Location or keyword"
            />
          </div>
          <select
            aria-label="Filter listing type"
            name="listingType"
            value={filters.listingType}
            onChange={updateFilter}
            className={inputClass}
          >
            <option value="">All listing types</option>
            <option value="For Sale">For Sale</option>
            <option value="For Rent">For Rent</option>
            <option value="BnB">BnB</option>
          </select>
          <select
            aria-label="Filter property type"
            name="type"
            value={filters.type}
            onChange={updateFilter}
            className={inputClass}
          >
            <option value="">All property types</option>
            {["House", "Apartment", "Villa", "Condo", "Townhouse", "Commercial", "Land"].map((type) => (
              <option key={type} value={type}>
                {type}
              </option>
            ))}
          </select>
          <select
            aria-label="Filter property status"
            name="status"
            value={filters.status}
            onChange={updateFilter}
            className={inputClass}
          >
            <option value="">All availability statuses</option>
            {["Available", "Reserved", "Sold", "Rented", "Occupied", "Unavailable"].map((status) => (
              <option key={status} value={status}>
                {status}
              </option>
            ))}
          </select>
          <select
            aria-label="Filter approval status"
            name="approvalStatus"
            value={filters.approvalStatus}
            onChange={updateFilter}
            className={inputClass}
          >
            <option value="">All approval statuses</option>
            <option value="pending">Pending approval</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
          </select>
          <select
            aria-label="Filter price currency"
            name="currency"
            value={filters.currency}
            onChange={updateFilter}
            className={inputClass}
          >
            <option value="">All currencies</option>
            {["KES", "USD", "EUR", "GBP", "AED"].map((currency) => (
              <option key={currency} value={currency}>
                {currency}
              </option>
            ))}
          </select>
          <input
            aria-label="Minimum price"
            name="minPrice"
            value={filters.minPrice}
            onChange={updateFilter}
            type="number"
            min="0"
            className={inputClass}
            placeholder="Min price"
          />
          <input
            aria-label="Maximum price"
            name="maxPrice"
            value={filters.maxPrice}
            onChange={updateFilter}
            type="number"
            min="0"
            className={inputClass}
            placeholder="Max price"
          />
        </div>
        {(filters.minPrice || filters.maxPrice) && !filters.currency && (
          <p className="mt-3 text-xs text-amber-700">Choose a currency to apply a price range.</p>
        )}
        <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-4 text-sm">
          <p className="text-slate-500">
            <span className="font-bold text-[#0F2A43]">{filteredProperties.length}</span> listings found{" "}
            <span className="hidden sm:inline">· all prices shown in each listing currency</span>
          </p>
          {(filters.search ||
            filters.listingType ||
            filters.type ||
            filters.status ||
            filters.approvalStatus ||
            filters.currency ||
            filters.minPrice ||
            filters.maxPrice) && (
            <button
              type="button"
              onClick={() => setFilters(blankFilters)}
              className="inline-flex items-center gap-1 font-semibold text-[#0F2A43] hover:text-[#B88912]"
            >
              <X size={14} /> Clear filters
            </button>
          )}
        </div>
      </section>

      {filteredProperties.length ? (
        <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
          {filteredProperties.map((property) => {
            const matches = getMatches(property, enquiries).length;
            return (
              <div key={property.id} className="relative">
                {matches > 0 && (
                  <span className="absolute -top-2 right-3 z-10 rounded-full bg-emerald-100 px-3 py-1 text-[11px] font-bold text-emerald-800 shadow-sm">
                    {matches} matched {matches === 1 ? "lead" : "leads"}
                  </span>
                )}
                <PropertyCard property={property} onView={() => setSelectedProperty(property)} />
              </div>
            );
          })}
        </div>
      ) : (
        <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
          <Search className="mx-auto mb-3 text-slate-300" size={28} />
          <h2 className="font-bold text-[#0F2A43]">No listings match these filters</h2>
          <p className="mt-1 text-sm text-slate-500">Try another area, type or price range.</p>
        </div>
      )}

      {enquiryProperty && (
        <EnquiryEditor
          property={enquiryProperty}
          onClose={() => setEnquiryProperty(null)}
          onSave={(form, property) => {
            onSaveEnquiry(form, property);
            setEnquiryProperty(null);
          }}
        />
      )}
      {selectedProperty && (
        <Modal
          title={selectedProperty.title}
          description={[selectedProperty.location.area, selectedProperty.location.city, selectedProperty.location.country]
            .filter(Boolean)
            .join(", ")}
          onClose={() => setSelectedProperty(null)}
          size="max-w-4xl"
        >
          <div className="space-y-6 p-5 sm:p-7">
            <div className="grid gap-5 md:grid-cols-[1.25fr_1fr]">
              <div>
                <div className="h-60 overflow-hidden rounded-2xl bg-linear-to-br from-[#dbe8e8] to-[#b8c9c5] sm:h-72">
                  {selectedImages[activeImageIdx] || selectedImages[0] ? (
                    <img
                      src={selectedImages[activeImageIdx] || selectedImages[0]}
                      alt={selectedProperty.title}
                      className="h-full w-full object-cover"
                    />
                  ) : (
                    <div className="flex h-full items-center justify-center text-7xl font-light tracking-widest text-[#0F2A43]/20">
                      {selectedProperty.type.slice(0, 2).toUpperCase()}
                    </div>
                  )}
                </div>
                {selectedImages.length > 1 && (
                  <div className="mt-2.5 flex flex-wrap gap-2">
                    {selectedImages.map((imgUrl, idx) => (
                      <button
                        key={idx}
                        type="button"
                        onClick={() => setActiveImageIdx(idx)}
                        className={`h-14 w-16 overflow-hidden rounded-lg border-2 transition ${
                          activeImageIdx === idx ? "border-[#0F2A43]" : "border-transparent opacity-70 hover:opacity-100"
                        }`}
                      >
                        <img src={imgUrl} alt="" className="h-full w-full object-cover" />
                      </button>
                    ))}
                  </div>
                )}
              </div>
              <div>
                <div className="mb-3 flex flex-wrap items-center gap-2">
                  <span className="rounded-full bg-[#f8f0d8] px-3 py-1 text-xs font-bold text-[#876509]">
                    {selectedProperty.listingType}
                  </span>
                  {onUpdateStatus ? (
                    <select
                      aria-label="Update property status"
                      value={selectedProperty.status}
                      onChange={(event) => {
                        const nextStatus = event.target.value;
                        setSelectedProperty((curr) => (curr ? { ...curr, status: nextStatus } : null));
                        onUpdateStatus(selectedProperty.id, nextStatus);
                      }}
                      className="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 outline-none focus:border-[#0F2A43]"
                    >
                      {["Available", "Reserved", "Sold", "Rented", "Occupied", "Unavailable"].map((status) => (
                        <option key={status} value={status}>
                          {status}
                        </option>
                      ))}
                    </select>
                  ) : (
                    <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">
                      {selectedProperty.status}
                    </span>
                  )}
                  {selectedProperty.propertyStatus && (
                    <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold capitalize text-slate-700">
                      Approval: {selectedProperty.propertyStatus}
                    </span>
                  )}
                </div>
                <h3 className="text-2xl font-bold text-[#0F2A43]">
                  {formatMoney(selectedProperty.price, selectedProperty.currency)}
                </h3>
                <p className="mt-2 text-sm text-slate-600">
                  {selectedProperty.type} · {selectedProperty.bedrooms || "—"} beds ·{" "}
                  {selectedProperty.bathrooms || "—"} baths · {selectedProperty.size || "Size n/a"}
                </p>
                <p className="mt-4 leading-7 text-slate-600">{selectedProperty.description}</p>
                <div className="mt-4 rounded-xl bg-slate-50 p-4 text-sm">
                  <p className="mb-2 flex items-center gap-2 font-semibold text-[#0F2A43]">
                    <MapPin size={16} /> Map pin
                  </p>
                  {selectedProperty.location.latitude !== null &&
                  selectedProperty.location.latitude !== "" &&
                  selectedProperty.location.longitude !== null &&
                  selectedProperty.location.longitude !== "" ? (
                    <a
                      className="font-medium text-blue-700 underline"
                      href={`https://www.google.com/maps?q=${selectedProperty.location.latitude},${selectedProperty.location.longitude}`}
                      target="_blank"
                      rel="noreferrer"
                    >
                      Open location in Google Maps ↗
                    </a>
                  ) : (
                    <p className="text-slate-500">Coordinates not added to this listing yet.</p>
                  )}
                </div>
              </div>
            </div>

            {/* Property Document Verification & Site Visit Section */}
            <div className="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 sm:p-5">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                  <FileCheck2 size={18} className="text-[#0F2A43]" />
                  <h4 className="font-bold text-[#0F2A43]">Ownership &amp; Title Deed Verification</h4>
                </div>
                {latestVerification ? (
                  <span
                    className={`rounded-full border px-3 py-1 text-xs font-bold uppercase ${
                      verificationBadgeStyles[latestVerification.verification_status] || verificationBadgeStyles.pending
                    }`}
                  >
                    {latestVerification.verification_status.replace(/_/g, " ")}
                  </span>
                ) : (
                  <span className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-500">
                    Not yet submitted
                  </span>
                )}
              </div>

              {loadingVerifications ? (
                <p className="mt-3 text-xs text-slate-500">Loading verification status...</p>
              ) : (
                <div className="mt-4 space-y-4">
                  {latestVerification && (
                    <div className="rounded-xl border border-slate-200/80 bg-white p-3.5 text-xs text-slate-600">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <span>
                          Verification #{latestVerification.id} · Requested {formatDate(latestVerification.created_at)}
                          {latestVerification.reviewer_name ? ` · Reviewed by ${latestVerification.reviewer_name}` : ""}
                        </span>
                        {latestVerification.document_available ? (
                          <button
                            type="button"
                            onClick={() => handleDownloadDocument(latestVerification.id)}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-[#0F2A43] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#173e60]"
                          >
                            <Download size={13} /> Download attached document
                          </button>
                        ) : (
                          <span className="text-amber-700 font-semibold">No document file uploaded yet</span>
                        )}
                      </div>
                      {latestVerification.review_notes && (
                        <p className="mt-2 border-t border-slate-100 pt-2 text-slate-700">
                          <strong>Review notes:</strong> {latestVerification.review_notes}
                        </p>
                      )}
                    </div>
                  )}

                  {/* Upload or replace verification document */}
                  <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed border-slate-300 bg-white p-3.5">
                    <div>
                      <p className="text-xs font-semibold text-[#0F2A43]">
                        {latestVerification?.document_available
                          ? "Upload updated verification document (PDF, JPG, PNG)"
                          : "Attach ownership proof / title deed (PDF, JPG, PNG up to 10 MB)"}
                      </p>
                      <p className="text-[11px] text-slate-500">
                        Stored in private verification storage for administrator review.
                      </p>
                    </div>
                    <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2 text-xs font-semibold text-[#0F2A43] hover:bg-slate-100">
                      <Upload size={14} />
                      {uploadingDoc ? "Uploading..." : "Select & upload document"}
                      <input
                        type="file"
                        accept=".pdf,application/pdf,image/jpeg,image/png"
                        onChange={handleUploadVerificationFile}
                        disabled={uploadingDoc}
                        className="hidden"
                      />
                    </label>
                  </div>

                  {/* Admin Review & Site Visit Scheduling */}
                  {isAdmin && latestVerification && (
                    <div className="grid gap-4 border-t border-slate-200 pt-4 md:grid-cols-2">
                      <div className="space-y-2.5 rounded-xl bg-white p-3.5 border border-slate-200/80">
                        <p className="text-xs font-bold uppercase tracking-wider text-[#0F2A43]">
                          Admin Verification Review
                        </p>
                        <select
                          value={reviewForm.verification_status}
                          onChange={(e) =>
                            setReviewForm((curr) => ({ ...curr, verification_status: e.target.value }))
                          }
                          className={`${inputClass} py-2 text-xs`}
                        >
                          <option value="verified">Verified (Approve Document)</option>
                          <option value="under_review">Under Review</option>
                          <option value="visit_required">Site Visit Required</option>
                          <option value="rejected">Rejected</option>
                        </select>
                        <input
                          value={reviewForm.review_notes}
                          onChange={(e) => setReviewForm((curr) => ({ ...curr, review_notes: e.target.value }))}
                          placeholder="Review notes or remarks..."
                          className={`${inputClass} py-2 text-xs`}
                        />
                        <button
                          type="button"
                          disabled={!latestVerification.document_available}
                          onClick={() => handleAdminReview(latestVerification.id)}
                          className="inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-800 disabled:opacity-50"
                        >
                          <CheckCircle2 size={14} /> Save verification decision
                        </button>
                      </div>

                      <form
                        onSubmit={(e) => handleScheduleVisit(e, latestVerification.id)}
                        className="space-y-2.5 rounded-xl bg-white p-3.5 border border-slate-200/80"
                      >
                        <p className="text-xs font-bold uppercase tracking-wider text-[#0F2A43]">
                          Schedule Site Inspection Visit
                        </p>
                        <select
                          value={visitForm.assigned_to}
                          onChange={(e) => setVisitForm((curr) => ({ ...curr, assigned_to: e.target.value }))}
                          className={`${inputClass} py-2 text-xs`}
                        >
                          <option value="">Assign to verified agent / admin...</option>
                          {user?.id && <option value={user.id}>{user.name || "Administrator"} (Admin)</option>}
                          {agents
                            .filter((a) => a.verified)
                            .map((a) => (
                              <option key={a.id} value={a.id}>
                                {a.name} (Agent)
                              </option>
                            ))}
                        </select>
                        <input
                          type="datetime-local"
                          value={visitForm.scheduled_at}
                          onChange={(e) => setVisitForm((curr) => ({ ...curr, scheduled_at: e.target.value }))}
                          className={`${inputClass} py-2 text-xs`}
                        />
                        <input
                          value={visitForm.visit_notes}
                          onChange={(e) => setVisitForm((curr) => ({ ...curr, visit_notes: e.target.value }))}
                          placeholder="Inspection instructions..."
                          className={`${inputClass} py-2 text-xs`}
                        />
                        <button
                          type="submit"
                          disabled={!latestVerification.document_available}
                          className="inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-[#0F2A43] px-3 py-2 text-xs font-semibold text-white hover:bg-[#173e60] disabled:opacity-50"
                        >
                          <CalendarClock size={14} /> Schedule site visit
                        </button>
                      </form>
                    </div>
                  )}
                </div>
              )}
            </div>

            <div className="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-between">
              <div className="flex flex-wrap items-center gap-2">
                {isAdmin && selectedProperty.propertyStatus === "pending" && (
                  <>
                    <button
                      type="button"
                      onClick={() => handleListingReview(selectedProperty, "approve")}
                      className="rounded-xl bg-emerald-700 px-4 py-2.5 text-xs font-semibold text-white hover:bg-emerald-800"
                    >
                      Approve listing
                    </button>
                    <button
                      type="button"
                      onClick={() => handleListingReview(selectedProperty, "reject")}
                      className="rounded-xl border border-red-200 px-4 py-2.5 text-xs font-semibold text-red-600 hover:bg-red-50"
                    >
                      Reject listing
                    </button>
                  </>
                )}
                {selectedProperty.status !== "Unavailable" && (
                  <button
                    type="button"
                    onClick={() => handleArchiveProperty(selectedProperty)}
                    className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                  >
                    <Archive size={14} /> Archive listing
                  </button>
                )}
              </div>
              <div className="flex flex-col gap-2 sm:flex-row">
                {onEditProperty && (
                  <button
                    type="button"
                    onClick={() => {
                      onEditProperty(selectedProperty);
                      setSelectedProperty(null);
                    }}
                    className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-[#0F2A43] hover:bg-slate-50"
                  >
                    Edit property
                  </button>
                )}
                <button
                  type="button"
                  onClick={() => {
                    setEnquiryProperty(selectedProperty);
                    setSelectedProperty(null);
                  }}
                  className="rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60]"
                >
                  Record buyer enquiry
                </button>
              </div>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}

export default Properties;
