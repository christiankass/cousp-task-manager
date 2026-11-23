"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { OverdueBadge, PriorityLabel } from "@/components/TaskBadges";
import { api, type Task, type TaskStatus } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { useL } from "@/lib/lot4";

const COLUMNS: TaskStatus[] = ["todo", "in_progress", "on_hold", "submitted", "rejected", "validated"];
const DOT: Record<TaskStatus, string> = {
  todo: "border-l-slate-400",
  in_progress: "border-l-blue-500",
  on_hold: "border-l-amber-500",
  submitted: "border-l-violet-500",
  validated: "border-l-emerald-500",
  rejected: "border-l-orange-500",
};
const pad = (n: number) => String(n).padStart(2, "0");
const dayKey = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

/** Charge jusqu'à 500 tâches (5 pages de 100) pour la vue demandée. */
async function loadTasks(params: URLSearchParams): Promise<Task[]> {
  const all: Task[] = [];
  for (let page = 1; page <= 5; page++) {
    params.set("page", String(page));
    const r = await api<Task[]>(`tasks?${params}`);
    all.push(...r.data);
    if (page >= (r.meta?.pagination?.last_page ?? 1)) break;
  }
  return all;
}

export default function PlanningPage() {
  const { tx, locale } = useI18n();
  const { l } = useL();
  const [view, setView] = useState<"calendar" | "kanban">("calendar");
  const [mine, setMine] = useState(false);
  const [month, setMonth] = useState(() => {
    const d = new Date();
    return new Date(d.getFullYear(), d.getMonth(), 1);
  });
  const [tasks, setTasks] = useState<Task[] | null>(null);

  useEffect(() => {
    if (typeof window !== "undefined" && new URLSearchParams(window.location.search).get("vue") === "kanban") setView("kanban");
  }, []);

  useEffect(() => {
    setTasks(null);
    const q = new URLSearchParams({ per_page: "100", sort: "due_at" });
    if (mine) q.set("assignee", "me");
    if (view === "calendar") {
      const start = new Date(month.getFullYear(), month.getMonth(), 1);
      const end = new Date(month.getFullYear(), month.getMonth() + 1, 1);
      q.set("due_from", start.toISOString());
      q.set("due_to", new Date(end.getTime() - 1).toISOString());
    }
    let cancelled = false;
    loadTasks(q).then((t) => !cancelled && setTasks(t)).catch(() => !cancelled && setTasks([]));
    return () => {
      cancelled = true;
    };
  }, [view, month, mine]);

  const fmtLocale = locale === "en" ? "en-GB" : "fr-FR";

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold">{l("planningTitle")}</h1>
        <div className="flex flex-wrap items-center gap-3 text-sm">
          <label className="flex items-center gap-1">
            <input type="checkbox" checked={mine} onChange={(e) => setMine(e.target.checked)} />
            {l("onlyMine")}
          </label>
          <div className="inline-flex overflow-hidden rounded-md border border-slate-300 bg-white">
            {(["calendar", "kanban"] as const).map((v) => (
              <button
                key={v}
                onClick={() => setView(v)}
                className={`px-3 py-1.5 ${view === v ? "bg-[var(--brand)] text-white" : "text-slate-700 hover:bg-slate-100"}`}
              >
                {l(v)}
              </button>
            ))}
          </div>
        </div>
      </div>

      {view === "calendar" ? (
        <Calendar month={month} setMonth={setMonth} tasks={tasks} fmtLocale={fmtLocale} />
      ) : (
        <div className="space-y-2">
          <p className="text-sm text-slate-500">{l("kanbanHint")}</p>
          <div className="grid gap-3 overflow-x-auto pb-2 md:grid-cols-3 xl:grid-cols-6">
            {COLUMNS.map((status) => {
              const items = (tasks ?? []).filter((t) => t.status === status);
              return (
                <section key={status} className="flex min-w-56 flex-col rounded-xl bg-slate-100 p-2">
                  <h2 className="mb-2 flex items-center justify-between px-1 text-sm font-semibold text-slate-700">
                    {tx(`s_${status}`)}
                    <span className="rounded-full bg-white px-2 text-xs text-slate-500">{items.length}</span>
                  </h2>
                  <div className="space-y-2">
                    {tasks === null ? (
                      <p className="px-1 text-xs text-slate-400">…</p>
                    ) : items.length === 0 ? (
                      <p className="px-1 text-xs text-slate-400">{l("emptyColumn")}</p>
                    ) : (
                      items.map((task) => (
                        <Link
                          key={task.id}
                          href={`/taches/${task.id}`}
                          className={`block rounded-lg border border-l-4 border-slate-200 bg-white p-2 text-sm shadow-sm hover:border-[var(--brand)] ${DOT[task.status]}`}
                        >
                          <div className="font-medium leading-snug">{task.title}</div>
                          <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                            <PriorityLabel priority={task.priority} />
                            <span>
                              {task.due_at
                                ? new Date(task.due_at).toLocaleDateString(fmtLocale, { day: "2-digit", month: "short" })
                                : l("noDueDate")}
                            </span>
                            {task.is_overdue && <OverdueBadge />}
                          </div>
                          <div className="mt-1 truncate text-xs text-slate-500">
                            {task.assignees?.find((a) => a.role === "responsible")?.name} · {task.organization_unit?.name}
                          </div>
                        </Link>
                      ))
                    )}
                  </div>
                </section>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}

function Calendar({ month, setMonth, tasks, fmtLocale }: { month: Date; setMonth: (d: Date) => void; tasks: Task[] | null; fmtLocale: string }) {
  const { l } = useL();
  const [openDay, setOpenDay] = useState<string | null>(null);

  const byDay = useMemo(() => {
    const map = new Map<string, Task[]>();
    for (const t of tasks ?? []) {
      if (!t.due_at) continue;
      const k = dayKey(new Date(t.due_at));
      map.set(k, [...(map.get(k) ?? []), t]);
    }
    return map;
  }, [tasks]);

  // Grille du lundi au dimanche couvrant tout le mois.
  const first = new Date(month.getFullYear(), month.getMonth(), 1);
  const offset = (first.getDay() + 6) % 7;
  const gridStart = new Date(first.getFullYear(), first.getMonth(), 1 - offset);
  const daysInMonth = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
  const cells = Math.ceil((offset + daysInMonth) / 7) * 7;
  const days = Array.from({ length: cells }, (_, i) => new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + i));
  const weekdayNames = days.slice(0, 7).map((d) => d.toLocaleDateString(fmtLocale, { weekday: "short" }));
  const todayKey = dayKey(new Date());

  return (
    <div className="rounded-xl border border-slate-200 bg-white">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 p-3">
        <h2 className="text-lg font-semibold capitalize">{month.toLocaleDateString(fmtLocale, { month: "long", year: "numeric" })}</h2>
        <div className="flex gap-2">
          <button className="btn-secondary" onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() - 1, 1))}>
            ‹ {l("prev")}
          </button>
          <button className="btn-secondary" onClick={() => setMonth(new Date(new Date().getFullYear(), new Date().getMonth(), 1))}>
            {l("today")}
          </button>
          <button className="btn-secondary" onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() + 1, 1))}>
            {l("next")} ›
          </button>
        </div>
      </div>
      <div className="overflow-x-auto">
        <div className="grid min-w-[44rem] grid-cols-7">
          {weekdayNames.map((n) => (
            <div key={n} className="border-b border-slate-200 p-2 text-center text-xs font-medium uppercase text-slate-500">
              {n}
            </div>
          ))}
          {days.map((d) => {
            const k = dayKey(d);
            const items = byDay.get(k) ?? [];
            const inMonth = d.getMonth() === month.getMonth();
            const shown = openDay === k ? items : items.slice(0, 3);
            return (
              <div key={k} className={`min-h-28 border-b border-r border-slate-100 p-1.5 ${inMonth ? "" : "bg-slate-50 text-slate-400"}`}>
                <div className={`mb-1 text-right text-xs ${k === todayKey ? "font-bold text-[var(--brand)]" : ""}`}>
                  {k === todayKey ? <span className="rounded-full bg-[var(--brand)] px-1.5 py-0.5 text-white">{d.getDate()}</span> : d.getDate()}
                </div>
                <div className="space-y-1">
                  {shown.map((t) => (
                    <Link
                      key={t.id}
                      href={`/taches/${t.id}`}
                      title={t.title}
                      className={`block truncate rounded border-l-4 px-1.5 py-0.5 text-xs hover:bg-slate-100 ${DOT[t.status]} ${
                        t.is_overdue ? "bg-red-50 text-red-800" : t.status === "validated" ? "bg-emerald-50 text-emerald-900" : "bg-slate-50 text-slate-800"
                      }`}
                    >
                      {t.title}
                    </Link>
                  ))}
                  {items.length > 3 && openDay !== k && (
                    <button onClick={() => setOpenDay(k)} className="px-1 text-xs text-[var(--brand)] hover:underline">
                      +{items.length - 3} {l("more")}
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>
      {tasks === null && <p className="p-3 text-sm text-slate-500">…</p>}
    </div>
  );
}
