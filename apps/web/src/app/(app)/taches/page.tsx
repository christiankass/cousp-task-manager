"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { can, useMe } from "@/components/MeProvider";
import { OverdueBadge, PriorityLabel, StatusBadge } from "@/components/TaskBadges";
import { api, formatDate, type Task } from "@/lib/api";
import { useI18n } from "@/lib/i18n";

const STATUSES = ["todo", "in_progress", "on_hold", "submitted", "rejected", "validated"] as const;

export default function TasksPage() {
  const me = useMe();
  const { t, tx, locale } = useI18n();
  const [tasks, setTasks] = useState<Task[] | null>(null);
  const [filters, setFilters] = useState({ status: "", search: "", overdue: false, mine: false });

  useEffect(() => {
    if (typeof window !== "undefined" && new URLSearchParams(window.location.search).get("mine")) {
      setFilters((f) => ({ ...f, mine: true }));
    }
  }, []);

  useEffect(() => {
    const q = new URLSearchParams({ per_page: "50", sort: "due_at" });
    if (filters.status) q.set("status", filters.status);
    if (filters.search) q.set("search", filters.search);
    if (filters.overdue) q.set("overdue", "1");
    if (filters.mine) q.set("assignee", "me");
    const timer = setTimeout(() => api<Task[]>(`tasks?${q}`).then((r) => setTasks(r.data)), 250);
    return () => clearTimeout(timer);
  }, [filters]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold">{t("tasks")}</h1>
        {can(me, "tasks.create") && (
          <Link href="/taches/nouvelle" className="rounded-md bg-[var(--brand)] px-4 py-2 text-sm font-medium text-white">
            + {t("newTask")}
          </Link>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-3 text-sm">
        <input
          placeholder={t("search")}
          value={filters.search}
          onChange={(e) => setFilters({ ...filters, search: e.target.value })}
          className="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-1.5"
        />
        <select
          value={filters.status}
          onChange={(e) => setFilters({ ...filters, status: e.target.value })}
          className="rounded-md border border-slate-300 px-2 py-1.5"
        >
          <option value="">{t("filterAll")}</option>
          {STATUSES.map((s) => (
            <option key={s} value={s}>
              {tx(`s_${s}`)}
            </option>
          ))}
        </select>
        <label className="flex items-center gap-1">
          <input type="checkbox" checked={filters.overdue} onChange={(e) => setFilters({ ...filters, overdue: e.target.checked })} />
          {t("onlyOverdue")}
        </label>
        <label className="flex items-center gap-1">
          <input type="checkbox" checked={filters.mine} onChange={(e) => setFilters({ ...filters, mine: e.target.checked })} />
          {t("onlyMine")}
        </label>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="bg-slate-50 text-xs text-slate-600">
            <tr>
              <th className="p-3">{t("reference")}</th>
              <th className="p-3">{t("title")}</th>
              <th className="p-3">{t("status")}</th>
              <th className="p-3">{t("priority")}</th>
              <th className="p-3">{t("responsible")}</th>
              <th className="p-3">{t("dueAt")}</th>
            </tr>
          </thead>
          <tbody>
            {tasks === null ? (
              <tr>
                <td className="p-3" colSpan={6}>
                  {t("loading")}
                </td>
              </tr>
            ) : tasks.length === 0 ? (
              <tr>
                <td className="p-3 text-slate-500" colSpan={6}>
                  {t("noTasks")}
                </td>
              </tr>
            ) : (
              tasks.map((task) => (
                <tr key={task.id} className="border-t border-slate-100 hover:bg-slate-50">
                  <td className="whitespace-nowrap p-3 font-mono text-xs text-slate-500">{task.reference}</td>
                  <td className="p-3">
                    <Link href={`/taches/${task.id}`} className="font-medium hover:text-[var(--brand)]">
                      {task.title}
                    </Link>
                    <div className="text-xs text-slate-500">{task.organization_unit?.name}</div>
                  </td>
                  <td className="p-3">
                    <div className="flex flex-wrap gap-1">
                      <StatusBadge status={task.status} />
                      {task.is_overdue && <OverdueBadge />}
                    </div>
                  </td>
                  <td className="p-3">
                    <PriorityLabel priority={task.priority} />
                  </td>
                  <td className="p-3">{task.assignees?.find((a) => a.role === "responsible")?.name}</td>
                  <td className={`whitespace-nowrap p-3 ${task.is_overdue ? "text-red-700" : ""}`}>{formatDate(task.due_at, locale)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
