"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { StatusBadge } from "@/components/TaskBadges";
import { api, formatDate } from "@/lib/api";
import { ExecutionBadge, FollowUpBar, useC, type FollowUp, type Recommendation } from "@/lib/coordination";
import { useI18n } from "@/lib/i18n";

const STATUSES = ["not_converted", "in_progress", "overdue", "done", "cancelled"];

export default function RecommendationsPage() {
  const { t, locale } = useI18n();
  const { c, cx } = useC();
  const [status, setStatus] = useState("");
  const [items, setItems] = useState<Recommendation[] | null>(null);
  const [summary, setSummary] = useState<FollowUp | null>(null);

  useEffect(() => {
    api<Recommendation[]>(`recommendations${status ? `?execution_status=${status}` : ""}`).then((r) => {
      setItems(r.data);
      setSummary((r.meta as unknown as { summary?: FollowUp } | undefined)?.summary ?? null);
    });
  }, [status]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold">{c("recommendations")}</h1>
        <Link href="/reunions" className="btn-secondary">{t("meetings")}</Link>
      </div>
      <div className="flex flex-wrap items-center gap-4 rounded-xl border border-slate-200 bg-white p-3">
        <select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">{t("filterAll")}</option>
          {STATUSES.map((s) => <option key={s} value={s}>{cx(`es_${s}`)}</option>)}
        </select>
        {!status && <FollowUpBar followUp={summary} />}
      </div>
      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="bg-slate-50 text-xs text-slate-600">
            <tr>
              <th className="p-3">{t("reference")}</th>
              <th className="p-3">{c("recommendation")}</th>
              <th className="p-3">{t("responsible")}</th>
              <th className="p-3">{t("dueAt")}</th>
              <th className="p-3">{t("status")}</th>
            </tr>
          </thead>
          <tbody>
            {items === null && <tr><td colSpan={5} className="p-3 text-slate-500">{t("loading")}</td></tr>}
            {items?.length === 0 && <tr><td colSpan={5} className="p-3 text-slate-500">{t("noData")}</td></tr>}
            {items?.map((r) => (
              <tr key={r.id} className="border-t border-slate-100 align-top">
                <td className="p-3 font-mono text-xs">
                  <Link href={`/reunions/${r.meeting_id}`} className="text-[var(--brand)] hover:underline">{r.reference}</Link>
                  {r.meeting && <div className="mt-1 font-sans text-slate-500">{formatDate(r.meeting.starts_at, locale)}</div>}
                </td>
                <td className="p-3">
                  {r.content}
                  {r.tasks.map((task) => (
                    <div key={task.id} className="mt-1 flex items-center gap-2 text-xs">
                      <Link href={`/taches/${task.id}`} className="font-mono text-[var(--brand)] hover:underline">{task.reference}</Link>
                      <StatusBadge status={task.status} />
                    </div>
                  ))}
                </td>
                <td className="p-3">{r.tasks[0]?.responsible?.name ?? r.responsible?.name ?? "—"}</td>
                <td className="whitespace-nowrap p-3">{formatDate(r.tasks[0]?.due_at ?? r.due_at, locale)}</td>
                <td className="p-3"><ExecutionBadge status={r.execution_status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
