import {
  LayoutDashboard,
  Building2,
  MessageSquare,
  Users,
  UserRound,
  HandCoins,
  KeyRound,
  CreditCard,
  BellRing,
  X,
} from "lucide-react";

function Sidebar({ activePage, setActivePage, isOpen, onClose, role }) {
  const allMenuItems = [
    { name: "Dashboard", icon: LayoutDashboard },
    { name: "Properties", icon: Building2 },
    { name: "Enquiries", icon: MessageSquare },
    { name: "Agents", icon: Users },
    { name: "Owners", icon: UserRound },
    { name: "Rentals", icon: KeyRound },
    { name: "Sales", icon: HandCoins },
    { name: "Payments", icon: CreditCard },
    { name: "Notifications", icon: BellRing },
  ];
  const rolePages = {
    admin: allMenuItems.map((item) => item.name),
    agent: ["Dashboard", "Properties", "Enquiries", "Rentals", "Sales", "Notifications"],
    owner: ["Dashboard", "Properties", "Enquiries", "Rentals", "Notifications"],
  };
  const menuItems = allMenuItems.filter((item) => rolePages[role]?.includes(item.name));

  function handleSelect(itemName) {
    setActivePage(itemName);
    onClose();
  }

  return (
    <>
      <div
        className={`fixed inset-0 z-40 bg-black/40 transition-opacity duration-200 md:hidden ${
          isOpen ? "opacity-100" : "pointer-events-none opacity-0"
        }`}
        onClick={onClose}
      />

      <aside
        className={`app-sidebar fixed inset-y-0 left-0 z-50 w-64 bg-[#0F2A43] text-white transition-transform duration-200 ease-in-out ${
          isOpen ? "is-open" : ""
        }`}
      >
        <div className="flex items-center justify-between border-b border-white/10 p-6">
          <div>
            <h1 className="text-2xl font-bold text-[#D4A72C]">
              GOLD<span className="">ERP</span>
            </h1>

            <p className="mt-1 text-xs text-slate-300">Real Estate Management</p>
          </div>

          <button
            type="button"
            onClick={onClose}
            className="rounded-lg p-2 text-slate-300 transition hover:bg-white/10 hover:text-white md:hidden"
            aria-label="Close sidebar"
          >
            <X size={18} />
          </button>
        </div>

        <nav className="max-h-[calc(100vh-112px)] overflow-y-auto p-4">
          <p className="mb-3 px-3 text-xs font-semibold uppercase tracking-wider text-slate-400">
            Main Menu
          </p>

          <div className="space-y-1">
            {menuItems.map((item) => {
              const Icon = item.icon;

              return (
                <button
                  key={item.name}
                  onClick={() => handleSelect(item.name)}
                  className={`flex w-full items-center gap-3 rounded-lg px-4 py-3 text-left font-medium transition ${
                    activePage === item.name
                      ? "bg-[#D4A72C] text-white"
                      : "text-slate-300 hover:bg-white/10 hover:text-white"
                  }`}
                >
                  <Icon size={20} strokeWidth={1.8} />
                  <span>{item.name}</span>
                </button>
              );
            })}
          </div>
        </nav>
      </aside>
    </>
  );
}

export default Sidebar;