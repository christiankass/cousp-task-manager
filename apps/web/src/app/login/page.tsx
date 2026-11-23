"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useI18n } from "@/lib/i18n";
import { useL } from "@/lib/lot4";
import { LanguageSwitch } from "@/components/LanguageSwitch";

export default function LoginPage() {
  const { t, locale } = useI18n();
  const { l } = useL();
  const router = useRouter();
  const [form, setForm] = useState({ email: "", password: "", institution_code: "" });
  const [challenge, setChallenge] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const [recovery, setRecovery] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function post(url: string, payload: unknown) {
    setBusy(true);
    setError(null);
    const response = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept-Language": locale },
      body: JSON.stringify(payload),
    });
    const body = await response.json();
    setBusy(false);
    if (!response.ok) {
      setError(body.errors ? Object.values(body.errors).flat().join(" ") : body.message);
      // Délai dépassé ou trop d'essais : on revient à la première étape.
      if (body.code === "MFA_EXPIRED") setChallenge(null);
      return null;
    }
    return body;
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    const body = await post("/api/auth/login", { ...form, institution_code: form.institution_code || undefined });
    if (!body) return;
    if (body.data?.mfa_required) {
      setChallenge(body.data.challenge_token);
      setCode("");
    } else router.push("/");
  }

  async function verify(e: React.FormEvent) {
    e.preventDefault();
    const body = await post("/api/auth/mfa", { challenge_token: challenge, ...(recovery ? { recovery_code: code } : { code }) });
    if (body) router.push("/");
  }

  return (
    <main className="flex min-h-screen items-center justify-center px-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center justify-between">
          <h1 className="text-xl font-bold text-[var(--brand)]">{t("appName")}</h1>
          <LanguageSwitch />
        </div>
        {challenge ? (
          <form onSubmit={verify} className="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 className="text-lg font-semibold">{l("loginCodeTitle")}</h2>
            <p className="text-sm text-slate-600">{recovery ? l("loginRecoveryHint") : l("loginCodeHint")}</p>
            <input
              autoFocus
              required
              aria-label={l("code")}
              inputMode={recovery ? "text" : "numeric"}
              autoComplete="one-time-code"
              maxLength={recovery ? 11 : 6}
              value={code}
              onChange={(e) => setCode(recovery ? e.target.value.toUpperCase() : e.target.value.replace(/\D/g, ""))}
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-center font-mono text-xl tracking-widest focus:border-[var(--brand)] focus:outline-none"
            />
            {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
            <button disabled={busy} className="w-full rounded-md bg-[var(--brand)] py-2 font-medium text-white disabled:opacity-60">
              {busy ? t("signingIn") : l("verify")}
            </button>
            <div className="flex justify-between text-sm">
              <button type="button" className="text-slate-600 hover:underline" onClick={() => setChallenge(null)}>
                ‹ {l("back")}
              </button>
              <button
                type="button"
                className="text-[var(--brand)] hover:underline"
                onClick={() => {
                  setRecovery(!recovery);
                  setCode("");
                }}
              >
                {recovery ? l("loginUseApp") : l("loginUseRecovery")}
              </button>
            </div>
          </form>
        ) : (
          <form onSubmit={submit} className="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 className="text-lg font-semibold">{t("loginTitle")}</h2>
            {(["email", "password", "institution_code"] as const).map((field) => (
              <label key={field} className="block text-sm">
                <span className="mb-1 block text-slate-600">
                  {field === "email" ? t("email") : field === "password" ? t("password") : t("institutionCode")}
                </span>
                <input
                  type={field === "password" ? "password" : field === "email" ? "email" : "text"}
                  required={field !== "institution_code"}
                  value={form[field]}
                  onChange={(e) => setForm({ ...form, [field]: e.target.value })}
                  className="w-full rounded-md border border-slate-300 px-3 py-2 focus:border-[var(--brand)] focus:outline-none"
                />
              </label>
            ))}
            {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
            <button disabled={busy} className="w-full rounded-md bg-[var(--brand)] py-2 font-medium text-white disabled:opacity-60">
              {busy ? t("signingIn") : t("signIn")}
            </button>
          </form>
        )}
      </div>
    </main>
  );
}
