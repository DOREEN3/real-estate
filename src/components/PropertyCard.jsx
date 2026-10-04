import { ArrowUpRight, BedDouble, Bath, MapPin, Ruler } from "lucide-react";
import { formatMoney, getPropertyImages } from "../lib/demo";

function PropertyCard({ property, onView }) {
  const image = getPropertyImages(property)[0];

  return (
    <article className="group flex h-full flex-col overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-xl">
      <div className="relative h-48 overflow-hidden bg-gradient-to-br from-[#dbe8e8] via-[#edf2ec] to-[#b8c9c5]">
        {image ? (
          <img
            src={image}
            alt={property.title}
            className="h-full w-full object-cover transition duration-500 group-hover:scale-105"
          />
        ) : (
          <div className="flex h-full items-center justify-center">
            <span className="text-6xl font-light tracking-widest text-[#0F2A43]/15">
              {property.type?.slice(0, 2).toUpperCase() || "RE"}
            </span>
          </div>
        )}
        <span className="absolute left-4 top-4 rounded-full bg-white/95 px-3 py-1 text-xs font-bold text-[#0F2A43] shadow-sm">
          {property.listingType}
        </span>
        <span className="absolute right-4 top-4 rounded-full bg-[#0F2A43]/85 px-3 py-1 text-xs font-semibold text-white">
          {property.status}
        </span>
      </div>

      <div className="flex flex-1 flex-col p-5">
        <p className="mb-1 text-xs font-bold uppercase tracking-wider text-[#B88912]">{property.type}</p>
        <h2 className="mb-2 line-clamp-1 text-lg font-bold text-[#0F2A43]">{property.title}</h2>
        <p className="mb-4 flex items-center gap-1.5 text-sm text-slate-500">
          <MapPin size={15} />
          {[property.location.area, property.location.city, property.location.country].filter(Boolean).join(", ")}
        </p>
        <div className="mb-5 flex flex-wrap gap-x-4 gap-y-2 text-xs text-slate-500">
          <span className="flex items-center gap-1"><BedDouble size={14} />{property.bedrooms ?? "—"} beds</span>
          <span className="flex items-center gap-1"><Bath size={14} />{property.bathrooms ?? "—"} baths</span>
          <span className="flex items-center gap-1"><Ruler size={14} />{property.size || "Size n/a"}</span>
        </div>

        <div className="mt-auto flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
          <p className="text-base font-bold leading-tight text-[#0F2A43]">
            {formatMoney(property.price, property.currency)}
          </p>
          <button
            type="button"
            onClick={onView}
            className="inline-flex shrink-0 items-center gap-1 rounded-lg bg-[#0F2A43] px-3 py-2 text-xs font-semibold text-white transition hover:bg-[#173e60]"
          >
            Details <ArrowUpRight size={15} />
          </button>
        </div>
      </div>
    </article>
  );
}

export default PropertyCard;