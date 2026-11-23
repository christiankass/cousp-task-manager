"use client";

import Link from "next/link";
import { use, useCallback, useEffect, useState } from "react";
import { StatusBadge } from "@/components/TaskBadges";
import { ApiError, api, formatDate } from "@/lib/api";
import { Card, Field, Pill, UserSelect, missionTone, useC, useDirectory, type Mission } from "@/lib/coordination";
import { useI18n } from "@/lib/i18n";

type MissionDetail = Mission & { can: { manage: boolean; report: boolean } };

export default function MissionPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { t, locale } = useI18n();
  const { c, cx } = useC();
  const { users } = useDirectory();
  const [mission, setMission] = useState<MissionDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [report, setReport] = useState("");
  const [member, setMember] = useState({ user_id: "", assignment: "" });

  const load = useCallback(() => api<MissionDetail>(`missions/${id}`).then((r) => setMission(r.data)).catch((e) => setError(e.message)), [id]);
  useEffect(() => {
    load();
  }, [load]);

  const run = async (fn: () => Promise<unknown>) => {
    setError(null);
    try {
      await fn();
      await load();
      return true;
    } catch (e) {
      if (e instanceof ApiError) setError([e.message, ...Object.values(e.body.errors ?? {}).flat()].join(" "));
      return false;
    }
  };

  if (!mission) return <p className="text-slate-500">{error ?? t("loading")}</p>;
  const closed = mission.status === "completed" || mission.status === "report_submitted";

  return (
    <div className="space-y-4">
      <div>
        <div className="font-mono text-xs text-slate-500">{mission.reference}</div>
        <h1 className="text-2xl font-bold">{mission.title}</h1>
        <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-600">
          <Pill tone={missionTone(mission.status)}>{cx(`mis_${mission.status}`)}</Pill>
          <span>{mission.destination}</span>
          <span>· {formatDate(mission.departure_at, locale)} → {formatDate(mission.return_at, locale)}</span>
        </div>
        {mission.health_event && (
          <Link href={`/urgences/${mission.health_event.id}`} className="mt-1 inline-block text-sm text-[var(--brand)] hover:underline">
            {mission.health_event.reference} · {mission.health_event.title}
          </Link>
        )}
      </div>

      {error && <div className="rounded-md bg-red-50 p-2 text-sm text-red-700">{error}</div>}

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Card title={c("objectives")}>
            <p className="whitespace-pre-line text-sm text-slate-700">{mission.objectives}</p>
          </Card>

          <Card title={c("report")}>
            {mission.report ? (
              <>
                <p className="whitespace-pre-line text-sm text-slate-700">{mission.report}</p>
                <p className="mt-2 text-xs text-slate-500">{formatDate(mission.report_submitted_at, locale)}</p>
              </>
            ) : (
              !mission.can.report && <p className="text-sm text-slate-500">—</p>
            )}
            {mission.can.report && (
              <form
                className="mt-3 space-y-2"
                onSubmit={async (e) => {
                  e.preventDefault();
                  if (await run(() => api(`missions/${mission.id}/report`, { method: "POST", body: JSON.stringify({ report }) }))) setReport("");
                }}
              >
                <textarea required rows={6} className="input" value={report} onChange={(e) => setReport(e.target.value)} />
                <div className="flex items-center gap-2">
                  <button className="btn">{c("submitReport")}</button>
                  <span className="text-xs text-slate-500">{c("reportHint")}</span>
                </div>
              </form>
            )}
          </Card>
        </div>

        <div className="space-y-4">
          {mission.task && (
            <Card title={c("supportTask")}>
              <Link href={`/taches/${mission.task.id}`} className="font-mono text-sm text-[var(--brand)] hover:underline">{mission.task.reference}</Link>
              <div className="mt-1 flex items-center gap-2"><StatusBadge status={mission.task.status} /></div>
              <p className="mt-2 text-xs text-slate-500">{c("reportHint")}</p>
            </Card>
          )}
          <Card title={c("team")}>
            <ul className="space-y-1 text-sm">
              <li><b>{mission.leader?.name}</b> <span className="text-xs text-slate-500">· {c("leader")}</span></li>
              {mission.members?.map((m) => (
                <li key={m.id}>{m.user?.name} {m.assignment && <span className="text-xs text-slate-500">· {m.assignment}</span>}</li>
              ))}
            </ul>
            {mission.can.manage && !closed && (
              <form
                className="mt-3 space-y-2"
                onSubmit={async (e) => {
                  e.preventDefault();
                  if (await run(() => api(`missions/${mission.id}/members`, { method: "POST", body: JSON.stringify({ user_id: member.user_id, assignment: member.assignment || undefined }) }))) {
                    setMember({ user_id: "", assignment: "" });
                  }
                }}
              >
                <UserSelect users={users.filter((u) => u.id !== mission.leader?.id && !mission.members?.some((m) => m.user?.id === u.id))} value={member.user_id} onChange={(v) => setMember({ ...member, user_id: v })} required />
                <Field label={c("role")}><input className="input" value={member.assignment} onChange={(e) => setMember({ ...member, assignment: e.target.value })} /></Field>
                <button className="btn-secondary">+ {c("addMember")}</button>
              </form>
            )}
          </Card>
        </div>
      </div>
    </div>
  );
}
