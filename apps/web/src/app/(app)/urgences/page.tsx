"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { can, useMe } from "@/components/MeProvider";
import { ApiError, api, formatDate } from "@/lib/api";
import { Card, Field, Pill, SeverityBadge, UserSelect, eventTone, toIso, useC, useDirectory, type HealthEvent } from "@/lib/coordination";
import { useI18n } from "@/lib/i18n";

const EVENT_TYPES = ["cholera", "measles", "mpox", "hemorrhagic_fever", "flood", "other"];
const SEVERITIES = ["low", "moderate", "high", "critical"];

export default function HealthEventsPage() {
  const me = useMe();
  const { t, locale } = useI18n();
  const { c, cx } = useC();
  const [events, setEvents] = useState<HealthEvent[] | null>(null);
  const [status, setStatus] = useState("");
  const [creating, setCreating] = useState(false);

  useEffect(() => {
    api<HealthEvent[]>(`health-events?per_page=50${status ? `&status=${status}` : ""}`).then((r) => setEvents(r.data));
  }, [status]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold">{t("emergencies")}</h1>
        <div className="flex gap-2">
          <Link href="/missions" className="btn-secondary">{c("missions")}</Link>
          {can(me, "health_events.manage") && <button className="btn" onClick={() => setCreating((v) => !v)}>+ {c("newEvent")}</button>}
        </div>
      </div>
      <p className="text-sm text-slate-500">{c("disclaimer")}</p>

      {creating && <NewEventForm onCancel={() => setCreating(false)} />}

      <select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value)}>
        <option value="">{t("filterAll")}</option>
        {["active", "monitoring", "closed"].map((s) => <option key={s} value={s}>{cx(`es2_${s}`)}</option>)}
      </select>

      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        {events === null && <p className="text-slate-500">{t("loading")}</p>}
        {events?.length === 0 && <p className="text-slate-500">{t("noData")}</p>}
        {events?.map((ev) => (
          <Link key={ev.id} href={`/urgences/${ev.id}`} className="rounded-xl border border-slate-200 bg-white p-4 hover:border-[var(--brand)]">
            <div className="flex items-center justify-between gap-2">
              <span className="font-mono text-xs text-slate-500">{ev.reference}</span>
              <Pill tone={eventTone(ev.status)}>{cx(`es2_${ev.status}`)}</Pill>
            </div>
            <div className="mt-1 font-semibold">{ev.title}</div>
            <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-600">
              <span>{cx(`ev_${ev.event_type}`)}</span>
              <SeverityBadge severity={ev.severity} />
              {ev.location && <span>· {ev.location}</span>}
            </div>
            <div className="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
              <div className="rounded bg-slate-50 p-2"><div className="text-lg font-bold">{ev.open_tasks_count ?? 0}</div>{c("responseActions")}</div>
              <div className="rounded bg-red-50 p-2 text-red-700"><div className="text-lg font-bold">{ev.overdue_tasks_count ?? 0}</div>{t("overdue")}</div>
              <div className="rounded bg-slate-50 p-2"><div className="text-lg font-bold">{ev.missions_count ?? 0}</div>{c("missions")}</div>
            </div>
            <div className="mt-2 text-xs text-slate-500">{c("openedOn")} {formatDate(ev.start_at, locale)}{ev.coordinator && ` · ${ev.coordinator.name}`}</div>
          </Link>
        ))}
      </div>
    </div>
  );
}

function NewEventForm({ onCancel }: { onCancel: () => void }) {
  const { t } = useI18n();
  const { c, cx } = useC();
  const router = useRouter();
  const { users } = useDirectory();
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [form, setForm] = useState({ title: "", event_type: "cholera", severity: "high", location: "", description: "", start_at: "", coordinator_id: "" });
  const set = (k: keyof typeof form) => (v: string) => setForm({ ...form, [k]: v });

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    try {
      const body = Object.fromEntries(Object.entries({ ...form, start_at: toIso(form.start_at) }).filter(([, v]) => v));
      const r = await api<HealthEvent>("health-events", { method: "POST", body: JSON.stringify(body) });
      router.push(`/urgences/${r.data.id}`);
    } catch (err) {
      if (err instanceof ApiError) setErrors(err.body.errors ?? { title: [err.message] });
    }
  }

  return (
    <Card title={c("newEvent")}>
      <form onSubmit={submit} className="grid gap-3 md:grid-cols-2">
        <Field label={t("title")} error={errors.title} className="md:col-span-2">
          <input required className="input" value={form.title} onChange={(e) => set("title")(e.target.value)} />
        </Field>
        <Field label={c("eventType")}>
          <select className="input" value={form.event_type} onChange={(e) => set("event_type")(e.target.value)}>
            {EVENT_TYPES.map((x) => <option key={x} value={x}>{cx(`ev_${x}`)}</option>)}
          </select>
        </Field>
        <Field label={c("severity")}>
          <select className="input" value={form.severity} onChange={(e) => set("severity")(e.target.value)}>
            {SEVERITIES.map((x) => <option key={x} value={x}>{cx(`sev_${x}`)}</option>)}
          </select>
        </Field>
        <Field label={c("location")}><input className="input" value={form.location} onChange={(e) => set("location")(e.target.value)} /></Field>
        <Field label={c("openedOn")} error={errors.start_at}><input required type="datetime-local" className="input" value={form.start_at} onChange={(e) => set("start_at")(e.target.value)} /></Field>
        <Field label={c("coordinator")}><UserSelect users={users} value={form.coordinator_id} onChange={set("coordinator_id")} /></Field>
        <Field label={t("description")} className="md:col-span-2"><textarea rows={3} className="input" value={form.description} onChange={(e) => set("description")(e.target.value)} /></Field>
        <div className="flex gap-2 md:col-span-2">
          <button className="btn">{t("save")}</button>
          <button type="button" className="btn-secondary" onClick={onCancel}>{t("cancel")}</button>
        </div>
      </form>
    </Card>
  );
}
