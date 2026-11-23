"use client";

import Link from "next/link";
import { use, useCallback, useEffect, useState } from "react";
import { StatusBadge } from "@/components/TaskBadges";
import { ApiError, api, formatDate } from "@/lib/api";
import {
  Card, ExecutionBadge, Field, FollowUpBar, Pill, UnitSelect, UserSelect, toIso, useC, useDirectory,
  type MeetingDetail, type Recommendation,
} from "@/lib/coordination";
import { useI18n } from "@/lib/i18n";

const ATTENDANCE = ["invited", "present", "absent", "excused"];

export default function MeetingPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { t, locale } = useI18n();
  const { c, cx } = useC();
  const [meeting, setMeeting] = useState<MeetingDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [flash, setFlash] = useState<string | null>(null);

  const load = useCallback(() => api<MeetingDetail>(`meetings/${id}`).then((r) => setMeeting(r.data)).catch((e) => setError(e.message)), [id]);
  useEffect(() => {
    load();
  }, [load]);

  /** Exécute une action, recharge, et affiche le message ou l'erreur du serveur. */
  const run = async (fn: () => Promise<unknown>) => {
    setError(null);
    try {
      await fn();
      setFlash(c("saved"));
      setTimeout(() => setFlash(null), 2500);
      await load();
      return true;
    } catch (e) {
      if (e instanceof ApiError) setError([e.message, ...Object.values(e.body.errors ?? {}).flat()].join(" "));
      return false;
    }
  };

  if (!meeting) return <p className="text-slate-500">{error ?? t("loading")}</p>;
  const manage = meeting.can.manage;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="font-mono text-xs text-slate-500">{meeting.reference}</div>
          <h1 className="text-2xl font-bold">{meeting.title}</h1>
          <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-600">
            <span>{cx(`mt_${meeting.meeting_type}`)}</span>
            <span>· {formatDate(meeting.starts_at, locale)}</span>
            {meeting.location && <span>· {meeting.location}</span>}
            <Pill tone={meeting.status === "held" ? "green" : meeting.status === "cancelled" ? "slate" : "blue"}>{cx(`ms_${meeting.status}`)}</Pill>
          </div>
          <div className="mt-1 text-sm text-slate-600">
            {meeting.chair && <span>{c("chair")} : {meeting.chair.name}. </span>}
            {meeting.secretary && <span>{c("secretary")} : {meeting.secretary.name}. </span>}
            {meeting.health_event && (
              <Link href={`/urgences/${meeting.health_event.id}`} className="text-[var(--brand)] hover:underline">
                {meeting.health_event.reference} · {meeting.health_event.title}
              </Link>
            )}
          </div>
        </div>
        <button className="btn-secondary no-print" onClick={() => window.print()}>{c("print")}</button>
      </div>

      {flash && <div className="no-print rounded-md bg-emerald-50 p-2 text-sm text-emerald-800">{flash}</div>}
      {error && <div className="no-print rounded-md bg-red-50 p-2 text-sm text-red-700">{error}</div>}

      <Card title={c("followUp")}>
        <FollowUpBar followUp={meeting.follow_up} />
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card title={c("agenda")}>
          <p className="whitespace-pre-line text-sm text-slate-700">{meeting.agenda || "—"}</p>
        </Card>
        <MinutesCard meeting={meeting} manage={manage} run={run} />
      </div>

      <Card title={`${c("recommendations")} (${meeting.recommendations.length})`}>
        <RecommendationTable items={meeting.recommendations} manage={manage} run={run} />
        {manage && <AddRecommendation meetingId={meeting.id} run={run} />}
      </Card>

      <ParticipantsCard meeting={meeting} manage={manage} run={run} />

      {meeting.previous_meeting && (
        <Card
          title={c("previousMeeting")}
          actions={
            <Link href={`/reunions/${meeting.previous_meeting.id}`} className="text-sm text-[var(--brand)] hover:underline">
              {meeting.previous_meeting.reference} · {formatDate(meeting.previous_meeting.starts_at, locale)}
            </Link>
          }
        >
          <div className="mb-3">
            <FollowUpBar followUp={meeting.previous_meeting.follow_up} />
          </div>
          <RecommendationTable items={meeting.previous_meeting.recommendations} manage={false} run={run} />
        </Card>
      )}
    </div>
  );
}

type Run = (fn: () => Promise<unknown>) => Promise<boolean>;

function MinutesCard({ meeting, manage, run }: { meeting: MeetingDetail; manage: boolean; run: Run }) {
  const { t } = useI18n();
  const { c, cx } = useC();
  const [editing, setEditing] = useState(false);
  const [minutes, setMinutes] = useState(meeting.minutes ?? "");
  const [status, setStatus] = useState(meeting.status);

  const save = async () => {
    if (await run(() => api(`meetings/${meeting.id}`, { method: "PUT", body: JSON.stringify({ minutes, status }) }))) setEditing(false);
  };

  return (
    <Card title={c("minutes")} actions={manage && !editing ? <button className="btn-secondary no-print" onClick={() => setEditing(true)}>{c("edit")}</button> : undefined}>
      {editing ? (
        <div className="space-y-2">
          <textarea rows={8} className="input" value={minutes} onChange={(e) => setMinutes(e.target.value)} />
          <div className="flex flex-wrap items-center gap-2">
            <select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value as MeetingDetail["status"])}>
              {["planned", "held", "cancelled"].map((s) => (
                <option key={s} value={s}>{cx(`ms_${s}`)}</option>
              ))}
            </select>
            <button className="btn" onClick={save}>{t("save")}</button>
            <button className="btn-secondary" onClick={() => setEditing(false)}>{t("cancel")}</button>
          </div>
        </div>
      ) : (
        <p className="whitespace-pre-line text-sm text-slate-700">{meeting.minutes || "—"}</p>
      )}
    </Card>
  );
}

