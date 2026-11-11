import { useState } from "react";
import { AlertTriangle, Lock, ShieldAlert, Trash2 } from "lucide-react";
import Modal from "./Modal";
import { requestAccountDeletion } from "../api/auth";
import { inputClass } from "../lib/utils";

export default function DeleteAccountModal({ onClose, onDeletionSuccess }) {
  const [password, setPassword] = useState("");
  const [reason, setReason] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(event) {
    event.preventDefault();
    if (!password) {
      setError("Please confirm your password to proceed.");
      return;
    }

    setSubmitting(true);
    setError("");

    try {
      const res = await requestAccountDeletion({
        password,
        reason: reason.trim(),
      });

      if (res.success) {
        onDeletionSuccess(
          res.message ||
            "Your deletion request has been submitted. Your account is deactivated while administrators review records."
        );
      } else {
        setError(res.message || "Failed to submit deletion request. Please check your password.");
      }
    } catch (err) {
      setError(err?.message || "An error occurred while submitting your request.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      title="Delete GoldERP account"
      description="Submit a formal request to permanently retire your account and handle personal data."
      onClose={onClose}
      size="max-w-xl"
    >
      <form onSubmit={handleSubmit} className="space-y-5 p-5 sm:p-7">
        <div className="rounded-2xl border border-amber-200 bg-amber-50/80 p-4 text-xs leading-5 text-amber-950">
          <div className="flex items-start gap-2.5">
            <AlertTriangle className="mt-0.5 shrink-0 text-amber-700" size={18} />
            <div className="space-y-1.5">
              <p className="font-bold text-amber-900">
                Important: Account deletion &amp; statutory recordkeeping
              </p>
              <p>
                GoldERP Real Estate preserves legal and financial integrity. Deleting your account does not perform an immediate cascade deletion:
              </p>
              <ul className="list-disc pl-4 space-y-1 text-amber-900/90">
                <li>
                  <strong>Immediate Deactivation:</strong> Your account is locked immediately and you will not be able to log in.
                </li>
                <li>
                  <strong>Administrator Review:</strong> An administrator will assess outstanding commitments (active property listings, sales agreements, installment balances, or administrator responsibilities).
                </li>
                <li>
                  <strong>Personal Data Anonymization:</strong> Once verified, your personal contact information and identity are permanently anonymized in compliance with Kenyan data protection guidelines.
                </li>
                <li>
                  <strong>Preserved History:</strong> Property transaction history, official receipts, and audit trails remain preserved for legitimate accounting and statutory recordkeeping.
                </li>
              </ul>
            </div>
          </div>
        </div>

        {error && (
          <div className="flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 p-3.5 text-xs font-medium text-red-700">
            <ShieldAlert size={16} className="shrink-0" />
            <span>{error}</span>
          </div>
        )}

        <label className="block text-sm font-semibold text-slate-700">
          Confirm Your Password <span className="text-red-500">*</span>
          <div className="relative mt-2">
            <Lock size={16} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Enter your current password"
              required
              autoFocus
              className={`${inputClass} pl-10`}
            />
          </div>
        </label>

        <label className="block text-sm font-semibold text-slate-700">
          Reason for Account Deletion <span className="font-normal text-slate-400">(optional)</span>
          <textarea
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            rows={3}
            placeholder="Tell us why you are deleting your account (e.g. changing firm, retirement, duplicate account)..."
            className={`${inputClass} mt-2`}
          />
        </label>

        <div className="flex flex-col-reverse sm:flex-row justify-end gap-3 border-t border-slate-100 pt-5">
          <button
            type="button"
            onClick={onClose}
            disabled={submitting}
            className="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={submitting || !password}
            className="inline-flex items-center justify-center gap-2 rounded-xl bg-red-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50"
          >
            <Trash2 size={16} />
            {submitting ? "Submitting request..." : "Deactivate & submit deletion request"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
