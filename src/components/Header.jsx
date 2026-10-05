import { Bell, Menu } from "lucide-react";

function Header({ onToggleSidebar, onOpenNotifications, notificationCount = 0, user, onLogout }) {
  return (
    <header className="flex h-20 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6">
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
          {notificationCount > 0 && <span className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-[#D4A72C] px-1 text-[9px] font-bold text-[#0F2A43]">{notificationCount > 9 ? "9+" : notificationCount}</span>}
        </button>

        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-[#D4A72C] font-bold text-white">
            {user.name.slice(0, 1).toUpperCase()}
          </div>

          <div className="hidden sm:block">
            <p className="text-sm font-semibold text-[#0F2A43]">{user.name}</p>
            <p className="text-xs capitalize text-slate-500">{user.role}</p>
          </div>
        </div>
        <button type="button" onClick={onLogout} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-100">Sign out</button>
      </div>
    </header>
  );
}

export default Header;