"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useMe } from "@/components/MeProvider";
import { OverdueBadge, StatusBadge } from "@/components/TaskBadges";
import { api, formatDate, type Dashboard, type Task } from "@/lib/api";
import { useI18n } from "@/lib/i18n";

function Kpi({ label, value, tone = "slate", hint }: { label: string; value: string | number; tone?: string; hint?: string }) {
  const tones: Record<string, string> = {
    slate: "text-slate-900",
    red: "text-red-700",
    green: "text-emerald-700",
    blue: "text-[var(--brand)]",
  };
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="text-xs uppercase tracking-wide text-slate-500">{label}</div>
      <div className={`mt-1 text-3xl font-bold ${tones[tone]}`}>{value}</div>
      {hint && <div className="mt-1 text-xs text-slate-500">{hint}</div>}
    </div>
  );
}

export default function DashboardPage() {
  const me = useMe();
  const { t, locale } = useI18n();
  const [data, setData] = useState<Dashboard | null>(null);
  const [mine, setMine] = useState<Task[]>([]);
  const [toValidate, setToValidate] = useState<Task[]>([]);

  useEffect(() => {
    api<Dashboard>("dashboard/overview").then((r) => setData(r.data));
    api<Task[]>("tasks?assignee=me&status=todo,in_progress,on_hold,rejected&sort=due_at&per_page=8").then((r) => setMine(r.data));
    api<Task[]>("tasks?status=submitted&sort=due_at&per_page=50").then((r) => setToValidate(r.data.filter((x) => x.can?.decide)));
  }, []);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">
          {t("welcome")}, {me.user.name}
        </h1>
        <p className="text-sm text-slate-500">{me.institution.name}</p>
      </div>

      {data && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
          <Kpi label={t("total")} value={data.total} tone="blue" />
          <Kpi label={t("s_in_progress")} value={data.by_status.in_progress} />
          <Kpi label={t("overdue")} value={data.overdue} tone="red" />
          <Kpi label={t("critical")} value={data.critical_open} tone="red" />
          <Kpi
            label={t("executionRate")}
            value={data.execution_rate === null ? "—" : `${data.execution_rate} %`}
            tone="green"
            hint={data.execution_rate === null ? t("notDue") : undefined}
          />
        </div>
      )}

      {toValidate.length > 0 && (
        <section className="rounded-xl border border-violet-200 bg-violet-50 p-5">
          <h2 className="mb-3 font-semibold text-violet-900">
            {t("toValidate")} ({toValidate.length})
          </h2>
          <ul className="divide-y divide-violet-100">
            {toValidate.map((task) => (
              <li key={task.id} className="py-2">
                <Link href={`/taches/${task.id}`} className="flex flex-wrap items-center justify-between gap-2 hover:text-[var(--brand)]">
                  <span className="text-sm font-medium">{task.title}</span>
                  <span className="text-xs text-slate-500">
                    {task.assignees?.find((a) => a.role === "responsible")?.name} · {formatDate(task.due_at, locale)}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        <section className="rounded-xl border border-slate-200 bg-white p-5">
          <div className="mb-3 flex items-center justify-between">
            <h2 className="font-semibold">{t("myTasks")}</h2>
            <Link href="/taches?mine=1" className="text-sm text-[var(--brand)] hover:underline">
              →
            </Link>
          </div>
          {mine.length === 0 ? (
            <p className="text-sm text-slate-500">{t("noTasks")}</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {mine.map((task) => (
                <li key={task.id} className="py-2">
                  <Link href={`/taches/${task.id}`} className="block hover:text-[var(--brand)]">
                    <div className="text-sm font-medium">{task.title}</div>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                      <StatusBadge status={task.status} />
                      {task.is_overdue && <OverdueBadge />}
                      <span>
                        {t("dueAt")} : {formatDate(task.due_at, locale)}
                      </span>
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </section>

        {data && data.by_unit.length > 0 && (
          <section className="rounded-xl border border-slate-200 bg-white p-5">
            <h2 className="mb-3 font-semibold">{t("byUnit")}</h2>
            <table className="w-full text-sm">
              <thead className="text-left text-xs text-slate-500">
                <tr>
                  <th className="pb-2">{t("unit")}</th>
                  <th className="pb-2 text-right">{t("total")}</th>
                  <th className="pb-2 text-right">{t("s_validated")}</th>
                  <th className="pb-2 text-right">{t("overdue")}</th>
                </tr>
              </thead>
              <tbody>
                {data.by_unit.map((u) => (
                  <tr key={u.id} className="border-t border-slate-100">
                    <td className="py-2">{u.name}</td>
                    <td className="py-2 text-right">{u.total}</td>
                    <td className="py-2 text-right text-emerald-700">{u.validated}</td>
                    <td className="py-2 text-right text-red-700">{u.overdue}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
        )}
      </div>
    </div>
  );
}
