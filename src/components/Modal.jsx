import { X } from "lucide-react";

function Modal({ title, description, onClose, children, size = "max-w-3xl" }) {
  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-3 backdrop-blur-sm sm:p-6"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="modal-title"
        className={`max-h-[92vh] w-full ${size} overflow-y-auto rounded-2xl bg-white shadow-2xl`}
      >
        <header className="sticky top-0 z-10 flex items-start justify-between gap-5 border-b border-slate-100 bg-white/95 p-5 backdrop-blur sm:p-6">
          <div>
            <h2 id="modal-title" className="text-xl font-bold text-[#0F2A43] sm:text-2xl">
              {title}
            </h2>
            {description && <p className="mt-1 text-sm text-slate-500">{description}</p>}
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close dialog"
            className="rounded-lg p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
          >
            <X size={20} />
          </button>
        </header>
        {children}
      </section>
    </div>
  );
}

export default Modal;
