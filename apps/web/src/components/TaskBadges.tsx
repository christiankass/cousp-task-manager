"use client";

import type { Task, TaskStatus } from "@/lib/api";
import { useI18n } from "@/lib/i18n";

const STATUS_STYLE: Record<TaskStatus, string> = {
  todo: "bg-slate-100 text-slate-700",
  in_progress: "bg-blue-100 text-blue-800",
  on_hold: "bg-amber-100 text-amber-800",
  submitted: "bg-violet-100 text-violet-800",
  validated: "bg-emerald-100 text-emerald-800",
  rejected: "bg-orange-100 text-orange-800",
};

const PRIORITY_STYLE: Record<Task["priority"], string> = {
  low: "text-slate-500",
  normal: "text-slate-700",
  high: "text-orange-700 font-medium",
  critical: "text-red-700 font-semibold",
};

export function StatusBadge({ status }: { status: TaskStatus }) {
  const { tx } = useI18n();
  return <span className={`rounded-full px-2 py-0.5 text-xs ${STATUS_STYLE[status]}`}>{tx(`s_${status}`)}</span>;
}

export function OverdueBadge() {
  const { t } = useI18n();
  return <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">{t("overdue")}</span>;
}

export function PriorityLabel({ priority }: { priority: Task["priority"] }) {
  const { tx } = useI18n();
  return <span className={`text-xs ${PRIORITY_STYLE[priority]}`}>{tx(`p_${priority}`)}</span>;
}