function RecommendationTable({ items, manage, run }: { items: Recommendation[]; manage: boolean; run: Run }) {
  const { t, locale } = useI18n();
  const { c } = useC();
  const [converting, setConverting] = useState<string | null>(null);

  if (items.length === 0) return <p className="text-sm text-slate-500">{c("noRecommendations")}</p>;

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead className="text-xs text-slate-500">
          <tr>
            <th className="py-2 pr-3">N°</th>
            <th className="py-2 pr-3">{c("recommendation")}</th>
            <th className="py-2 pr-3">{t("responsible")}</th>
            <th className="py-2 pr-3">{t("dueAt")}</th>
            <th className="py-2 pr-3">{t("status")}</th>
            {manage && <th className="no-print py-2" />}
          </tr>
        </thead>
        <tbody>
          {items.map((r) => (
            <RecommendationRow key={r.id} r={r} manage={manage} run={run} open={converting === r.id} toggle={() => setConverting(converting === r.id ? null : r.id)} locale={locale} />
          ))}
        </tbody>
      </table>
    </div>
  );
}

function RecommendationRow({ r, manage, run, open, toggle, locale }: { r: Recommendation; manage: boolean; run: Run; open: boolean; toggle: () => void; locale: string }) {
  const { c } = useC();
  const cols = manage ? 6 : 5;
  return (
    <>
      <tr className="border-t border-slate-100 align-top">
        <td className="py-2 pr-3 font-mono text-xs">R{String(r.number).padStart(2, "0")}</td>
        <td className="py-2 pr-3">
          <div>{r.content}</div>
          {r.organization_unit && <div className="text-xs text-slate-500">{r.organization_unit.name}</div>}
          {r.tasks.map((task) => (
            <div key={task.id} className="mt-1 flex flex-wrap items-center gap-2 text-xs">
              <Link href={`/taches/${task.id}`} className="font-mono text-[var(--brand)] hover:underline">{task.reference}</Link>
              <StatusBadge status={task.status} />
              {task.responsible && <span className="text-slate-500">{task.responsible.name}</span>}
            </div>
          ))}
        </td>
        <td className="py-2 pr-3">{r.tasks[0]?.responsible?.name ?? r.responsible?.name ?? "—"}</td>
        <td className={`whitespace-nowrap py-2 pr-3 ${r.execution_status === "overdue" ? "font-medium text-red-600" : ""}`}>{formatDate(r.tasks[0]?.due_at ?? r.due_at, locale)}</td>
        <td className="py-2 pr-3"><ExecutionBadge status={r.execution_status} /></td>
        {manage && (
          <td className="no-print whitespace-nowrap py-2 text-right">
            {r.status !== "cancelled" && (
              <button className="btn-secondary" onClick={toggle}>{r.tasks.length > 0 ? c("addTask") : c("convert")}</button>
            )}
            {r.status === "open" && (
              <button className="ml-1 text-xs text-slate-500 hover:text-red-600" onClick={() => run(() => api(`recommendations/${r.id}`, { method: "PUT", body: JSON.stringify({ status: "cancelled" }) }))}>
                {c("dropRecommendation")}
              </button>
            )}
          </td>
        )}
      </tr>
      {open && (
        <tr>
          <td colSpan={cols} className="pb-3">
            <ConvertForm r={r} run={run} done={toggle} />
          </td>
        </tr>
      )}
    </>
  );
}

function ConvertForm({ r, run, done }: { r: Recommendation; run: Run; done: () => void }) {
  const { t, tx } = useI18n();
  const { c } = useC();
  const { units, users } = useDirectory();
  const [form, setForm] = useState({
    organization_unit_id: r.organization_unit?.id ?? "",
    responsible_id: r.responsible?.id ?? "",
    due_at: r.due_at ? r.due_at.slice(0, 16) : "",
    priority: r.priority ?? "normal",
    expected_result: r.expected_result ?? "",
  });

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const body = Object.fromEntries(Object.entries({ ...form, due_at: form.due_at ? toIso(form.due_at) : undefined }).filter(([, v]) => v));
    if (await run(() => api(`recommendations/${r.id}/convert-to-task`, { method: "POST", body: JSON.stringify(body) }))) done();
  };

  return (
    <form onSubmit={submit} className="grid gap-2 rounded-lg bg-slate-50 p-3 md:grid-cols-5">
      <Field label={t("unit")}><UnitSelect units={units} value={form.organization_unit_id} onChange={(v) => setForm({ ...form, organization_unit_id: v })} required /></Field>
      <Field label={t("responsible")}><UserSelect users={users} value={form.responsible_id} onChange={(v) => setForm({ ...form, responsible_id: v })} required /></Field>
      <Field label={t("dueAt")}><input type="datetime-local" className="input" value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })} /></Field>
      <Field label={t("priority")}>
        <select className="input" value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value as typeof form.priority })}>
          {["low", "normal", "high", "critical"].map((p) => <option key={p} value={p}>{tx(`p_${p}`)}</option>)}
        </select>
      </Field>
      <Field label={t("expectedResult")}><input className="input" value={form.expected_result} onChange={(e) => setForm({ ...form, expected_result: e.target.value })} /></Field>
      <div className="flex items-center gap-2 md:col-span-5">
        <button className="btn">{c("convert")}</button>
        <span className="text-xs text-slate-500">{c("convertHint")}</span>
      </div>
    </form>
  );
}

