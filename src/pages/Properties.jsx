import { useMemo, useState } from "react";
import { MapPin, Plus, Search, SlidersHorizontal, X } from "lucide-react";
import EnquiryEditor from "../components/EnquiryEditor";
import Modal from "../components/Modal";
import PageHeading from "../components/PageHeading";
import PropertyCard from "../components/PropertyCard";
import { formatMoney, getMatches, getPropertyImages, inputClass, sameListingType } from "../lib/demo";

const blankFilters = { search: "", listingType: "", type: "", status: "", currency: "", minPrice: "", maxPrice: "" };

function Properties({ properties, enquiries, onRequestAdd, onEditProperty, onSaveEnquiry }) {
  const [filters, setFilters] = useState(blankFilters);
  const [selectedProperty, setSelectedProperty] = useState(null);
  const [enquiryProperty, setEnquiryProperty] = useState(null);

  const filteredProperties = useMemo(() => properties.filter((property) => {
    const search = filters.search.trim().toLowerCase();
    const location = [property.location.area, property.location.city, property.location.country].join(" ").toLowerCase();
    return (
      (!search || property.title.toLowerCase().includes(search) || location.includes(search)) &&
      (!filters.listingType || sameListingType(property.listingType, filters.listingType)) &&
      (!filters.type || property.type === filters.type) &&
      (!filters.status || property.status === filters.status) &&
      (!filters.currency || property.currency === filters.currency) &&
      ((!filters.minPrice && !filters.maxPrice) || (
        Boolean(filters.currency) &&
        (!filters.minPrice || Number(property.price) >= Number(filters.minPrice)) &&
        (!filters.maxPrice || Number(property.price) <= Number(filters.maxPrice))
      ))
    );
  }), [filters, properties]);

  function updateFilter(event) {
    const { name, value } = event.target;
    setFilters((current) => ({ ...current, [name]: value }));
  }

  return (
    <div>
      <PageHeading
        eyebrow="Property portfolio"
        title="Properties"
        description="Explore listings across markets, review map pins and manage verified-agent and owner inventory."
        action={<button type="button" onClick={onRequestAdd} className="inline-flex items-center justify-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#173e60]"><Plus size={18} /> Add property</button>}
      />

      <section className="mb-6 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm sm:p-5">
        <div className="mb-4 flex items-center gap-2 text-sm font-bold text-[#0F2A43]"><SlidersHorizontal size={17} /> Search and filters</div>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <div className="relative sm:col-span-2 xl:col-span-1">
            <Search size={17} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input aria-label="Search by property or location" name="search" value={filters.search} onChange={updateFilter} className={`${inputClass} pl-10`} placeholder="Location or keyword" />
          </div>
          <select aria-label="Filter listing type" name="listingType" value={filters.listingType} onChange={updateFilter} className={inputClass}><option value="">All listing types</option><option>For Sale</option><option>For Rent</option><option>BnB</option></select>
          <select aria-label="Filter property type" name="type" value={filters.type} onChange={updateFilter} className={inputClass}><option value="">All property types</option>{["House", "Apartment", "Villa", "Studio", "Townhouse", "Commercial", "Land"].map((type) => <option key={type}>{type}</option>)}</select>
          <select aria-label="Filter property status" name="status" value={filters.status} onChange={updateFilter} className={inputClass}><option value="">All statuses</option>{["Available", "Reserved", "Sold", "Rented"].map((status) => <option key={status}>{status}</option>)}</select>
          <select aria-label="Filter price currency" name="currency" value={filters.currency} onChange={updateFilter} className={inputClass}><option value="">All currencies</option>{["KSH", "USD", "EUR", "GBP", "AED"].map((currency) => <option key={currency}>{currency}</option>)}</select>
          <input aria-label="Minimum price" name="minPrice" value={filters.minPrice} onChange={updateFilter} type="number" min="0" className={inputClass} placeholder="Min price" />
          <input aria-label="Maximum price" name="maxPrice" value={filters.maxPrice} onChange={updateFilter} type="number" min="0" className={inputClass} placeholder="Max price" />
        </div>
        {(filters.minPrice || filters.maxPrice) && !filters.currency && <p className="mt-3 text-xs text-amber-700">Choose a currency to apply a price range.</p>}
        <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-4 text-sm">
          <p className="text-slate-500"><span className="font-bold text-[#0F2A43]">{filteredProperties.length}</span> listings found <span className="hidden sm:inline">· all prices shown in each listing currency</span></p>
          {(filters.search || filters.listingType || filters.type || filters.status || filters.currency || filters.minPrice || filters.maxPrice) && <button type="button" onClick={() => setFilters(blankFilters)} className="inline-flex items-center gap-1 font-semibold text-[#0F2A43] hover:text-[#B88912]"><X size={14} /> Clear filters</button>}
        </div>
      </section>

      {filteredProperties.length ? (
        <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
          {filteredProperties.map((property) => {
            const matches = getMatches(property, enquiries).length;
            return (
              <div key={property.id} className="relative">
                {matches > 0 && <span className="absolute -top-2 right-3 z-10 rounded-full bg-emerald-100 px-3 py-1 text-[11px] font-bold text-emerald-800 shadow-sm">{matches} matched {matches === 1 ? "lead" : "leads"}</span>}
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

      {enquiryProperty && <EnquiryEditor property={enquiryProperty} onClose={() => setEnquiryProperty(null)} onSave={(form, property) => { onSaveEnquiry(form, property); setEnquiryProperty(null); }} />}
      {selectedProperty && (
        <Modal title={selectedProperty.title} description={[selectedProperty.location.area, selectedProperty.location.city, selectedProperty.location.country].filter(Boolean).join(", ")} onClose={() => setSelectedProperty(null)} size="max-w-4xl">
          <div className="p-5 sm:p-7">
            <div className="mb-6 grid gap-5 md:grid-cols-[1.25fr_1fr]">
              <div className="h-60 overflow-hidden rounded-2xl bg-linear-to-br from-[#dbe8e8] to-[#b8c9c5] sm:h-72">
                {getPropertyImages(selectedProperty)[0] ? <img src={getPropertyImages(selectedProperty)[0]} alt={selectedProperty.title} className="h-full w-full object-cover" /> : <div className="flex h-full items-center justify-center text-7xl font-light tracking-widest text-[#0F2A43]/20">{selectedProperty.type.slice(0, 2).toUpperCase()}</div>}
              </div>
              <div>
                <div className="mb-3 flex flex-wrap gap-2"><span className="rounded-full bg-[#f8f0d8] px-3 py-1 text-xs font-bold text-[#876509]">{selectedProperty.listingType}</span><span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">{selectedProperty.status}</span></div>
                <h3 className="text-2xl font-bold text-[#0F2A43]">{formatMoney(selectedProperty.price, selectedProperty.currency)}</h3>
                <p className="mt-2 text-sm text-slate-600">{selectedProperty.type} · {selectedProperty.bedrooms || "—"} beds · {selectedProperty.bathrooms || "—"} baths · {selectedProperty.size || "Size n/a"}</p>
                <p className="mt-5 leading-7 text-slate-600">{selectedProperty.description}</p>
                <div className="mt-5 rounded-xl bg-slate-50 p-4 text-sm">
                  <p className="mb-2 flex items-center gap-2 font-semibold text-[#0F2A43]"><MapPin size={16} /> Map pin</p>
                  {selectedProperty.location.latitude !== null && selectedProperty.location.latitude !== "" && selectedProperty.location.longitude !== null && selectedProperty.location.longitude !== "" ? <a className="font-medium text-blue-700 underline" href={`https://www.google.com/maps?q=${selectedProperty.location.latitude},${selectedProperty.location.longitude}`} target="_blank" rel="noreferrer">Open location in Google Maps ↗</a> : <p className="text-slate-500">Coordinates not added to this listing yet.</p>}
                </div>
              </div>
            </div>
            <div className="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-between">
              <p className="self-center text-xs text-slate-400">Enquiries go to our team first; seller and agent details stay private.</p>
              <div className="flex flex-col gap-2 sm:flex-row">
                {onEditProperty && <button type="button" onClick={() => { onEditProperty(selectedProperty); setSelectedProperty(null); }} className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-[#0F2A43] hover:bg-slate-50">Edit property</button>}
                <button type="button" onClick={() => { setEnquiryProperty(selectedProperty); setSelectedProperty(null); }} className="rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60]">Record buyer enquiry</button>
              </div>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}

export default Properties;
