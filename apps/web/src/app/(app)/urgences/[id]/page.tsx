"use client";

import Link from "next/link";
import { use, useCallback, useEffect, useState } from "react";
import { OverdueBadge, StatusBadge } from "@/components/TaskBadges";
import { ApiError, api, formatDate } from "@/lib/api";
import { Card, Field, Pill, SeverityBadge, UnitSelect, UserSelect, eventTone, missionTone, toIso, useC, useDirectory, type HealthEventDetail } from "@/lib/coordination";
import { useI18n } from "@/lib/i18n";
import { DownloadButton, useL } from "@/lib/lot4";
import { can, useMe } from "@/components/MeProvider";

export default function HealthEventPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { t, locale } = useI18n();
  const { c, cx } = useC();
  const me = useMe();
  const { l } = useL();
  const [event, setEvent] = useState<HealthEventDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [panel, setPanel] = useState<"" | "action" | "sitrep" | "close">("");

  const load = useCallback(() => api<HealthEventDetail>(`health-events/${id}`).then((r) => setEvent(r.data)).catch((e) => setError(e.message)), [id]);
  useEffect(() => {
    load();
  }, [load]);

  const run = async (fn: () => Promise<unknown>) => {
    setError(null);
    try {
      await fn();
      setPanel("");
      await load();
    } catch (e) {
      if (e instanceof ApiError) setError([e.message, ...Object.values(e.body.errors ?? {}).flat()].join(" "));
    }
  };

  if (!event) return <p className="text-slate-500">{error ?? t("loading")}</p>;
  const open = event.status !== "closed";
  const manage = event.can.manage && open;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="font-mono text-xs text-slate-500">{event.reference}</div>
          <h1 className="text-2xl font-bold">{event.title}</h1>
          <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-600">
            <span>{cx(`ev_${event.event_type}`)}</span>
            <SeverityBadge severity={event.severity} />
            <Pill tone={eventTone(event.status)}>{cx(`es2_${event.status}`)}</Pill>
            {event.location && <span>· {event.location}</span>}
          </div>
          <div className="mt-1 text-sm text-slate-600">
            {c("openedOn")} {formatDate(event.start_at, locale)}
            {event.end_at && ` · ${c("closedOn")} ${formatDate(event.end_at, locale)}`}
            {event.coordinator && ` · ${c("coordinator")} : ${event.coordinator.name}`}
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {(event.can.manage || can(me, "reports.export")) && (
            <DownloadButton path={`reports/health-events/${event.id}`} label={l("eventReportPdf")} />
          )}
        {manage && (
          <>
            <button className="btn" onClick={() => setPanel(panel === "action" ? "" : "action")}>+ {c("addAction")}</button>
            <button className="btn-secondary" onClick={() => setPanel(panel === "sitrep" ? "" : "sitrep")}>+ {c("addSitrep")}</button>
            <Link href={`/missions?event=${event.id}`} className="btn-secondary">+ {c("newMission")}</Link>
            <button className="btn-secondary" onClick={() => setPanel(panel === "close" ? "" : "close")}>{c("closeEvent")}</button>
          </>
        )}
        </div>
      </div>

      {error && <div className="rounded-md bg-red-50 p-2 text-sm text-red-700">{error}</div>}
      {panel === "action" && <ActionForm eventId={event.id} run={run} />}
      {panel === "sitrep" && <SitrepForm eventId={event.id} run={run} />}
      {panel === "close" && <CloseForm eventId={event.id} run={run} />}

      {event.description && <p className="whitespace-pre-line text-sm text-slate-700">{event.description}</p>}
      {event.closure_note && <Card title={c("closureNote")}><p className="whitespace-pre-line text-sm">{event.closure_note}</p></Card>}

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Kpi label={c("responseActions")} value={event.stats.tasks_total} />
        <Kpi label={t("s_in_progress")} value={event.stats.by_status.in_progress + event.stats.by_status.submitted} />
        <Kpi label={t("overdue")} value={event.stats.overdue} tone="text-red-600" />
        <Kpi label={c("completion")} value={event.stats.completion_rate === null ? "—" : `${event.stats.completion_rate} %`} tone="text-emerald-700" />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Card title={`${c("responseActions")} (${event.tasks.length})`}>
            {event.tasks.length === 0 ? <p className="text-sm text-slate-500">{t("noTasks")}</p> : (
              <ul className="divide-y divide-slate-100 text-sm">
                {event.tasks.map((task) => (
                  <li key={task.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span>
                      <Link href={`/taches/${task.id}`} className="font-medium text-[var(--brand)] hover:underline">{task.title}</Link>
                      <span className="block text-xs text-slate-500">{task.reference} · {task.organization_unit?.name} · {task.responsible?.name}</span>
                    </span>
                    <span className="flex items-center gap-2 text-xs">
                      {task.is_overdue && <OverdueBadge />}
                      <StatusBadge status={task.status} />
                      <span className="text-slate-500">{formatDate(task.due_at, locale)}</span>
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card title={`${c("missions")} (${event.missions.length})`}>
            {event.missions.length === 0 ? <p className="text-sm text-slate-500">{t("noData")}</p> : (
              <ul className="divide-y divide-slate-100 text-sm">
                {event.missions.map((m) => (
                  <li key={m.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span>
                      <Link href={`/missions/${m.id}`} className="font-medium text-[var(--brand)] hover:underline">{m.title}</Link>
                      <span className="block text-xs text-slate-500">{m.reference} · {m.destination} · {m.leader?.name}</span>
                    </span>
                    <span className="flex items-center gap-2 text-xs">
                      <Pill tone={missionTone(m.status)}>{cx(`mis_${m.status}`)}</Pill>
                      <span className="text-slate-500">{formatDate(m.departure_at, locale)}</span>
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card title={`${c("sitreps")} (${event.situation_reports.length})`}>
            {event.situation_reports.length === 0 ? <p className="text-sm text-slate-500">{t("noData")}</p> : (
              <div className="space-y-3">
                {event.situation_reports.map((r) => (
                  <article key={r.id} className="rounded-lg border border-slate-100 p-3 text-sm">
                    <div className="text-xs text-slate-500">{new Date(r.report_date).toLocaleDateString(locale === "en" ? "en-GB" : "fr-FR")} · {r.author?.name}</div>
                    <p className="mt-1 whitespace-pre-line">{r.summary}</p>
                    {r.actions && <p className="mt-1 whitespace-pre-line text-slate-600"><b>{c("actionsTaken")} :</b> {r.actions}</p>}
                    {r.needs && <p className="mt-1 whitespace-pre-line text-slate-600"><b>{c("needs")} :</b> {r.needs}</p>}
                  </article>
                ))}
              </div>
            )}
          </Card>
        </div>

        <div className="space-y-4">
          <Card title={c("teams")}>
            {event.teams.length === 0 ? <p className="text-sm text-slate-500">—</p> : (
              <ul className="space-y-2 text-sm">
                {event.teams.map((team) => (
                  <li key={team.id}>
                    <div className="flex justify-between"><span>{team.name}</span><span className="text-slate-500">{team.validated}/{team.total}</span></div>
                    <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100"><div className="h-full bg-emerald-500" style={{ width: `${(team.validated * 100) / team.total}%` }} /></div>
                    {team.overdue > 0 && <div className="text-xs text-red-600">{team.overdue} {t("overdue").toLowerCase()}</div>}
                  </li>
                ))}
              </ul>
            )}
          </Card>
          <Card title={t("meetings")}>
            {event.meetings.length === 0 ? <p className="text-sm text-slate-500">—</p> : (
              <ul className="space-y-1 text-sm">
                {event.meetings.map((m) => (
                  <li key={m.id}><Link href={`/reunions/${m.id}`} className="text-[var(--brand)] hover:underline">{m.title}</Link> <span className="text-xs text-slate-500">{formatDate(m.starts_at, locale)}</span></li>
                ))}
              </ul>
            )}
          </Card>
          <p className="text-xs text-slate-400">{c("disclaimer")}</p>
        </div>
      </div>
    </div>
  );
}

function Kpi({ label, value, tone = "text-[var(--brand)]" }: { label: string; value: number | string; tone?: string }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className={`text-2xl font-bold ${tone}`}>{value}</div>
      <div className="text-xs text-slate-500">{label}</div>
    </div>
  );
}

type Run = (fn: () => Promise<unknown>) => Promise<void>;

function ActionForm({ eventId, run }: { eventId: string; run: Run }) {
  const { t, tx } = useI18n();
  const { c } = useC();
  const { units, users } = useDirectory();
  const [form, setForm] = useState({ title: "", organization_unit_id: "", responsible: "", due_at: "", priority: "high", expected_result: "" });
  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    run(() => api(`health-events/${eventId}/tasks`, {
      method: "POST",
      body: JSON.stringify({
        title: form.title, organization_unit_id: form.organization_unit_id, priority: form.priority,
        expected_result: form.expected_result || undefined, start_at: new Date().toISOString(), due_at: toIso(form.due_at),
        assignees: [{ user_id: form.responsible, role: "responsible" }],
      }),
    }));
  };
  return (
    <Card title={c("addAction")}>
      <form onSubmit={submit} className="grid gap-3 md:grid-cols-3">
        <Field label={t("title")} className="md:col-span-3"><input required className="input" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
        <Field label={t("unit")}><UnitSelect units={units} value={form.organization_unit_id} onChange={(v) => setForm({ ...form, organization_unit_id: v })} required /></Field>
        <Field label={t("responsible")}><UserSelect users={users} value={form.responsible} onChange={(v) => setForm({ ...form, responsible: v })} required /></Field>
        <Field label={t("dueAt")}><input type="datetime-local" className="input" value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })} /></Field>
        <Field label={t("priority")}>
          <select className="input" value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}>
            {["low", "normal", "high", "critical"].map((p) => <option key={p} value={p}>{tx(`p_${p}`)}</option>)}
          </select>
        </Field>
        <Field label={t("expectedResult")} className="md:col-span-2"><input className="input" value={form.expected_result} onChange={(e) => setForm({ ...form, expected_result: e.target.value })} /></Field>
        <div><button className="btn">{t("save")}</button></div>
      </form>
    </Card>
  );
}

function SitrepForm({ eventId, run }: { eventId: string; run: Run }) {
  const { t } = useI18n();
  const { c } = useC();
  const [form, setForm] = useState({ report_date: new Date().toISOString().slice(0, 10), summary: "", actions: "", needs: "" });
  return (
    <Card title={c("addSitrep")}>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          run(() => api(`health-events/${eventId}/situation-reports`, { method: "POST", body: JSON.stringify(form) }));
        }}
        className="grid gap-3 md:grid-cols-3"
      >
        <Field label={c("date")}><input type="date" required className="input" value={form.report_date} onChange={(e) => setForm({ ...form, report_date: e.target.value })} /></Field>
        <Field label={c("summary")} className="md:col-span-3"><textarea required rows={3} className="input" value={form.summary} onChange={(e) => setForm({ ...form, summary: e.target.value })} /></Field>
        <Field label={c("actionsTaken")} className="md:col-span-3"><textarea rows={2} className="input" value={form.actions} onChange={(e) => setForm({ ...form, actions: e.target.value })} /></Field>
        <Field label={c("needs")} className="md:col-span-3"><textarea rows={2} className="input" value={form.needs} onChange={(e) => setForm({ ...form, needs: e.target.value })} /></Field>
        <div><button className="btn">{t("save")}</button></div>
      </form>
    </Card>
  );
}

function CloseForm({ eventId, run }: { eventId: string; run: Run }) {
  const { c } = useC();
  const [note, setNote] = useState("");
  return (
    <Card title={c("closeEvent")}>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          run(() => api(`health-events/${eventId}/close`, { method: "POST", body: JSON.stringify({ closure_note: note }) }));
        }}
        className="space-y-2"
      >
        <Field label={c("closureNote")}><textarea required rows={3} className="input" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
        <button className="btn-danger">{c("closeEvent")}</button>
      </form>
    </Card>
  );
}
