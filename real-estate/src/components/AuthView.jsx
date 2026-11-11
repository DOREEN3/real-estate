import { useState, useEffect } from "react";
import {
  AlertCircle,
  Building2,
  Check,
  CheckCircle2,
  Copy,
  ExternalLink,
  Eye,
  EyeOff,
  Loader2,
  LogIn,
  Send,
  ShieldCheck,
  UserPlus,
} from "lucide-react";
import {
  login,
  register,
  forgotPassword,
  getSetupStatus,
  resetPassword,
  setupInitialAdmin,
} from "../api/auth";
import { validateForm } from "../lib/validation";

const inputClass =
  "w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 outline-none transition focus:border-[#0F2A43] focus:ring-2 focus:ring-[#0F2A43]/10";

export default function AuthView({ onAuthSuccess }) {
  const [mode, setMode] = useState("signin");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [successMsg, setSuccessMsg] = useState("");
  const [adminExists, setAdminExists] = useState(true);

  // Form states
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [setupKey, setSetupKey] = useState("");
  const [resetToken, setResetToken] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [fullName, setFullName] = useState("");
  const [role, setRole] = useState("agent");
  const [invitedViaEmail, setInvitedViaEmail] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});
  const [devResetUrl, setDevResetUrl] = useState("");
  const [devNotice, setDevNotice] = useState("");
  const [copiedDev, setCopiedDev] = useState(false);

  // 1. Check URL parameters for email registration links (e.g. ?mode=register&role=agent&email=...)
  // 2. Check if any administrator exists in the database (for first-time setup after wiping demo data)
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const modeParam = params.get("mode") || params.get("register");
    const roleParam = params.get("role");
    const emailParam = params.get("email");
    const nameParam = params.get("name");
    const tokenParam = params.get("token");
    const hasResetToken = Boolean(tokenParam);

    const hasInviteParams =
      modeParam === "register" || roleParam === "agent" || roleParam === "owner";

    if (hasInviteParams) {
      setMode("register");
      setInvitedViaEmail(true);
    }
    if (roleParam === "agent" || roleParam === "owner") {
      setRole(roleParam);
    }
    if (emailParam) {
      setEmail(emailParam);
    }
    if (nameParam) {
      setFullName(nameParam);
    }
    if (tokenParam) {
      setResetToken(tokenParam);
      setMode("reset_password");
    }

    async function checkAdminSetup() {
      try {
        const res = await getSetupStatus();
        if (res.success && res.data && res.data.admin_exists === false) {
          setAdminExists(false);
          if (!hasInviteParams && !hasResetToken) {
            setMode("setup_admin");
          }
        } else {
          setAdminExists(true);
        }
      } catch {
        setAdminExists(true);
      }
    }
    checkAdminSetup();
  }, []);

  async function handleLogin(e) {
    e.preventDefault();
    setError("");
    setSuccessMsg("");

    const validation = validateForm(
      { email, password },
      {
        email: { required: true, email: true, label: "Email" },
        password: { required: true, label: "Password" },
      }
    );

    if (!validation.isValid) {
      setFieldErrors(validation.errors);
      return;
    }
    setFieldErrors({});
    setLoading(true);

    try {
      const res = await login(email, password);
      if (res.success && res.data?.user) {
        onAuthSuccess(res.data.user);
      } else {
        setError(res.message || "Invalid credentials. Please verify your email and password.");
      }
    } catch {
      setError("Unable to connect to authentication service.");
    } finally {
      setLoading(false);
    }
  }

  async function handleSetupAdmin(e) {
    e.preventDefault();
    setError("");
    setSuccessMsg("");

    const validation = validateForm(
      { full_name: fullName, email, password, setup_key: setupKey },
      {
        full_name: { required: true, minLength: 2, maxLength: 100, label: "Full Name" },
        email: { required: true, email: true, label: "Email" },
        password: { required: true, password: true, label: "Password" },
        setup_key: { required: true, label: "Administrator setup key" },
      }
    );

    if (!validation.isValid) {
      setFieldErrors(validation.errors);
      return;
    }
    setFieldErrors({});
    setLoading(true);

    try {
      const res = await setupInitialAdmin({
        full_name: fullName,
        email,
        password,
        setup_key: setupKey,
      });

      if (res.success) {
        setAdminExists(true);
        setSuccessMsg(
          res.message || "Primary administrator account created! Please sign in below."
        );
        setMode("signin");
        setPassword("");
        setSetupKey("");
      } else {
        setError(res.message || "Unable to create administrator account.");
      }
    } catch {
      setError("Setup service unavailable.");
    } finally {
      setLoading(false);
    }
  }

  async function handleRegister(e) {
    e.preventDefault();
    setError("");
    setSuccessMsg("");

    const validation = validateForm(
      { full_name: fullName, email, password, role },
      {
        full_name: { required: true, minLength: 2, maxLength: 100, label: "Full Name" },
        email: { required: true, email: true, label: "Email" },
        password: { required: true, password: true, label: "Password" },
        role: { required: true, label: "Role" },
      }
    );

    if (!validation.isValid) {
      setFieldErrors(validation.errors);
      return;
    }
    setFieldErrors({});
    setLoading(true);

    try {
      const res = await register({
        full_name: fullName,
        email,
        password,
        role,
      });

      if (res.success) {
        setSuccessMsg(
          res.message ||
            "Your registration has been submitted! Our administrator will verify your account and notify you by email."
        );
        setMode("signin");
        setInvitedViaEmail(false);
        setPassword("");
        if (window.history.replaceState) {
          window.history.replaceState({}, document.title, window.location.pathname);
        }
      } else {
        setError(res.message || "Registration failed. Please check the details.");
      }
    } catch {
      setError("Registration service unavailable.");
    } finally {
      setLoading(false);
    }
  }

  async function handleForgotPassword(e) {
    e.preventDefault();
    setError("");
    setSuccessMsg("");

    const validation = validateForm(
      { email },
      { email: { required: true, email: true, label: "Email" } }
    );

    if (!validation.isValid) {
      setFieldErrors(validation.errors);
      return;
    }
    setFieldErrors({});
    setLoading(true);

    try {
      const res = await forgotPassword(email);
      if (res.success) {
        setSuccessMsg(
          res.message || "If the account exists, a password reset link has been sent to your email."
        );
        if (res.data?.dev_reset_url) {
          setDevResetUrl(res.data.dev_reset_url);
          setDevNotice(res.data.mail_notice || "");
        } else {
          setDevResetUrl("");
          setDevNotice("");
        }
      } else {
        setError(res.message || "Unable to request password reset.");
        setDevResetUrl("");
        setDevNotice("");
      }
    } catch {
      setError("Unable to request password reset.");
      setDevResetUrl("");
      setDevNotice("");
    } finally {
      setLoading(false);
    }
  }

  async function handleResetPassword(event) {
    event.preventDefault();
    setError("");
    setSuccessMsg("");

    const validation = validateForm(
      { password, confirmPassword },
      {
        password: { required: true, password: true, label: "Password" },
        confirmPassword: { required: true, label: "Confirm password" },
      }
    );
    if (!validation.isValid) {
      setFieldErrors(validation.errors);
      return;
    }
    if (password !== confirmPassword) {
      setFieldErrors({ confirmPassword: "Passwords do not match." });
      return;
    }

    setFieldErrors({});
    setLoading(true);
    try {
      const res = await resetPassword(resetToken, password);
      if (res.success) {
        setSuccessMsg(res.message || "Password reset successfully. You can now sign in.");
        setMode("signin");
        setPassword("");
        setConfirmPassword("");
        setResetToken("");
        window.history.replaceState({}, document.title, window.location.pathname);
      } else {
        setError(res.message || "Unable to reset password. The link may have expired.");
      }
    } catch {
      setError("Unable to reset password.");
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-[#f6f7f9] px-4 py-10">
      <div className="grid w-full max-w-5xl overflow-hidden rounded-3xl bg-white shadow-xl md:grid-cols-[1fr_1.1fr]">
        {/* Left Brand Panel */}
        <section className="flex flex-col justify-between bg-[#0F2A43] p-8 text-white sm:p-10">
          <div>
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#D4A72C] text-[#0F2A43]">
              <Building2 size={26} />
            </div>
            <p className="mt-8 text-xs font-bold uppercase tracking-[0.2em] text-[#eac95f]">Real Estate Portal</p>
            <h1 className="mt-3 text-3xl font-bold sm:text-4xl">Property &amp; Portfolio Management</h1>
            <p className="mt-4 max-w-sm text-sm leading-6 text-slate-300">
              Connect verified agents and property owners with qualified buyers and tenants across properties for sale, long-term rentals, and short-term BnB stays worldwide.
            </p>
          </div>
          <div className="mt-10 rounded-xl border border-white/10 bg-white/5 p-4 text-xs text-slate-300">
            <p className="font-semibold text-white">Verified Partner Network</p>
            <p className="mt-1 text-slate-400">
              Agents and property owners are invited via email and publish listings once verified by our administrator.
            </p>
          </div>
        </section>

        {/* Right Form Panel */}
        <section className="p-6 sm:p-10">
          {/* Mode Switcher: Only show tabs when an invite link is active or during initial setup */}
          {(invitedViaEmail || !adminExists) && (
            <div className="mb-7 flex flex-wrap gap-2">
              <button
                type="button"
                onClick={() => {
                  setMode("signin");
                  setError("");
                  setSuccessMsg("");
                }}
                className={`inline-flex items-center gap-2 rounded-full px-4 py-2 text-xs font-semibold transition ${
                  mode === "signin" ? "bg-[#0F2A43] text-white" : "bg-slate-100 text-slate-600 hover:bg-slate-200"
                }`}
              >
                <LogIn size={14} /> Sign in
              </button>
              {!adminExists && (
                <button
                  type="button"
                  onClick={() => {
                    setMode("setup_admin");
                    setError("");
                    setSuccessMsg("");
                  }}
                  className={`inline-flex items-center gap-2 rounded-full px-4 py-2 text-xs font-semibold transition ${
                    mode === "setup_admin"
                      ? "bg-[#0F2A43] text-white"
                      : "bg-amber-50 text-amber-900 hover:bg-amber-100"
                  }`}
                >
                  <ShieldCheck size={14} /> Initial Admin Setup
                </button>
              )}
              {invitedViaEmail && (
                <button
                  type="button"
                  onClick={() => {
                    setMode("register");
                    setError("");
                    setSuccessMsg("");
                  }}
                  className={`inline-flex items-center gap-2 rounded-full px-4 py-2 text-xs font-semibold transition ${
                    mode === "register"
                      ? "bg-[#0F2A43] text-white"
                      : "bg-slate-100 text-slate-600 hover:bg-slate-200"
                  }`}
                >
                  <UserPlus size={14} /> Complete Registration
                </button>
              )}
            </div>
          )}

          {successMsg && (
            <div className="mb-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
              <CheckCircle2 size={18} className="mt-0.5 shrink-0 text-emerald-600" />
              <span>{successMsg}</span>
            </div>
          )}

          {error && (
            <div className="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
              {error}
            </div>
          )}

          {mode === "signin" && (
            <div>
              <div className="mb-6">
                <h2 className="text-2xl font-bold text-[#0F2A43]">Sign in to your account</h2>
                <p className="mt-1 text-sm text-slate-500">Access listings, buyer enquiries, rentals, and statements.</p>
              </div>

              <form onSubmit={handleLogin} className="space-y-4">
                <div>
                  <label className="block text-sm font-semibold text-slate-700">Email address</label>
                  <input
                    type="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    className={`${inputClass} mt-1`}
                    placeholder="name@company.com"
                  />
                  {fieldErrors.email && <p className="mt-1 text-xs text-red-600">{fieldErrors.email}</p>}
                </div>

                <div>
                  <div className="flex items-center justify-between">
                    <label className="block text-sm font-semibold text-slate-700">Password</label>
                    <button
                      type="button"
                      onClick={() => setMode("forgot")}
                      className="text-xs text-[#0F2A43] hover:underline"
                    >
                      Forgot password?
                    </button>
                  </div>
                  <div className="relative mt-1">
                    <input
                      type={showPassword ? "text" : "password"}
                      required
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      className={`${inputClass} pr-12`}
                      placeholder="Enter your password"
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword((visible) => !visible)}
                      aria-label={showPassword ? "Hide password" : "Show password"}
                      aria-pressed={showPassword}
                      className="absolute inset-y-0 right-0 flex items-center px-4 text-slate-500 hover:text-[#0F2A43]"
                    >
                      {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                    </button>
                  </div>
                  {fieldErrors.password && <p className="mt-1 text-xs text-red-600">{fieldErrors.password}</p>}
                </div>

                <button
                  type="submit"
                  disabled={loading}
                  className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3.5 text-sm font-semibold text-white shadow-md transition hover:bg-[#173e60] disabled:opacity-50"
                >
                  {loading ? <Loader2 size={18} className="animate-spin" /> : <LogIn size={18} />}
                  Sign In
                </button>
              </form>
            </div>
          )}

          {mode === "setup_admin" && !adminExists && (
            <div>
              <div className="mb-6">
                <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-800 border border-amber-200">
                  <ShieldCheck size={14} /> First-Time System Setup
                </span>
                <h2 className="mt-3 text-2xl font-bold text-[#0F2A43]">
                  Create Primary Administrator Account
                </h2>
                <p className="mt-1 text-sm text-slate-500">
                  No administrator account exists yet. Use the one-time setup key provided by your hosting administrator to create the primary account.
                </p>
              </div>

              <form onSubmit={handleSetupAdmin} className="space-y-4">
                <div>
                  <label className="block text-sm font-semibold text-slate-700">One-Time Administrator Setup Key</label>
                  <input
                    type="password"
                    required
                    autoComplete="off"
                    value={setupKey}
                    onChange={(e) => setSetupKey(e.target.value)}
                    className={`${inputClass} mt-1`}
                    placeholder="Enter the setup key provided by your hosting administrator"
                  />
                  {fieldErrors.setup_key && <p className="mt-1 text-xs text-red-600">{fieldErrors.setup_key}</p>}
                </div>

                <div>
                  <label className="block text-sm font-semibold text-slate-700">Administrator Full Name</label>
                  <input
                    type="text"
                    required
                    value={fullName}
                    onChange={(e) => setFullName(e.target.value)}
                    className={`${inputClass} mt-1`}
                    placeholder="e.g. System Administrator"
                  />
                  {fieldErrors.full_name && <p className="mt-1 text-xs text-red-600">{fieldErrors.full_name}</p>}
                </div>

                <div>
                  <label className="block text-sm font-semibold text-slate-700">Administrator Email</label>
                  <input
                    type="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    className={`${inputClass} mt-1`}
                    placeholder="admin@company.com"
                  />
                  {fieldErrors.email && <p className="mt-1 text-xs text-red-600">{fieldErrors.email}</p>}
                </div>

                <div>
                  <label className="block text-sm font-semibold text-slate-700">Create Strong Password</label>
                  <div className="relative mt-1">
                    <input
                      type={showPassword ? "text" : "password"}
                      required
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      className={`${inputClass} pr-12`}
                      placeholder="At least 8 characters with uppercase, number & symbol"
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword((visible) => !visible)}
                      aria-label={showPassword ? "Hide password" : "Show password"}
                      aria-pressed={showPassword}
                      className="absolute inset-y-0 right-0 flex items-center px-4 text-slate-500 hover:text-[#0F2A43]"
                    >
                      {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                    </button>
                  </div>
                  {fieldErrors.password && <p className="mt-1 text-xs text-red-600">{fieldErrors.password}</p>}
                </div>

                <button
                  type="submit"
                  disabled={loading}
                  className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3.5 text-sm font-semibold text-white shadow-md transition hover:bg-[#173e60] disabled:opacity-50"
                >
                  {loading ? <Loader2 size={18} className="animate-spin" /> : <ShieldCheck size={18} />}
                  Initialize Primary Administrator
                </button>
              </form>
            </div>
          )}

          {mode === "register" && invitedViaEmail && (
            <div>
              <div className="mb-6">
                <h2 className="text-2xl font-bold text-[#0F2A43]">
                  Complete Your {role === "owner" ? "Property Owner" : "Agent"} Registration
                </h2>
                <p className="mt-1 text-sm text-slate-500">
                  Submit your details below. Once verified by our administrator, you will be able to sign in and submit properties.
                </p>
              </div>

              <form onSubmit={handleRegister} className="space-y-4">
                <div>
                  <label className="block text-sm font-semibold text-slate-700">Full Name / Business Name</label>
                  <input
                    type="text"
                    required
                    value={fullName}
                    onChange={(e) => setFullName(e.target.value)}
                    className={`${inputClass} mt-1`}
                    placeholder="Jane Doe"
                  />
                  {fieldErrors.full_name && <p className="mt-1 text-xs text-red-600">{fieldErrors.full_name}</p>}
                </div>

                <div>
                  <label className="block text-sm font-semibold text-slate-700">Email address</label>
                  <input
                    type="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    className={`${inputClass} mt-1`}
                    placeholder="you@company.com"
                  />
                  {fieldErrors.email && <p className="mt-1 text-xs text-red-600">{fieldErrors.email}</p>}
                </div>

                <div>
                  <label className="block text-sm font-semibold text-slate-700">Account Type</label>
                  <select
                    value={role}
                    onChange={(e) => setRole(e.target.value)}
                    className={`${inputClass} mt-1`}
                  >
                    <option value="agent">Real Estate Agent</option>
                    <option value="owner">Property Owner / Seller</option>
                  </select>
                </div>

                <div>
                  <label className="block text-sm font-semibold text-slate-700">Create Password</label>
                  <div className="relative mt-1">
                    <input
                      type={showPassword ? "text" : "password"}
                      required
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      className={`${inputClass} pr-12`}
                      placeholder="At least 8 characters with uppercase, number & symbol"
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword((visible) => !visible)}
                      aria-label={showPassword ? "Hide password" : "Show password"}
                      aria-pressed={showPassword}
                      className="absolute inset-y-0 right-0 flex items-center px-4 text-slate-500 hover:text-[#0F2A43]"
                    >
                      {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                    </button>
                  </div>
                  {fieldErrors.password && <p className="mt-1 text-xs text-red-600">{fieldErrors.password}</p>}
                </div>

                <button
                  type="submit"
                  disabled={loading}
                  className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3.5 text-sm font-semibold text-white shadow-md transition hover:bg-[#173e60] disabled:opacity-50"
                >
                  {loading ? <Loader2 size={18} className="animate-spin" /> : <UserPlus size={18} />}
                  Submit Registration for Verification
                </button>
                <p className="text-center text-xs text-slate-400">
                  Your account will be reviewed and verified by an administrator before activation.
                </p>
              </form>
            </div>
          )}

          {mode === "forgot" && (
            <div>
              <div className="mb-6">
                <h2 className="text-2xl font-bold text-[#0F2A43]">Reset password</h2>
                <p className="mt-1 text-sm text-slate-500">
                  Enter your registered email address to receive a password reset link.
                </p>
              </div>

              <form onSubmit={handleForgotPassword} className="space-y-4">
                <div>
                  <label className="block text-sm font-semibold text-slate-700">Registered Email</label>
                  <input
                    type="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    className={`${inputClass} mt-1`}
                    placeholder="you@example.com"
                  />
                  {fieldErrors.email && <p className="mt-1 text-xs text-red-600">{fieldErrors.email}</p>}
                </div>

                <button
                  type="submit"
                  disabled={loading}
                  className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3.5 text-sm font-semibold text-white shadow-md transition hover:bg-[#173e60] disabled:opacity-50"
                >
                  {loading ? <Loader2 size={18} className="animate-spin" /> : <Send size={18} />}
                  Send Reset Link
                </button>

                {devResetUrl && (
                  <div className="rounded-xl border border-amber-200 bg-amber-50/90 p-4 text-xs text-amber-900 shadow-xs">
                    <div className="flex items-center gap-1.5 font-bold text-amber-950">
                      <AlertCircle size={15} className="text-amber-600 shrink-0" />
                      <span>Development Testing Link</span>
                    </div>
                    <p className="mt-1 text-slate-700 leading-relaxed">
                      {devNotice || "Live email delivery requires authenticated SMTP credentials in your .env file. For development testing, you can use this direct link to complete the reset immediately:"}
                    </p>
                    <div className="mt-3 flex flex-wrap gap-2">
                      <button
                        type="button"
                        onClick={() => {
                          try {
                            const parsed = new URL(devResetUrl);
                            const token = parsed.searchParams.get("token");
                            if (token) {
                              setResetToken(token);
                              setMode("reset_password");
                              setSuccessMsg("");
                              setError("");
                            }
                          } catch {
                            window.location.href = devResetUrl;
                          }
                        }}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-[#0F2A43] px-3.5 py-2 font-semibold text-white hover:bg-[#173e60] transition shadow-xs"
                      >
                        <ShieldCheck size={14} />
                        Open Reset Form Now
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          if (navigator.clipboard) {
                            navigator.clipboard.writeText(devResetUrl);
                            setCopiedDev(true);
                            setTimeout(() => setCopiedDev(false), 2500);
                          }
                        }}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50 transition"
                      >
                        {copiedDev ? <Check size={14} className="text-emerald-600" /> : <Copy size={14} />}
                        {copiedDev ? "Link Copied!" : "Copy Reset Link"}
                      </button>
                    </div>
                  </div>
                )}

                <button
                  type="button"
                  onClick={() => {
                    setMode("signin");
                    setDevResetUrl("");
                    setDevNotice("");
                  }}
                  className="w-full text-center text-xs font-semibold text-slate-500 hover:text-[#0F2A43]"
                >
                  ← Back to Sign In
                </button>
              </form>
            </div>
          )}

          {mode === "reset_password" && (
            <div>
              <div className="mb-6">
                <h2 className="text-2xl font-bold text-[#0F2A43]">Choose a new password</h2>
                <p className="mt-1 text-sm text-slate-500">
                  Create a strong password for your account.
                </p>
              </div>

              <form onSubmit={handleResetPassword} className="space-y-4">
                <label className="block text-sm font-semibold text-slate-700">
                  New password
                  <input
                    type="password"
                    required
                    autoComplete="new-password"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    className={`${inputClass} mt-1`}
                    placeholder="At least 8 characters with uppercase, number & symbol"
                  />
                  {fieldErrors.password && (
                    <span className="mt-1 block text-xs text-red-600">{fieldErrors.password}</span>
                  )}
                </label>

                <label className="block text-sm font-semibold text-slate-700">
                  Confirm new password
                  <input
                    type="password"
                    required
                    autoComplete="new-password"
                    value={confirmPassword}
                    onChange={(event) => setConfirmPassword(event.target.value)}
                    className={`${inputClass} mt-1`}
                    placeholder="Re-enter your new password"
                  />
                  {fieldErrors.confirmPassword && (
                    <span className="mt-1 block text-xs text-red-600">
                      {fieldErrors.confirmPassword}
                    </span>
                  )}
                </label>

                <button
                  type="submit"
                  disabled={loading || !resetToken}
                  className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#0F2A43] px-5 py-3.5 text-sm font-semibold text-white shadow-md transition hover:bg-[#173e60] disabled:opacity-50"
                >
                  {loading ? <Loader2 size={18} className="animate-spin" /> : <ShieldCheck size={18} />}
                  Reset Password
                </button>
                {!resetToken && (
                  <p className="text-sm text-red-600">
                    This password reset link is invalid or incomplete. Request a new link.
                  </p>
                )}
              </form>
            </div>
          )}
        </section>
      </div>
    </main>
  );
}
