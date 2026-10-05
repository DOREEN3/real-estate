import { useState } from "react";
import { Building2, KeyRound, LogIn } from "lucide-react";
import { demoUsers } from "../data/demoUsers";
import { inputClass } from "../lib/demo";

function DemoLogin({ onLogin }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");

  function handleSubmit(event) {
    event.preventDefault();
    const account = demoUsers.find((user) =>
      user.email.toLowerCase() === email.trim().toLowerCase() && user.password === password
    );

    if (!account) {
      setError("Those demo credentials were not recognized. Choose an account below to try again.");
      return;
    }

    setError("");
    onLogin({ name: account.name, email: account.email, role: account.role, id: account.id });
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-[#f6f7f9] px-4 py-10">
      <div className="grid w-full max-w-5xl overflow-hidden rounded-3xl bg-white shadow-xl md:grid-cols-[1fr_1.1fr]">
        <section className="flex flex-col justify-between bg-[#0F2A43] p-8 text-white sm:p-10">
          <div>
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#D4A72C] text-[#0F2A43]"><Building2 size={25} /></div>
            <p className="mt-8 text-xs font-bold uppercase tracking-[0.2em] text-[#eac95f]">GoldERP · Demo</p>
            <h1 className="mt-3 text-3xl font-bold sm:text-4xl">Real estate, all in one place.</h1>
            <p className="mt-4 max-w-sm text-sm leading-6 text-slate-300">Sign in as an administrator, agent or property owner to explore the role-specific demo experience.</p>
          </div>
          <p className="mt-10 text-xs leading-5 text-slate-400">Demo access only. Accounts and data are stored in this browser; this is not production authentication.</p>
        </section>

        <section className="p-6 sm:p-10">
          <div className="mb-7">
            <h2 className="text-2xl font-bold text-[#0F2A43]">Welcome back</h2>
            <p className="mt-1 text-sm text-slate-500">Sign in with one of the demo accounts.</p>
          </div>

          <form onSubmit={handleSubmit} className="space-y-4">
            <label className="block text-sm font-semibold text-slate-700">Email
              <input type="email" autoComplete="username" required value={email} onChange={(event) => setEmail(event.target.value)} className={`${inputClass} mt-2`} placeholder="you@example.com" />
            </label>
            <label className="block text-sm font-semibold text-slate-700">Password
              <input type="password" autoComplete="current-password" required value={password} onChange={(event) => setPassword(event.target.value)} className={`${inputClass} mt-2`} placeholder="Enter demo password" />
            </label>
            {error && <p role="alert" className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}
            <button type="submit" className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#173e60]"><LogIn size={17} /> Sign in</button>
          </form>

          <div className="mt-8 border-t border-slate-100 pt-6">
            <h3 className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-slate-500"><KeyRound size={14} /> Demo accounts</h3>
            <div className="mt-3 space-y-2">
              {demoUsers.map((user) => (
                <button key={user.email} type="button" onClick={() => { setEmail(user.email); setPassword(user.password); setError(""); }} className="flex w-full items-center justify-between rounded-xl border border-slate-200 px-4 py-3 text-left transition hover:border-[#D4A72C] hover:bg-amber-50/40">
                  <span><span className="block text-sm font-semibold text-[#0F2A43]">{user.name} · {user.role}</span><span className="mt-0.5 block text-xs text-slate-500">{user.email} · {user.password}</span></span>
                  <span className="text-xs font-semibold text-[#876509]">Use</span>
                </button>
              ))}
            </div>
          </div>
        </section>
      </div>
    </main>
  );
}

export default DemoLogin;
