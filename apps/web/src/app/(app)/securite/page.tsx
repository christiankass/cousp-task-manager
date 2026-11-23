"use client";

import { QRCodeSVG } from "qrcode.react";
import { useCallback, useEffect, useState } from "react";
import { ApiError, api } from "@/lib/api";
import { Card, Field } from "@/lib/coordination";
import { useI18n } from "@/lib/i18n";
import { formatDay, useL, type MfaStatus } from "@/lib/lot4";

export default function SecurityPage() {
  const { locale } = useI18n();
  const { l } = useL();
  const [status, setStatus] = useState<MfaStatus | null>(null);
  const [setup, setSetup] = useState<{ secret: string; otpauth_url: string } | null>(null);
  const [codes, setCodes] = useState<string[] | null>(null);
  const [disabling, setDisabling] = useState(false);
  const [form, setForm] = useState({ code: "", password: "" });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => api<MfaStatus>("auth/mfa").then((r) => setStatus(r.data)), []);
  useEffect(() => {
    load();
  }, [load]);

  async function run(fn: () => Promise<void>) {
    setBusy(true);
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(e instanceof ApiError ? [e.message, ...Object.values(e.body.errors ?? {}).flat()].join(" ") : String(e));
    } finally {
      setBusy(false);
    }
  }

  const start = () => run(async () => setSetup((await api<{ secret: string; otpauth_url: string }>("auth/mfa/setup", { method: "POST" })).data));
  const confirm = (e: React.FormEvent) => {
    e.preventDefault();
    run(async () => {
      const r = await api<{ recovery_codes: string[] }>("auth/mfa/confirm", { method: "POST", body: JSON.stringify({ code: form.code }) });
      setCodes(r.data.recovery_codes);
      setSetup(null);
      setForm({ code: "", password: "" });
      await load();
    });
  };
  const disable = (e: React.FormEvent) => {
    e.preventDefault();
    run(async () => {
      await api("auth/mfa/disable", { method: "POST", body: JSON.stringify(form) });
      setDisabling(false);
      setForm({ code: "", password: "" });
      await load();
    });
  };

  if (!status) return <p className="text-slate-500">…</p>;

  return (
    <div className="max-w-2xl space-y-4">
      <h1 className="text-2xl font-bold">{l("securityTitle")}</h1>

      <Card
        title={l("mfaTitle")}
        actions={
          <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${status.enabled ? "bg-emerald-100 text-emerald-800" : "bg-slate-100 text-slate-700"}`}>
            {status.enabled ? l("mfaOn") : l("mfaOff")}
          </span>
        }
      >
        <div className="space-y-3 text-sm">
          <p className="text-slate-600">{l("mfaIntro")}</p>
          {!status.enabled && status.recommended && <p className="rounded bg-amber-50 p-2 text-amber-800">{l("mfaRecommended")}</p>}
          {status.enabled && status.confirmed_at && (
            <p className="text-slate-600">
              {l("mfaOn")} {l("mfaSince")} {formatDay(status.confirmed_at.slice(0, 10), locale)} · {l("mfaCodesLeft")} : <strong>{status.recovery_codes_left}</strong>
            </p>
          )}

          {codes && (
            <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
              <h3 className="font-semibold text-emerald-900">{l("mfaCodesTitle")}</h3>
              <p className="mt-1 text-emerald-900">{l("mfaCodesHint")}</p>
              <ul className="my-3 grid grid-cols-2 gap-1 font-mono text-base">
                {codes.map((c) => (
                  <li key={c} className="rounded bg-white px-2 py-1 text-center">
                    {c}
                  </li>
                ))}
              </ul>
              <div className="no-print flex gap-2">
                <button className="btn-secondary" onClick={() => window.print()}>
                  {l("mfaPrint")}
                </button>
                <button className="btn" onClick={() => setCodes(null)}>
                  {l("mfaDone")}
                </button>
              </div>
            </div>
          )}

          {!status.enabled && !setup && (
            <button className="btn" onClick={start} disabled={busy}>
              {l("mfaStart")}
            </button>
          )}

          {setup && (
            <form onSubmit={confirm} className="space-y-3 rounded-lg border border-slate-200 p-3">
              <p className="font-medium">{l("mfaStep1")}</p>
              <div className="flex flex-wrap items-center gap-4">
                <div className="rounded-lg border border-slate-200 bg-white p-2">
                  <QRCodeSVG value={setup.otpauth_url} size={176} />
                </div>
                <div className="min-w-0 flex-1 text-slate-600">
                  {l("mfaManual")}
                  <code className="mt-1 block break-all rounded bg-slate-100 p-2 font-mono text-slate-900">{setup.secret.replace(/(.{4})/g, "$1 ").trim()}</code>
                </div>
              </div>
              <p className="font-medium">{l("mfaStep2")}</p>
              <div className="flex flex-wrap items-end gap-2">
                <input
                  className="input w-40 text-center font-mono text-lg tracking-widest"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={6}
                  required
                  value={form.code}
                  onChange={(e) => setForm({ ...form, code: e.target.value.replace(/\D/g, "") })}
                />
                <button className="btn" disabled={busy || form.code.length !== 6}>
                  {l("mfaConfirm")}
                </button>
                <button type="button" className="btn-secondary" onClick={() => setSetup(null)}>
                  {l("cancel")}
                </button>
              </div>
            </form>
          )}

          {status.enabled && !disabling && !codes && (
            <button className="btn-secondary" onClick={() => setDisabling(true)}>
              {l("mfaDisable")}
            </button>
          )}

          {disabling && (
            <form onSubmit={disable} className="space-y-3 rounded-lg border border-red-200 p-3">
              <p>{l("mfaDisableHint")}</p>
              <div className="grid gap-3 sm:grid-cols-2">
                <Field label={l("password")}>
                  <input type="password" className="input" required value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
                </Field>
                <Field label={l("code")}>
                  <input className="input font-mono" required autoComplete="one-time-code" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.trim() })} />
                </Field>
              </div>
              <div className="flex gap-2">
                <button className="btn-danger" disabled={busy}>
                  {l("mfaDisable")}
                </button>
                <button type="button" className="btn-secondary" onClick={() => setDisabling(false)}>
                  {l("cancel")}
                </button>
              </div>
            </form>
          )}

          {error && <p className="rounded bg-red-50 p-2 text-red-700">{error}</p>}
        </div>
      </Card>
    </div>
  );
}
