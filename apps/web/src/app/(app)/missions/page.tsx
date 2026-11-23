"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { can, useMe } from "@/components/MeProvider";
import { ApiError, api, formatDate } from "@/lib/api";
import { Card, Field, Pill, UnitSelect, UserSelect, missionTone, toIso, useC, useDirectory, type HealthEvent, type Mission } from "@/lib/coordination";
import { useI18n } from "@/lib/i18n";

export default function MissionsPage() {
  const me = useMe();
  const { t, locale } = useI18n();
  const { c, cx } = useC();
  const [missions, setMissions] = useState<Mission[] | null>(null);
  const [mine, setMine] = useState(false);
  const [creating, setCreating] = useState(false);
  const [presetEvent, setPresetEvent] = useState("");

  useEffect(() => {
    const event = new URLSearchParams(window.location.search).get("event");
    if (event) {
      setPresetEvent(event);
      setCreating(true);
    }
  }, []);

  useEffect(() => {
    api<Mission[]>(`missions?per_page=50${mine ? "&mine=1" : ""}`).then((r) => setMissions(r.data));
  }, [mine]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold">{c("missions")}</h1>
        {can(me, "health_events.manage") && <button className="btn" onClick={() => setCreating((v) => !v)}>+ {c("newMission")}</button>}
      </div>

      {creating && <NewMissionForm presetEvent={presetEvent} onCancel={() => setCreating(false)} />}

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={mine} onChange={(e) => setMine(e.target.checked)} /> {c("onlyMyMissions")}
      </label>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="bg-slate-50 text-xs text-slate-600">
            <tr>
              <th className="p-3">{t("reference")}</th>
              <th className="p-3">{t("title")}</th>
              <th className="p-3">{c("destination")}</th>
              <th className="p-3">{c("departure")}</th>
              <th className="p-3">{c("leader")}</th>
              <th className="p-3">{t("status")}</th>
            </tr>
          </thead>
          <tbody>
            {missions === null && <tr><td colSpan={6} className="p-3 text-slate-500">{t("loading")}</td></tr>}
            {missions?.length === 0 && <tr><td colSpan={6} className="p-3 text-slate-500">{t("noData")}</td></tr>}
            {missions?.map((m) => (
              <tr key={m.id} className="border-t border-slate-100 hover:bg-slate-50">
                <td className="p-3 font-mono text-xs">{m.reference}</td>
                <td className="p-3">
                  <Link href={`/missions/${m.id}`} className="font-medium text-[var(--brand)] hover:underline">{m.title}</Link>
                  {m.health_event && <div className="text-xs text-slate-500">{m.health_event.title}</div>}
                </td>
                <td className="p-3">{m.destination}</td>
                <td className="whitespace-nowrap p-3">{formatDate(m.departure_at, locale)}</td>
                <td className="p-3">{m.leader?.name} {m.members_count && m.members_count > 1 ? <span className="text-xs text-slate-500">+{m.members_count - 1}</span> : null}</td>
                <td className="p-3"><Pill tone={missionTone(m.status)}>{cx(`mis_${m.status}`)}</Pill></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function NewMissionForm({ presetEvent, onCancel }: { presetEvent: string; onCancel: () => void }) {
  const { t } = useI18n();
  const { c } = useC();
  const router = useRouter();
  const { units, users } = useDirectory();
  const [events, setEvents] = useState<HealthEvent[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [form, setForm] = useState({
    title: "", destination: "", objectives: "", departure_at: "", return_at: "",
    organization_unit_id: "", health_event_id: presetEvent, mission_leader_id: "", members: [] as string[],
  });

  useEffect(() => {
    api<HealthEvent[]>("health-events?per_page=50").then((r) => setEvents(r.data.filter((e) => e.status !== "closed"))).catch(() => undefined);
  }, []);
  useEffect(() => setForm((f) => ({ ...f, health_event_id: f.health_event_id || presetEvent })), [presetEvent]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setErrors({});
    try {
      const r = await api<Mission>("missions", {
        method: "POST",
        body: JSON.stringify({
          ...form,
          health_event_id: form.health_event_id || undefined,
          departure_at: toIso(form.departure_at),
          return_at: toIso(form.return_at),
          members: form.members.filter((id) => id !== form.mission_leader_id).map((user_id) => ({ user_id })),
        }),
      });
      router.push(`/missions/${r.data.id}`);
    } catch (err) {
      if (err instanceof ApiError) setErrors(err.body.errors ?? { title: [err.message] });
    }
  }

  return (
    <Card title={c("newMission")}>
      <form onSubmit={submit} className="grid gap-3 md:grid-cols-2">
        <Field label={t("title")} error={errors.title} className="md:col-span-2"><input required className="input" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
        <Field label={c("destination")} error={errors.destination}><input required className="input" value={form.destination} onChange={(e) => setForm({ ...form, destination: e.target.value })} /></Field>
        <Field label={c("linkedEvent")}>
          <select className="input" value={form.health_event_id} onChange={(e) => setForm({ ...form, health_event_id: e.target.value })}>
            <option value="">{c("none")}</option>
            {events.map((ev) => <option key={ev.id} value={ev.id}>{ev.reference} · {ev.title}</option>)}
          </select>
        </Field>
        <Field label={c("departure")} error={errors.departure_at}><input required type="datetime-local" className="input" value={form.departure_at} onChange={(e) => setForm({ ...form, departure_at: e.target.value })} /></Field>
        <Field label={c("return")} error={errors.return_at}><input required type="datetime-local" className="input" value={form.return_at} onChange={(e) => setForm({ ...form, return_at: e.target.value })} /></Field>
        <Field label={t("unit")} error={errors.organization_unit_id}><UnitSelect units={units} value={form.organization_unit_id} onChange={(v) => setForm({ ...form, organization_unit_id: v })} required /></Field>
        <Field label={c("leader")} error={errors.mission_leader_id}><UserSelect users={users} value={form.mission_leader_id} onChange={(v) => setForm({ ...form, mission_leader_id: v })} required /></Field>
        <Field label={c("team")} className="md:col-span-2">
          <select multiple className="input h-28" value={form.members} onChange={(e) => setForm({ ...form, members: Array.from(e.target.selectedOptions, (o) => o.value) })}>
            {users.filter((u) => u.id !== form.mission_leader_id).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
        </Field>
        <Field label={c("objectives")} error={errors.objectives} className="md:col-span-2"><textarea required rows={3} className="input" value={form.objectives} onChange={(e) => setForm({ ...form, objectives: e.target.value })} /></Field>
        <div className="flex gap-2 md:col-span-2">
          <button className="btn">{t("save")}</button>
          <button type="button" className="btn-secondary" onClick={onCancel}>{t("cancel")}</button>
        </div>
      </form>
    </Card>
  );
}
