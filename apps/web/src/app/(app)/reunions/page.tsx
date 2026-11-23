"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { can, useMe } from "@/components/MeProvider";
import { ApiError, api, formatDate } from "@/lib/api";
import { Card, Field, FollowUpBar, Pill, UnitSelect, UserSelect, toIso, useC, useDirectory, type HealthEvent, type Meeting } from "@/lib/coordination";
import { useI18n } from "@/lib/i18n";

const TYPES = ["coordination", "technical", "management", "crisis", "other"];

export default function MeetingsPage() {
  const me = useMe();
  const { t, locale } = useI18n();
  const { c, cx } = useC();
  const [meetings, setMeetings] = useState<Meeting[] | null>(null);
  const [creating, setCreating] = useState(false);
  const [search, setSearch] = useState("");

  useEffect(() => {
    const q = new URLSearchParams({ per_page: "50" });
    if (search) q.set("search", search);
    const timer = setTimeout(() => api<Meeting[]>(`meetings?${q}`).then((r) => setMeetings(r.data)), 250);
    return () => clearTimeout(timer);
  }, [search]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold">{t("meetings")}</h1>
        <div className="flex gap-2">
          <Link href="/recommandations" className="btn-secondary">
            {c("recommendations")}
          </Link>
          {can(me, "meetings.manage") && (
            <button className="btn" onClick={() => setCreating((v) => !v)}>
              + {c("newMeeting")}
            </button>
          )}
        </div>
      </div>

      {creating && <NewMeetingForm onCancel={() => setCreating(false)} />}

      <input placeholder={t("search")} value={search} onChange={(e) => setSearch(e.target.value)} className="input max-w-md" />

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="bg-slate-50 text-xs text-slate-600">
            <tr>
              <th className="p-3">{t("reference")}</th>
              <th className="p-3">{t("title")}</th>
              <th className="p-3">{c("date")}</th>
              <th className="p-3">{t("status")}</th>
              <th className="p-3">{c("followUp")}</th>
            </tr>
          </thead>
          <tbody>
            {meetings === null && (
              <tr>
                <td className="p-3 text-slate-500" colSpan={5}>{t("loading")}</td>
              </tr>
            )}
            {meetings?.length === 0 && (
              <tr>
                <td className="p-3 text-slate-500" colSpan={5}>{t("noData")}</td>
              </tr>
            )}
            {meetings?.map((m) => (
              <tr key={m.id} className="border-t border-slate-100 hover:bg-slate-50">
                <td className="p-3 font-mono text-xs">{m.reference}</td>
                <td className="p-3">
                  <Link href={`/reunions/${m.id}`} className="font-medium text-[var(--brand)] hover:underline">
                    {m.title}
                  </Link>
                  <div className="text-xs text-slate-500">
                    {cx(`mt_${m.meeting_type}`)}
                    {m.health_event && ` · ${m.health_event.title}`}
                  </div>
                </td>
                <td className="p-3 whitespace-nowrap">{formatDate(m.starts_at, locale)}</td>
                <td className="p-3">
                  <Pill tone={m.status === "held" ? "green" : m.status === "cancelled" ? "slate" : "blue"}>{cx(`ms_${m.status}`)}</Pill>
                </td>
                <td className="p-3">
                  <FollowUpBar followUp={m.follow_up} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function NewMeetingForm({ onCancel }: { onCancel: () => void }) {
  const { t } = useI18n();
  const { c, cx } = useC();
  const router = useRouter();
  const { units, users } = useDirectory();
  const [events, setEvents] = useState<HealthEvent[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [form, setForm] = useState({
    title: "", meeting_type: "coordination", starts_at: "", location: "", agenda: "",
    organization_unit_id: "", health_event_id: "", chair_id: "", secretary_id: "",
  });

  useEffect(() => {
    api<HealthEvent[]>("health-events?status=active&per_page=50").then((r) => setEvents(r.data)).catch(() => undefined);
  }, []);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    try {
      const body = Object.fromEntries(Object.entries({ ...form, starts_at: toIso(form.starts_at) }).filter(([, v]) => v));
      const r = await api<Meeting>("meetings", { method: "POST", body: JSON.stringify(body) });
      router.push(`/reunions/${r.data.id}`);
    } catch (err) {
      if (err instanceof ApiError) setErrors(err.body.errors ?? { title: [err.message] });
    }
  }

  const set = (k: keyof typeof form) => (v: string) => setForm({ ...form, [k]: v });

  return (
    <Card title={c("newMeeting")}>
      <form onSubmit={submit} className="grid gap-3 md:grid-cols-2">
        <Field label={t("title")} error={errors.title} className="md:col-span-2">
          <input required className="input" value={form.title} onChange={(e) => set("title")(e.target.value)} />
        </Field>
        <Field label={c("meetingType")}>
          <select className="input" value={form.meeting_type} onChange={(e) => set("meeting_type")(e.target.value)}>
            {TYPES.map((x) => (
              <option key={x} value={x}>{cx(`mt_${x}`)}</option>
            ))}
          </select>
        </Field>
        <Field label={c("date")} error={errors.starts_at}>
          <input required type="datetime-local" className="input" value={form.starts_at} onChange={(e) => set("starts_at")(e.target.value)} />
        </Field>
        <Field label={c("location")}>
          <input className="input" value={form.location} onChange={(e) => set("location")(e.target.value)} />
        </Field>
        <Field label={t("unit")}>
          <UnitSelect units={units} value={form.organization_unit_id} onChange={set("organization_unit_id")} />
        </Field>
        <Field label={c("chair")}>
          <UserSelect users={users} value={form.chair_id} onChange={set("chair_id")} />
        </Field>
        <Field label={c("secretary")}>
          <UserSelect users={users} value={form.secretary_id} onChange={set("secretary_id")} />
        </Field>
        <Field label={c("linkedEvent")} className="md:col-span-2">
          <select className="input" value={form.health_event_id} onChange={(e) => set("health_event_id")(e.target.value)}>
            <option value="">{c("none")}</option>
            {events.map((ev) => (
              <option key={ev.id} value={ev.id}>{ev.reference} · {ev.title}</option>
            ))}
          </select>
        </Field>
        <Field label={c("agenda")} className="md:col-span-2">
          <textarea rows={3} className="input" value={form.agenda} onChange={(e) => set("agenda")(e.target.value)} />
        </Field>
        <div className="flex gap-2 md:col-span-2">
          <button className="btn">{t("save")}</button>
          <button type="button" className="btn-secondary" onClick={onCancel}>{t("cancel")}</button>
        </div>
      </form>
    </Card>
  );
}