function AddRecommendation({ meetingId, run }: { meetingId: string; run: Run }) {
  const { t } = useI18n();
  const { c } = useC();
  const { units, users } = useDirectory();
  const empty = { content: "", organization_unit_id: "", responsible_id: "", due_at: "" };
  const [form, setForm] = useState(empty);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const body = Object.fromEntries(Object.entries({ ...form, due_at: toIso(form.due_at) }).filter(([, v]) => v));
    if (await run(() => api(`meetings/${meetingId}/recommendations`, { method: "POST", body: JSON.stringify(body) }))) setForm(empty);
  };

  return (
    <form onSubmit={submit} className="no-print mt-4 grid gap-2 border-t border-slate-100 pt-4 md:grid-cols-4">
      <Field label={c("addRecommendation")} className="md:col-span-4">
        <textarea required rows={2} className="input" value={form.content} onChange={(e) => setForm({ ...form, content: e.target.value })} />
      </Field>
      <Field label={t("unit")}><UnitSelect units={units} value={form.organization_unit_id} onChange={(v) => setForm({ ...form, organization_unit_id: v })} /></Field>
      <Field label={t("responsible")}><UserSelect users={users} value={form.responsible_id} onChange={(v) => setForm({ ...form, responsible_id: v })} /></Field>
      <Field label={t("dueAt")}><input type="datetime-local" className="input" value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })} /></Field>
      <div className="flex items-end"><button className="btn">+ {c("addRecommendation")}</button></div>
    </form>
  );
}

function ParticipantsCard({ meeting, manage, run }: { meeting: MeetingDetail; manage: boolean; run: Run }) {
  const { c, cx } = useC();
  const { users } = useDirectory();
  const [form, setForm] = useState({ user_id: "", external_name: "", organization: "" });

  const add = async (e: React.FormEvent) => {
    e.preventDefault();
    const p = Object.fromEntries(Object.entries({ ...form, attendance: "present" }).filter(([, v]) => v));
    if (await run(() => api(`meetings/${meeting.id}/participants`, { method: "POST", body: JSON.stringify({ participants: [p] }) }))) {
      setForm({ user_id: "", external_name: "", organization: "" });
    }
  };

  return (
    <Card title={`${c("participants")} (${meeting.participants.length})`}>
      <ul className="divide-y divide-slate-100 text-sm">
        {meeting.participants.map((p) => (
          <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
            <span>
              {p.user?.name ?? p.external_name}
              {p.organization && <span className="text-slate-500"> · {p.organization}</span>}
            </span>
            {manage ? (
              <span className="flex items-center gap-2">
                <select
                  className="input w-auto"
                  value={p.attendance}
                  onChange={(e) => run(() => api(`meetings/${meeting.id}/participants/${p.id}`, { method: "PATCH", body: JSON.stringify({ attendance: e.target.value }) }))}
                >
                  {ATTENDANCE.map((a) => <option key={a} value={a}>{cx(`a_${a}`)}</option>)}
                </select>
                <button className="no-print text-xs text-slate-400 hover:text-red-600" onClick={() => run(() => api(`meetings/${meeting.id}/participants/${p.id}`, { method: "DELETE" }))}>✕</button>
              </span>
            ) : (
              <Pill tone={p.attendance === "present" ? "green" : "slate"}>{cx(`a_${p.attendance}`)}</Pill>
            )}
          </li>
        ))}
      </ul>
      {manage && (
        <form onSubmit={add} className="no-print mt-3 grid gap-2 md:grid-cols-4">
          <UserSelect users={users} value={form.user_id} onChange={(v) => setForm({ ...form, user_id: v, external_name: "" })} emptyLabel={`— ${c("participants")} —`} />
          <input className="input" placeholder={c("externalName")} value={form.external_name} disabled={!!form.user_id} onChange={(e) => setForm({ ...form, external_name: e.target.value })} />
          <input className="input" placeholder={c("organization")} value={form.organization} onChange={(e) => setForm({ ...form, organization: e.target.value })} />
          <button className="btn" disabled={!form.user_id && !form.external_name}>+ {c("addParticipant")}</button>
        </form>
      )}
    </Card>
  );
}
