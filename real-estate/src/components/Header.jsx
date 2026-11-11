import { useState } from "react";
import { Bell, Building2, ChevronDown, LogOut, Menu, Settings, Trash2, UserRound } from "lucide-react";
import CompanyProfileModal from "./CompanyProfileModal";
import DeleteAccountModal from "./DeleteAccountModal";
import { companyLogoUrl } from "../api/companyProfile";

function Header({
  onToggleSidebar,
  onOpenNotifications,
  notificationCount = 0,
  user,
  onLogout,
  onNotify,
  companyProfile,
  onSaveCompanyProfile,
}) {
  const [profileMenuOpen, setProfileMenuOpen] = useState(false);
  const [companyProfileOpen, setCompanyProfileOpen] = useState(false);
  const [deleteModalOpen, setDeleteModalOpen] = useState(false);
  const logoUrl = companyLogoUrl(companyProfile?.logo_url);

  function handleDeletionSuccess(message) {
    setDeleteModalOpen(false);
    if (onNotify) {
      onNotify(message);
    }
    if (onLogout) {
      onLogout();
    }
  }

  return (
    <>
      <header className="relative z-30 flex h-20 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6">
        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={onToggleSidebar}
            className="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 text-slate-700 transition hover:bg-slate-100 md:hidden"
            aria-label="Toggle sidebar"
          >
            <Menu size={20} />
          </button>

          <div>
            <p className="text-sm text-slate-500">Welcome back</p>
            <h2 className="text-xl font-bold text-[#0F2A43]">
              Real Estate Dashboard
            </h2>
          </div>
        </div>

        <div className="flex items-center gap-3 sm:gap-4">
          <button
            type="button"
            onClick={onOpenNotifications}
            aria-label={`${notificationCount} notifications, open activity`}
            className="relative rounded-lg p-2 text-slate-500 transition hover:bg-slate-100"
          >
            <Bell size={20} />
            {notificationCount > 0 && (
              <span className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-[#D4A72C] px-1 text-[9px] font-bold text-[#0F2A43]">
                {notificationCount > 9 ? "9+" : notificationCount}
              </span>
            )}
          </button>

          <div className="relative">
            <button
              type="button"
              onClick={() => setProfileMenuOpen((open) => !open)}
              aria-expanded={profileMenuOpen}
              aria-label="Open account profile menu"
              className="flex items-center gap-2 rounded-xl p-1.5 transition hover:bg-slate-50"
            >
              {logoUrl ? (
                <img
                  src={logoUrl}
                  alt=""
                  className="h-9 w-9 rounded-full border border-slate-200 bg-white object-contain p-1"
                />
              ) : (
                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-[#D4A72C] font-bold text-white">
                  {user.name.slice(0, 1).toUpperCase()}
                </span>
              )}
              <span className="hidden text-left sm:block">
                <span className="block text-sm font-semibold text-[#0F2A43]">{user.name}</span>
                <span className="block text-xs capitalize text-slate-500">{user.role}</span>
              </span>
              <ChevronDown size={15} className="hidden text-slate-400 sm:block" />
            </button>

            {profileMenuOpen && (
              <div className="absolute right-0 top-full z-[70] mt-2 w-72 rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl">
                <div className="flex items-center gap-3 border-b border-slate-100 px-3 py-3">
                  {logoUrl ? (
                    <img
                      src={logoUrl}
                      alt=""
                      className="h-10 w-10 rounded-lg border border-slate-100 object-contain p-1"
                    />
                  ) : (
                    <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-[#0F2A43] text-white">
                      <UserRound size={18} />
                    </span>
                  )}
                  <div className="min-w-0">
                    <p className="truncate text-sm font-bold text-[#0F2A43]">{user.name}</p>
                    <p className="truncate text-xs text-slate-500">{user.email}</p>
                    <span className="mt-1 inline-block rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-600">
                      {user.role}
                    </span>
                  </div>
                </div>

                <div className="py-1">
                  {user.role === "admin" && (
                    <button
                      type="button"
                      onClick={() => {
                        setProfileMenuOpen(false);
                        setCompanyProfileOpen(true);
                      }}
                      className="flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-left text-xs font-semibold text-slate-700 hover:bg-slate-50"
                    >
                      <Building2 size={16} className="text-[#0F2A43]" />
                      <span className="truncate">{companyProfile?.company_name || "Company profile"}</span>
                      <Settings size={14} className="ml-auto text-slate-400" />
                    </button>
                  )}
                </div>

                <div className="border-t border-slate-100 pt-1.5 space-y-1">
                  <p className="px-3 pt-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                    Account Actions
                  </p>
                  
                  {/* Action 1: Sign Out */}
                  <button
                    type="button"
                    onClick={() => {
                      setProfileMenuOpen(false);
                      onLogout();
                    }}
                    className="flex w-full items-start gap-2.5 rounded-xl px-3 py-2 text-left hover:bg-slate-50 transition"
                  >
                    <LogOut size={16} className="mt-0.5 text-slate-500 shrink-0" />
                    <div>
                      <p className="text-xs font-semibold text-slate-700">Sign out</p>
                      <p className="text-[11px] text-slate-400">End current session. Account and data remain intact.</p>
                    </div>
                  </button>

                  {/* Action 2: Delete Account */}
                  <button
                    type="button"
                    onClick={() => {
                      setProfileMenuOpen(false);
                      setDeleteModalOpen(true);
                    }}
                    className="flex w-full items-start gap-2.5 rounded-xl px-3 py-2 text-left hover:bg-red-50/70 transition group"
                  >
                    <Trash2 size={16} className="mt-0.5 text-red-600 shrink-0" />
                    <div>
                      <p className="text-xs font-semibold text-red-700">Delete account</p>
                      <p className="text-[11px] text-slate-400 group-hover:text-red-600/80">
                        Submit request to permanently close account &amp; anonymize data.
                      </p>
                    </div>
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      </header>

      {companyProfileOpen && (
        <CompanyProfileModal
          profile={companyProfile}
          onClose={() => setCompanyProfileOpen(false)}
          onSave={onSaveCompanyProfile}
        />
      )}

      {deleteModalOpen && (
        <DeleteAccountModal
          onClose={() => setDeleteModalOpen(false)}
          onDeletionSuccess={handleDeletionSuccess}
        />
      )}
    </>
  );
}

export default Header;
