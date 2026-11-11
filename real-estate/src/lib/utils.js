export const inputClass =
  "w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-[#D4A72C] focus:ring-2 focus:ring-[#D4A72C]/15";

export const fallbackPropertyImage = "/properties/house1.svg";

export function printDocument(documentType) {
  const allowedTypes = new Set(["receipt", "statement", "report"]);
  if (!allowedTypes.has(documentType)) {
    throw new Error("Unsupported print document type.");
  }

  const body = document.body;
  const printClass = `printing-${documentType}`;
  const previousClass = [...body.classList].find((className) => className.startsWith("printing-"));
  if (previousClass) body.classList.remove(previousClass);
  body.classList.add(printClass);

  const clearPrintClass = () => {
    body.classList.remove(printClass);
    window.removeEventListener("afterprint", clearPrintClass);
  };
  window.addEventListener("afterprint", clearPrintClass, { once: true });
  window.print();
}

export function formatMoney(amount, currency = "KES") {
  return `${currency} ${Number(amount || 0).toLocaleString()}`;
}

export function formatCurrencyTotals(entries) {
  const totals = new Map();
  entries.forEach((entry) => {
    const currency = entry.currency || "KES";
    totals.set(currency, (totals.get(currency) || 0) + Number(entry.amount || entry.salePrice || 0));
  });
  return Array.from(totals, ([currency, amount]) => formatMoney(amount, currency)).join(" · ") || formatMoney(0);
}

export function formatDate(date) {
  if (!date) return "—";
  return new Date(date).toLocaleDateString(undefined, {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

export function getPropertyImages(property) {
  if (property?.images?.length) return property.images;
  if (property?.primary_image_url) return [property.primary_image_url];
  return [];
}

export function sameListingType(first, second) {
  const normalize = (value) => (value?.toLowerCase() === "bnb" ? "bnb" : value?.toLowerCase());
  return normalize(first) === normalize(second);
}

export function getMatches(property, enquiries = []) {
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
    const typeMatch =
      !enquiry.propertyType && !enquiry.type
        ? true
        : (enquiry.propertyType || enquiry.type || "").toLowerCase() === "any" ||
          (enquiry.propertyType || enquiry.type || "").toLowerCase() === (property.type || "").toLowerCase();
    const listingMatch = !enquiry.listingType || sameListingType(enquiry.listingType, property.listingType);
    const price = Number(property.price);
    const minBudget = Number(enquiry.budgetMin || enquiry.minPrice || 0);
    const maxBudget = Number(enquiry.budgetMax || enquiry.maxPrice || 0);
    const budgetMatch =
      (!enquiry.currency || !property.currency || property.currency === enquiry.currency) &&
      (!minBudget || price >= minBudget) &&
      (!maxBudget || price <= maxBudget);

    return locationMatch && countryMatch && typeMatch && listingMatch && budgetMatch;
  });
}
