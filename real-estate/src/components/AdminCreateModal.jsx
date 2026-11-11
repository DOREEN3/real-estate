import { useState } from "react";
import { KeyRound } from "lucide-react";
import Modal from "./Modal";
import { inputClass } from "../lib/utils";
import { validateEmail, validatePassword } from "../lib/validation";

export default function AdminCreateModal({ onClose, onCreateAdmin }) {
  const [form, setForm] = useState({
    full_name: "",
    email: "",
    password: "",
  });
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  async function submit(event) {
    event.preventDefault();
    setError("");

    if (!form.full_name.trim() || form.full_name.trim().length < 2) {
      setError("Full name must be at least 2 characters.");
      return;
    }
    const emailErr = validateEmail(form.email);
    if (emailErr) {
      setError(emailErr);
      return;
    }
    const passErr = validatePassword(form.password);
    if (passErr) {
      setError(passErr);
      return;
    }

    setSubmitting(true);
    try {
      const res = await onCreateAdmin({
        full_name: form.full_name.trim(),
        email: form.email.trim(),
        password: form.password,
      });
      if (res?.success) {
        onClose();
      } else {
        setError(res?.message || "Unable to create administrator account.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      title="Register New Administrator"
      description="Create an approved administrator account with full access to the management portal."
      onClose={onClose}
      size="max-w-lg"
    >
      <form onSubmit={submit} className="space-y-4 p-5 sm:p-7">
        {error && (
          <div role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-medium text-red-700">
            {error}
          </div>
        )}

        <label className="block text-sm font-semibold text-slate-700">
          Administrator Full Name
          <input
            type="text"
            value={form.full_name}
            onChange={(event) => setForm({ ...form, full_name: event.target.value })}
            required
            placeholder="e.g. Samuel Kamau"
            className={`${inputClass} mt-2`}
          />
        </label>

        <label className="block text-sm font-semibold text-slate-700">
          Work Email Address
          <input
            type="email"
            value={form.email}
            onChange={(event) => setForm({ ...form, email: event.target.value })}
            required
            placeholder="admin@example.com"
            className={`${inputClass} mt-2`}
          />
        </label>

        <label className="block text-sm font-semibold text-slate-700">
          Temporary / Initial Password
          <input
            type="password"
            value={form.password}
            onChange={(event) => setForm({ ...form, password: event.target.value })}
            required
            placeholder="At least 8 characters with uppercase, number & symbol"
            className={`${inputClass} mt-2`}
          />
        </label>

        <p className="text-xs leading-5 text-slate-500">
          Administrator accounts can only be created by an authenticated administrator.
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
            disabled={submitting}
            className="inline-flex items-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white hover:bg-[#173e60] disabled:opacity-60"
          >
            <KeyRound size={16} /> {submitting ? "Creating..." : "Create Administrator"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
