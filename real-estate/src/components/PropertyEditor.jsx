import { useState } from "react";
import { FileCheck2, ImagePlus, MapPin } from "lucide-react";
import Modal from "./Modal";
import { inputClass } from "../lib/utils";

const blankProperty = {
  title: "",
  type: "House",
  listingType: "For Sale",
  price: "",
  currency: "KES",
  country: "Kenya",
  city: "",
  area: "",
  latitude: "",
  longitude: "",
  description: "",
  bedrooms: "",
  bathrooms: "",
  size: "",
  status: "Available",
  agentId: "",
  ownerId: "",
};

function PropertyEditor({
  onClose,
  onSave,
  agents = [],
  owners = [],
  initialValues = {},
  isEditing = false,
  userRole = "admin",
}) {
  const [form, setForm] = useState(() => ({
    ...blankProperty,
    ...initialValues,
    type: initialValues?.type || blankProperty.type,
    listingType: initialValues?.listingType || blankProperty.listingType,
    currency: initialValues?.currency || blankProperty.currency,
    country: initialValues?.country || blankProperty.country,
    status: initialValues?.status || blankProperty.status,
  }));
  const [photos, setPhotos] = useState(initialValues?.images || []);
  const [verificationDoc, setVerificationDoc] = useState(null);
  const [photoError, setPhotoError] = useState("");
  const [formError, setFormError] = useState("");

  function handleChange(event) {
    const { name, value } = event.target;
    if (formError) setFormError("");
    setForm((current) => ({ ...current, [name]: value }));
  }

  async function readPhoto(file) {
    const source = await new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result);
      reader.onerror = () => reject(reader.error);
      reader.readAsDataURL(file);
    });
    const image = new Image();
    await new Promise((resolve, reject) => {
      image.onload = resolve;
      image.onerror = reject;
      image.src = source;
    });
    const scale = Math.min(1, 1280 / image.width);
    const canvas = document.createElement("canvas");
    canvas.width = Math.round(image.width * scale);
    canvas.height = Math.round(image.height * scale);
    const context = canvas.getContext("2d");
    if (!context) throw new Error("Unable to resize the selected photo.");
    context.drawImage(image, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL("image/webp", 0.72);
  }

  async function handlePhotos(event) {
    const files = Array.from(event.target.files || []).slice(0, 5);
    setPhotoError("");
    try {
      const images = await Promise.all(files.map(readPhoto));
      setPhotos(images);
    } catch (error) {
      console.error("Unable to read the selected property photos.", error);
      setPhotoError("One or more photos could not be read. Try another image file.");
    }
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setFormError("");

    const cleanTitle = String(form.title || "").trim();
    if (cleanTitle.length < 3) {
      setFormError("Please enter a property title (at least 3 characters).");
      return;
    }

    const cleanPriceStr = String(form.price ?? "")
      .replace(/,/g, "")
      .replace(/[^\d.]/g, "")
      .trim();
    const numericPrice = Number(cleanPriceStr);
    if (!cleanPriceStr || !Number.isFinite(numericPrice) || numericPrice <= 0) {
      setFormError("Please enter a valid property price greater than 0.");
      return;
    }

    const result = await onSave({
      ...form,
      title: cleanTitle,
      price: String(numericPrice),
      images: photos,
      verificationDoc,
    });
    if (result && result.success === false && result.message) {
      setFormError(result.message);
    }
  }

  return (
    <Modal
      title={isEditing ? "Edit property" : "Add a property"}
      description={
        isEditing
          ? userRole === "admin"
            ? "Update the details of your property listing."
            : "Update listing details. Changes to approved listings are submitted for administrator re-approval."
          : userRole === "admin"
            ? "Create a detailed listing and publish directly."
            : "Submit a detailed listing and ownership document for administrator review and approval."
      }
      onClose={onClose}
      size="max-w-4xl"
    >
      <form onSubmit={handleSubmit} noValidate className="space-y-6 p-5 sm:p-7">
        {formError && (
          <div role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
            {formError}
          </div>
        )}
        <section className="grid gap-4 sm:grid-cols-2">
          <label className="text-sm font-semibold text-slate-700 sm:col-span-2">
            Property title
            <input
              name="title"
              value={form.title}
              onChange={handleChange}
              className={`${inputClass} mt-2`}
              placeholder="e.g. Garden villa near the coast"
            />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Property type
            <select name="type" value={form.type} onChange={handleChange} className={`${inputClass} mt-2`}>
              {["House", "Apartment", "Villa", "Condo", "Townhouse", "Commercial", "Land"].map((type) => (
                <option key={type} value={type}>
                  {type}
                </option>
              ))}
            </select>
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Listing type
            <select name="listingType" value={form.listingType} onChange={handleChange} className={`${inputClass} mt-2`}>
              {["For Sale", "For Rent", "BnB"].map((type) => (
                <option key={type} value={type}>
                  {type}
                </option>
              ))}
            </select>
          </label>
          <div className="text-sm font-semibold text-slate-700">
            <label htmlFor="property-price">Price</label>
            <div className="mt-2 grid grid-cols-[6.5rem_1fr] gap-2">
              <select
                aria-label="Price currency"
                name="currency"
                value={form.currency}
                onChange={handleChange}
                className={inputClass}
              >
                {["KES", "USD", "EUR", "GBP", "AED"].map((currency) => (
                  <option key={currency} value={currency}>
                    {currency}
                  </option>
                ))}
              </select>
              <input
                id="property-price"
                aria-label="Price amount"
                type="text"
                inputMode="decimal"
                name="price"
                value={form.price}
                onChange={handleChange}
                className={inputClass}
                placeholder="Enter amount (e.g. 1850000)"
              />
            </div>
          </div>
          <label className="text-sm font-semibold text-slate-700">
            Status
            <select name="status" value={form.status} onChange={handleChange} className={`${inputClass} mt-2`}>
              {["Available", "Reserved", "Sold", "Rented", "Occupied", "Unavailable"].map((status) => (
                <option key={status} value={status}>
                  {status}
                </option>
              ))}
            </select>
          </label>
        </section>

        <section className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4 sm:p-5">
          <h3 className="mb-4 flex items-center gap-2 font-bold text-[#0F2A43]">
            <MapPin size={18} /> Location and map pin
          </h3>
          <div className="grid gap-3 sm:grid-cols-3">
            <input
              name="country"
              value={form.country}
              onChange={handleChange}
              className={inputClass}
              placeholder="Country"
              aria-label="Country"
            />
            <input
              name="city"
              value={form.city}
              onChange={handleChange}
              className={inputClass}
              placeholder="City"
              aria-label="City"
            />
            <input
              name="area"
              value={form.area}
              onChange={handleChange}
              className={inputClass}
              placeholder="Area / neighbourhood"
              aria-label="Area"
            />
            <input
              type="text"
              inputMode="decimal"
              name="latitude"
              value={form.latitude}
              onChange={handleChange}
              className={inputClass}
              placeholder="Latitude (optional)"
              aria-label="Latitude"
            />
            <input
              type="text"
              inputMode="decimal"
              name="longitude"
              value={form.longitude}
              onChange={handleChange}
              className={inputClass}
              placeholder="Longitude (optional)"
              aria-label="Longitude"
            />
            <p className="self-center text-xs leading-5 text-slate-500 sm:px-2">
              Paste coordinates from Google Maps to save a map pin.
            </p>
          </div>
        </section>

        <section className="grid gap-4 sm:grid-cols-3">
          <label className="text-sm font-semibold text-slate-700">
            Bedrooms
            <input
              type="text"
              inputMode="numeric"
              name="bedrooms"
              value={form.bedrooms}
              onChange={handleChange}
              className={`${inputClass} mt-2`}
            />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Bathrooms
            <input
              type="text"
              inputMode="numeric"
              name="bathrooms"
              value={form.bathrooms}
              onChange={handleChange}
              className={`${inputClass} mt-2`}
            />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Floor area / size (sq ft)
            <input
              name="size"
              value={form.size}
              onChange={handleChange}
              className={`${inputClass} mt-2`}
              placeholder="e.g. 2400"
            />
          </label>
        </section>

        <label className="block text-sm font-semibold text-slate-700">
          Short description
          <textarea
            name="description"
            value={form.description}
            onChange={handleChange}
            rows="3"
            className={`${inputClass} mt-2 resize-y`}
            placeholder="What makes this property special?"
          />
        </label>

        <section>
          <label
            htmlFor="property-photos"
            className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-700"
          >
            <ImagePlus size={17} /> Property photos <span className="font-normal text-slate-400">(up to 5)</span>
          </label>
          <input
            id="property-photos"
            type="file"
            accept="image/*"
            multiple
            onChange={handlePhotos}
            className={`${inputClass} file:mr-4 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-xs file:font-semibold`}
          />
          {photoError && (
            <p role="alert" className="mt-2 text-sm text-red-700">
              {photoError}
            </p>
          )}
          {photos.length > 0 && (
            <div className="mt-3 flex flex-wrap gap-2">
              {photos.map((photo, index) => (
                <img
                  key={index}
                  src={photo}
                  alt={`Selected property ${index + 1}`}
                  className="h-16 w-20 rounded-lg object-cover"
                />
              ))}
            </div>
          )}
          <p className="mt-2 text-xs text-slate-400">
            Listing enquiries and buyer contact requests are routed directly to our team.
          </p>
        </section>

        <section className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4 sm:p-5">
          <label
            htmlFor="property-verification-doc"
            className="mb-2 flex items-center gap-2 text-sm font-semibold text-[#0F2A43]"
          >
            <FileCheck2 size={17} /> Property verification document{" "}
            <span className="font-normal text-slate-500">(optional · PDF, JPG, PNG up to 10 MB)</span>
          </label>
          <input
            id="property-verification-doc"
            type="file"
            accept=".pdf,application/pdf,image/jpeg,image/png"
            onChange={(event) => {
              const file = event.target.files?.[0] || null;
              setVerificationDoc(file);
            }}
            className={`${inputClass} file:mr-4 file:rounded-lg file:border-0 file:bg-slate-200 file:px-3 file:py-2 file:text-xs file:font-semibold`}
          />
          {verificationDoc && (
            <p className="mt-2 text-xs font-semibold text-emerald-700">
              Selected document: {verificationDoc.name} ({Math.round(verificationDoc.size / 1024)} KB)
            </p>
          )}
          <p className="mt-2 text-xs text-slate-500">
            Attach a title deed, lease agreement, or ownership certificate to submit this property for document verification.
          </p>
        </section>

        {!isEditing && userRole === "admin" && (
          <section className="grid gap-4 sm:grid-cols-2">
            <label className="text-sm font-semibold text-slate-700">
              Owner on record
              <select
                name="ownerId"
                value={form.ownerId}
                onChange={handleChange}
                className={`${inputClass} mt-2`}
              >
                <option value="">Admin account</option>
                {owners
                  .filter((owner) => owner.verified)
                  .map((owner) => (
                    <option key={owner.id} value={owner.id}>
                      {owner.name}
                    </option>
                  ))}
              </select>
            </label>
            <label className="text-sm font-semibold text-slate-700">
              Managing agent <span className="font-normal text-slate-400">(optional)</span>
              <select
                name="agentId"
                value={form.agentId}
                onChange={handleChange}
                className={`${inputClass} mt-2`}
              >
                <option value="">No agent assigned</option>
                {agents
                  .filter((agent) => agent.verified)
                  .map((agent) => (
                    <option key={agent.id} value={agent.id}>
                      {agent.name}
                    </option>
                  ))}
              </select>
            </label>
            <p className="text-xs leading-5 text-slate-500 sm:col-span-2">
              Ownership and management are recorded separately. An assigned agent will not be marked as the owner unless selected as owner on record.
            </p>
          </section>
        )}

        <div className="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
          <button
            type="button"
            onClick={onClose}
            className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50"
          >
            Cancel
          </button>
          <button
            type="submit"
            className="rounded-xl bg-[#0F2A43] px-6 py-3 text-sm font-semibold text-white transition hover:bg-[#173e60]"
          >
            {isEditing ? "Save changes" : "Save property"}
          </button>
        </div>
      </form>
    </Modal>
  );
}

export default PropertyEditor;
