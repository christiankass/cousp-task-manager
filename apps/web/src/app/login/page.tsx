"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useI18n } from "@/lib/i18n";
import { LanguageSwitch } from "@/components/LanguageSwitch";

export default function LoginPage() {
  const { t, locale } = useI18n();
  const router = useRouter();
  const [form, setForm] = useState({ email: "", password: "", institution_code: "" });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    const response = await fetch("/api/auth/login", {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept-Language": locale },
      body: JSON.stringify({ ...form, institution_code: form.institution_code || undefined }),
    });
    const body = await response.json();
    setBusy(false);
    if (response.ok) router.push("/");
    else setError(body.errors ? Object.values(body.errors).flat().join(" ") : body.message);
  }

  return (
    <main className="flex min-h-screen items-center justify-center px-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center justify-between">
          <h1 className="text-xl font-bold text-[var(--brand)]">{t("appName")}</h1>
          <LanguageSwitch />
        </div>
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
          <button
            disabled={busy}
            className="w-full rounded-md bg-[var(--brand)] py-2 font-medium text-white disabled:opacity-60"
          >
            {busy ? t("signingIn") : t("signIn")}
          </button>
        </form>
      </div>
    </main>
  );
}
