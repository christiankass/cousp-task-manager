"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { can, useMe } from "@/components/MeProvider";
import { OverdueBadge, StatusBadge } from "@/components/TaskBadges";
import { ApiError, api, formatDate } from "@/lib/api";
import { Card, Field, UnitSelect, useDirectory } from "@/lib/coordination";
import { useI18n } from "@/lib/i18n";
import { DownloadButton, useL, type TaskReport } from "@/lib/lot4";

const firstOfMonth = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
};
const todayIso = () => new Date().toLocaleDateString("sv-SE");

export default function ReportsPage() {
  const me = useMe();
  const { locale } = useI18n();
  const { l, lx } = useL();
  const { units } = useDirectory();
  const [filters, setFilters] = useState({ from: firstOfMonth(), to: todayIso(), organization_unit_id: "", task_type: "" });
  const [report, setReport] = useState<TaskReport | null>(null);
  const [error, setError] = useState<string | null>(null);

  const query = useMemo(() => {
    const q = new URLSearchParams();
    Object.entries(filters).forEach(([k, v]) => v && q.set(k, v));
    return q.toString();
  }, [filters]);

  useEffect(() => {
    setError(null);
    const timer = setTimeout(
      () =>
        api<TaskReport>(`reports/tasks?${query}`)
          .then((r) => setReport(r.data))
          .catch((e) => setError(e instanceof ApiError ? [e.message, ...Object.values(e.body.errors ?? {}).flat()].join(" ") : String(e))),
      250,
    );
    return () => clearTimeout(timer);
  }, [query]);

  const canExport = can(me, "reports.export");
  const i = report?.indicators;
  const pct = (v: number | null | undefined) => (v === null || v === undefined ? "—" : `${v} %`);
  const inProgress = i ? i.by_status.in_progress + i.by_status.on_hold + i.by_status.submitted + i.by_status.rejected : 0;

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold">{l("reportsTitle")}</h1>
        <p className="text-sm text-slate-600">{l("reportsIntro")}</p>
      </div>

      <Card>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Field label={l("from")}>
            <input type="date" className="input" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
          </Field>
          <Field label={l("to")}>
            <input type="date" className="input" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
          </Field>
          <Field label={l("unit")}>
            <UnitSelect value={filters.organization_unit_id} onChange={(v) => setFilters({ ...filters, organization_unit_id: v })} units={units} emptyLabel={l("allUnits")} />
          </Field>
          <Field label={l("taskType")}>
            <select className="input" value={filters.task_type} onChange={(e) => setFilters({ ...filters, task_type: e.target.value })}>
              <option value="">{l("allTypes")}</option>
              <option value="administrative">{l("tt_administrative")}</option>
              <option value="operational">{l("tt_operational")}</option>
            </select>
          </Field>
        </div>
        <div className="mt-4 flex flex-wrap items-center gap-2">
          {canExport ? (
            <>
              <DownloadButton path={`reports/tasks?${query}&format=pdf`} label={l("downloadPdf")} className="btn" />
              <DownloadButton path={`reports/tasks?${query}&format=xlsx`} label={l("downloadXlsx")} />
            </>
          ) : (
            <p className="text-sm text-slate-500">{l("noExportRight")}</p>
          )}
        </div>
      </Card>

      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      {i && report && (
        <>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-7">
            {[
              [l("totalTasks"), i.total, ""],
              [l("validated"), i.by_status.validated, "text-emerald-700"],
              [l("inProgress"), inProgress, "text-blue-700"],
              [l("overdue"), i.overdue, "text-red-700"],
              [l("criticalOpen"), i.critical_open, "text-orange-700"],
              [l("executionRate"), pct(i.execution_rate), ""],
              [l("validationRate"), pct(i.validation_rate), ""],
            ].map(([label, value, tone]) => (
              <div key={String(label)} className="rounded-xl border border-slate-200 bg-white p-3">
                <div className="text-xs text-slate-500">{label}</div>
                <div className={`text-2xl font-bold ${tone}`}>{value}</div>
              </div>
            ))}
          </div>

          <Card title={l("byUnit")}>
            {report.by_unit.length === 0 ? (
              <p className="text-sm text-slate-500">{l("noTasks")}</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="text-xs text-slate-500">
                    <tr>
                      <th className="py-2 pr-3">{l("unitName")}</th>
                      <th className="py-2 pr-3 text-right">{l("totalTasks")}</th>
                      <th className="py-2 pr-3 text-right">{l("validated")}</th>
                      <th className="py-2 pr-3 text-right">{l("inProgress")}</th>
                      <th className="py-2 pr-3 text-right">{l("overdue")}</th>
                      <th className="py-2 pr-3">{l("executionRate")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {report.by_unit.map((u) => (
                      <tr key={u.id} className="border-t border-slate-100">
                        <td className="py-2 pr-3 font-medium">{u.name}</td>
                        <td className="py-2 pr-3 text-right">{u.total}</td>
                        <td className="py-2 pr-3 text-right">{u.validated}</td>
                        <td className="py-2 pr-3 text-right">{u.in_progress}</td>
                        <td className={`py-2 pr-3 text-right ${u.overdue ? "font-semibold text-red-700" : ""}`}>{u.overdue}</td>
                        <td className="py-2 pr-3">
                          <div className="flex items-center gap-2">
                            <div className="h-2 w-24 overflow-hidden rounded-full bg-slate-100">
                              <div className="h-full bg-emerald-500" style={{ width: `${u.execution_rate ?? 0}%` }} />
                            </div>
                            <span className="text-xs text-slate-600">{pct(u.execution_rate)}</span>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>

          <Card title={`${l("tasksList")} (${report.tasks.length})`}>
            {report.truncated && <p className="mb-2 rounded bg-amber-50 p-2 text-sm text-amber-800">{l("truncated")}</p>}
            {report.tasks.length === 0 ? (
              <p className="text-sm text-slate-500">{l("noTasks")}</p>
            ) : (
              <div className="max-h-[32rem] overflow-auto">
                <table className="w-full text-left text-sm">
                  <thead className="sticky top-0 bg-white text-xs text-slate-500">
                    <tr>
                      <th className="py-2 pr-3">{l("reference")}</th>
                      <th className="py-2 pr-3">{l("title")}</th>
                      <th className="py-2 pr-3">{l("status")}</th>
                      <th className="py-2 pr-3">{l("responsible")}</th>
                      <th className="py-2 pr-3">{l("dueAt")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {report.tasks.map((t) => (
                      <tr key={t.id} className="border-t border-slate-100">
                        <td className="whitespace-nowrap py-2 pr-3 font-mono text-xs text-slate-500">{t.reference}</td>
                        <td className="py-2 pr-3">
                          <Link href={`/taches/${t.id}`} className="font-medium hover:text-[var(--brand)]">
                            {t.title}
                          </Link>
                          <div className="text-xs text-slate-500">
                            {t.organization_unit?.name} · {lx(`p_${t.priority}`)}
                          </div>
                        </td>
                        <td className="py-2 pr-3">
                          <div className="flex flex-wrap gap-1">
                            <StatusBadge status={t.status} />
                            {t.is_overdue && <OverdueBadge />}
                          </div>
                        </td>
                        <td className="py-2 pr-3">{t.responsible?.name ?? "—"}</td>
                        <td className={`whitespace-nowrap py-2 pr-3 ${t.is_overdue ? "text-red-700" : ""}`}>{formatDate(t.due_at, locale)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </>
      )}
    </div>
  );
}
