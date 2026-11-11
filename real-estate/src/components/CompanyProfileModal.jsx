import { useEffect, useState } from "react";
import { Building2, ImagePlus, Mail, MapPin, Phone, Receipt, Save, Upload, X } from "lucide-react";
import Modal from "./Modal";
import { companyLogoUrl } from "../api/companyProfile";
import { inputClass } from "../lib/utils";

export default function CompanyProfileModal({ profile, onClose, onSave }) {
  const [form, setForm] = useState({
    company_name: profile?.company_name || "",
    physical_address: profile?.physical_address || "",
    city: profile?.city || "",
    phone_number: profile?.phone_number || "",
    whatsapp_number: profile?.whatsapp_number || "",
    email: profile?.email || "",
    kra_pin: profile?.kra_pin || "",
  });
  const [logoFile, setLogoFile] = useState(null);
  const [previewUrl, setPreviewUrl] = useState("");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!logoFile) return undefined;
    const reader = new FileReader();
    reader.onload = () => setPreviewUrl(String(reader.result || ""));
    reader.onerror = () => setError("Unable to preview this logo file.");
    reader.readAsDataURL(logoFile);
    return () => reader.abort();
  }, [logoFile]);

  async function submit(event) {
    event.preventDefault();
    setError("");
    const name = form.company_name.trim();
    if (name.length < 2 || name.length > 150) {
      setError("Company name must be between 2 and 150 characters.");
      return;
    }
    if (
      logoFile &&
      (!["image/jpeg", "image/png", "image/webp"].includes(logoFile.type) ||
        logoFile.size > 3 * 1024 * 1024)
    ) {
      setError("Choose a JPEG, PNG, or WebP logo up to 3 MB.");
      return;
    }

    setSaving(true);
    let saved = false;
    try {
      const result = await onSave({
        ...form,
        company_name: name,
        kra_pin: form.kra_pin.trim().toUpperCase(),
        logoFile,
      });
      if (!result?.success) {
        setError(result?.message || "Unable to update company profile.");
      } else {
        saved = true;
      }
    } catch (saveError) {
      setError(saveError.message || "Unable to update company profile.");
    } finally {
      setSaving(false);
    }
    if (saved) onClose();
  }

  const currentLogo = previewUrl || companyLogoUrl(profile?.logo_url);

  return (
    <Modal
      title="Company & Branding Profile"
      description="Update your business identity, physical address, and KRA PIN printed on official receipts and statements."
      onClose={onClose}
      size="max-w-xl"
    >
      <form onSubmit={submit} className="space-y-4 p-5 sm:p-7 max-h-[75vh] overflow-y-auto">
        {error && (
          <div role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {error}
          </div>
        )}

        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block text-sm font-semibold text-slate-700 sm:col-span-2">
            Registered Business Name *
            <input
              required
              maxLength={150}
              value={form.company_name}
              onChange={(e) => setForm({ ...form, company_name: e.target.value })}
              className={`${inputClass} mt-1`}
              placeholder="e.g. GoldERP Real Estate Limited"
            />
          </label>

          <label className="block text-sm font-semibold text-slate-700">
            KRA PIN / Tax ID
            <input
              maxLength={50}
              value={form.kra_pin}
              onChange={(e) => setForm({ ...form, kra_pin: e.target.value })}
              className={`${inputClass} mt-1 uppercase font-mono`}
              placeholder="e.g. P051234567X"
            />
            <span className="mt-1 block text-[11px] text-slate-400">Printed on official tax receipts & invoices</span>
          </label>

          <label className="block text-sm font-semibold text-slate-700">
            City / Town
            <input
              maxLength={100}
              value={form.city}
              onChange={(e) => setForm({ ...form, city: e.target.value })}
              className={`${inputClass} mt-1`}
              placeholder="e.g. Nairobi, Kenya"
            />
          </label>

          <label className="block text-sm font-semibold text-slate-700 sm:col-span-2">
            Physical Office Address
            <input
              maxLength={255}
              value={form.physical_address}
              onChange={(e) => setForm({ ...form, physical_address: e.target.value })}
              className={`${inputClass} mt-1`}
              placeholder="e.g. Suite 402, Westlands Commercial Centre, Ring Road"
            />
          </label>

          <label className="block text-sm font-semibold text-slate-700">
            Official Phone Number
            <input
              maxLength={50}
              value={form.phone_number}
              onChange={(e) => setForm({ ...form, phone_number: e.target.value })}
              className={`${inputClass} mt-1`}
              placeholder="e.g. +254 700 000 000"
            />
          </label>

          <label className="block text-sm font-semibold text-slate-700">
            WhatsApp Support Number
            <input
              maxLength={50}
              value={form.whatsapp_number}
              onChange={(e) => setForm({ ...form, whatsapp_number: e.target.value })}
              className={`${inputClass} mt-1`}
              placeholder="e.g. +254 711 000 000"
            />
          </label>

          <label className="block text-sm font-semibold text-slate-700 sm:col-span-2">
            Official Billing / Support Email
            <input
              type="email"
              maxLength={150}
              value={form.email}
              onChange={(e) => setForm({ ...form, email: e.target.value })}
              className={`${inputClass} mt-1`}
              placeholder="e.g. accounts@golderp.co.ke"
            />
          </label>
        </div>

        <div>
          <p className="mb-2 text-sm font-semibold text-slate-700">Business Logo</p>
          <div className="flex items-center gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
            {currentLogo ? (
              <img src={currentLogo} alt="Company logo preview" className="h-16 w-16 rounded-lg bg-white object-contain p-1 shadow-xs" />
            ) : (
              <span className="flex h-16 w-16 items-center justify-center rounded-lg bg-white text-[#0F2A43] shadow-xs">
                <Building2 size={26} />
              </span>
            )}
            <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-[#0F2A43] hover:bg-slate-50">
              <ImagePlus size={16} /> {logoFile ? "Change logo image" : "Upload logo image"}
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="sr-only"
                onChange={(event) => setLogoFile(event.target.files?.[0] || null)}
              />
            </label>
          </div>
          <p className="mt-1 text-xs text-slate-400">JPEG, PNG, or WebP up to 3 MB. Transparent background recommended.</p>
        </div>

        <div className="flex justify-end gap-3 border-t border-slate-100 pt-4">
          <button
            type="button"
            onClick={onClose}
            className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50"
          >
            <X size={15} /> Cancel
          </button>
          <button
            type="submit"
            disabled={saving}
            className="inline-flex items-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#173e60] disabled:opacity-60"
          >
            {saving ? <Upload size={15} className="animate-pulse" /> : <Save size={15} />}
            {saving ? "Saving profile..." : "Save company profile"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
