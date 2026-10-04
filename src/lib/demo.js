export const inputClass =
  "w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-[#D4A72C] focus:ring-2 focus:ring-[#D4A72C]/15";

export function formatMoney(amount, currency = "KSH") {
  return `${currency} ${Number(amount || 0).toLocaleString()}`;
}

export function formatCurrencyTotals(entries) {
  const totals = new Map();
  entries.forEach((entry) => {
    const currency = entry.currency || "KSH";
    totals.set(currency, (totals.get(currency) || 0) + Number(entry.amount || entry.salePrice || 0));
  });
  return Array.from(totals, ([currency, amount]) => formatMoney(amount, currency)).join(" · ") || formatMoney(0);
}

export function formatDate(date) {
  return new Date(date).toLocaleDateString(undefined, {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

export function getPropertyImages(property) {
  if (property.images?.length) return property.images;
  return property.id === 2 ? ["/assets/apartment2.jpg"] : [];
}

export function sameListingType(first, second) {
  const normalize = (value) => value?.toLowerCase() === "bnb" ? "bnb" : value?.toLowerCase();
  return normalize(first) === normalize(second);
}

export function getMatches(property, enquiries) {
  if (property.status !== "Available") return [];

  return enquiries.filter((enquiry) => {
    if (enquiry.status === "Closed") return false;
    const place = [property.location?.city, property.location?.area]
      .filter(Boolean)
      .join(" ")
      .toLowerCase();
    const desiredPlace = [enquiry.city, enquiry.area]
      .filter(Boolean)
      .join(" ")
      .toLowerCase();
    const locationMatch = !desiredPlace || place.includes(desiredPlace);
    const countryMatch =
      !enquiry.country ||
      !property.location?.country ||
      property.location.country.toLowerCase() === enquiry.country.toLowerCase();
    const typeMatch = !enquiry.propertyType || enquiry.propertyType === property.type;
    const listingMatch = !enquiry.listingType || sameListingType(enquiry.listingType, property.listingType);
    const price = Number(property.price);
    const budgetMatch =
      (!enquiry.currency || !property.currency || property.currency === enquiry.currency) &&
      (!Number(enquiry.budgetMin) || price >= Number(enquiry.budgetMin)) &&
      (!Number(enquiry.budgetMax) || price <= Number(enquiry.budgetMax));

    return locationMatch && countryMatch && typeMatch && listingMatch && budgetMatch;
  });
}

export function getStoredValue(key, fallback) {
  try {
    const savedValue = localStorage.getItem(key);
    return savedValue ? JSON.parse(savedValue) : fallback;
  } catch (error) {
    console.error(`Unable to load demo data for "${key}".`, error);
    return fallback;
  }
}
