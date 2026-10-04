import { useState } from "react";
import { ImagePlus, MapPin } from "lucide-react";
import Modal from "./Modal";
import { inputClass } from "../lib/demo";

const blankProperty = {
  title: "",
  type: "House",
  listingType: "For Sale",
  price: "",
  currency: "KSH",
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
  listedBy: "Admin",
  agentId: "",
  ownerId: "",
};

function PropertyEditor({ onClose, onSave, agents, owners, initialValues = {} }) {
  const [form, setForm] = useState(() => ({ ...blankProperty, ...initialValues }));
  const [photos, setPhotos] = useState([]);
  const [photoError, setPhotoError] = useState("");

  function handleChange(event) {
    const { name, value } = event.target;
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

  function handleSubmit(event) {
    event.preventDefault();
    onSave({ ...form, images: photos });
  }

  return (
    <Modal
      title="Add a property"
      description="Create a detailed listing. Verified agents and owners can publish directly."
      onClose={onClose}
      size="max-w-4xl"
    >
      <form onSubmit={handleSubmit} className="space-y-6 p-5 sm:p-7">
        <section className="grid gap-4 sm:grid-cols-2">
          <label className="text-sm font-semibold text-slate-700 sm:col-span-2">
            Property title
            <input name="title" value={form.title} onChange={handleChange} required className={`${inputClass} mt-2`} placeholder="e.g. Garden villa near the coast" />
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Property type
            <select name="type" value={form.type} onChange={handleChange} className={`${inputClass} mt-2`}>
              {["House", "Apartment", "Villa", "Studio", "Townhouse", "Commercial", "Land"].map((type) => <option key={type}>{type}</option>)}
            </select>
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Listing type
            <select name="listingType" value={form.listingType} onChange={handleChange} className={`${inputClass} mt-2`}>
              {["For Sale", "For Rent", "BnB"].map((type) => <option key={type}>{type}</option>)}
            </select>
          </label>
          <div className="text-sm font-semibold text-slate-700">
            <label htmlFor="property-price">Price</label>
            <div className="mt-2 flex gap-2">
              <select aria-label="Price currency" name="currency" value={form.currency} onChange={handleChange} className={`${inputClass} w-28 shrink-0`}>
                {["KSH", "USD", "EUR", "GBP", "AED"].map((currency) => <option key={currency}>{currency}</option>)}
              </select>
              <input id="property-price" aria-label="Price amount" type="number" name="price" min="0" value={form.price} onChange={handleChange} required className={inputClass} placeholder="e.g. 1850000" />
            </div>
          </div>
          <label className="text-sm font-semibold text-slate-700">
            Status
            <select name="status" value={form.status} onChange={handleChange} className={`${inputClass} mt-2`}>
              {["Available", "Reserved", "Sold", "Rented"].map((status) => <option key={status}>{status}</option>)}
            </select>
          </label>
        </section>

        <section className="rounded-2xl border border-slate-100 bg-slate-50/70 p-4 sm:p-5">
          <h3 className="mb-4 flex items-center gap-2 font-bold text-[#0F2A43]"><MapPin size={18} /> Location and map pin</h3>
          <div className="grid gap-3 sm:grid-cols-3">
            <input name="country" value={form.country} onChange={handleChange} required className={inputClass} placeholder="Country" aria-label="Country" />
            <input name="city" value={form.city} onChange={handleChange} required className={inputClass} placeholder="City" aria-label="City" />
            <input name="area" value={form.area} onChange={handleChange} className={inputClass} placeholder="Area / neighbourhood" aria-label="Area" />
            <input type="number" step="any" min="-90" max="90" name="latitude" value={form.latitude} onChange={handleChange} className={inputClass} placeholder="Latitude" aria-label="Latitude" />
            <input type="number" step="any" min="-180" max="180" name="longitude" value={form.longitude} onChange={handleChange} className={inputClass} placeholder="Longitude" aria-label="Longitude" />
            <p className="self-center text-xs leading-5 text-slate-500 sm:px-2">Paste coordinates from Google Maps to save a map pin.</p>
          </div>
        </section>

        <section className="grid gap-4 sm:grid-cols-3">
          <label className="text-sm font-semibold text-slate-700">Bedrooms<input type="number" min="0" name="bedrooms" value={form.bedrooms} onChange={handleChange} className={`${inputClass} mt-2`} /></label>
          <label className="text-sm font-semibold text-slate-700">Bathrooms<input type="number" min="0" name="bathrooms" value={form.bathrooms} onChange={handleChange} className={`${inputClass} mt-2`} /></label>
          <label className="text-sm font-semibold text-slate-700">Floor area / size<input name="size" value={form.size} onChange={handleChange} className={`${inputClass} mt-2`} placeholder="450 sqm" /></label>
        </section>

        <label className="block text-sm font-semibold text-slate-700">
          Short description
          <textarea name="description" value={form.description} onChange={handleChange} required rows="3" className={`${inputClass} mt-2 resize-y`} placeholder="What makes this property special?" />
        </label>

        <section>
          <label htmlFor="property-photos" className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-700"><ImagePlus size={17} /> Property photos <span className="font-normal text-slate-400">(up to 5)</span></label>
          <input id="property-photos" type="file" accept="image/*" multiple onChange={handlePhotos} className={`${inputClass} file:mr-4 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-xs file:font-semibold`} />
          {photoError && <p role="alert" className="mt-2 text-sm text-red-700">{photoError}</p>}
          {photos.length > 0 && <div className="mt-3 flex flex-wrap gap-2">{photos.map((photo, index) => <img key={index} src={photo} alt={`Selected property ${index + 1}`} className="h-16 w-20 rounded-lg object-cover" />)}</div>}
          <p className="mt-2 text-xs text-slate-400">Listing contact requests are routed to our team. External email and messaging are simulated in this demo.</p>
        </section>

        <section className="grid gap-4 sm:grid-cols-3">
          <label className="text-sm font-semibold text-slate-700">
            Listed by
            <select name="listedBy" value={form.listedBy} onChange={handleChange} className={`${inputClass} mt-2`}>
              <option>Admin</option><option>Verified agent</option><option>Verified owner</option>
            </select>
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Agent
            <select name="agentId" value={form.agentId} onChange={handleChange} required={form.listedBy === "Verified agent"} className={`${inputClass} mt-2`}>
              <option value="">Select agent</option>
              {agents.filter((agent) => agent.verified).map((agent) => <option key={agent.id} value={agent.id}>{agent.name}</option>)}
            </select>
          </label>
          <label className="text-sm font-semibold text-slate-700">
            Owner
            <select name="ownerId" value={form.ownerId} onChange={handleChange} required={form.listedBy === "Verified owner"} className={`${inputClass} mt-2`}>
              <option value="">Select owner</option>
              {owners.filter((owner) => owner.verified).map((owner) => <option key={owner.id} value={owner.id}>{owner.name}</option>)}
            </select>
          </label>
        </section>

        <div className="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
          <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">Cancel</button>
          <button type="submit" className="rounded-xl bg-[#0F2A43] px-6 py-3 text-sm font-semibold text-white transition hover:bg-[#173e60]">Save property</button>
        </div>
      </form>
    </Modal>
  );
}

export default PropertyEditor;
