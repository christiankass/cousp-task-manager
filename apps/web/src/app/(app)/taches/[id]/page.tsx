"use client";

import Link from "next/link";
import { use, useCallback, useEffect, useState } from "react";
import { OverdueBadge, PriorityLabel, StatusBadge } from "@/components/TaskBadges";
import { ApiError, api, formatDate, type Task } from "@/lib/api";
import { useI18n } from "@/lib/i18n";

type Comment = { id: string; content: string; author: { name: string }; created_at: string };
type HistoryItem = { id: string; event: string; new_status: string | null; by: { name: string } | null; reason: string | null; changed_at: string };
type Workflow = {
  id: string;
  status: string;
  current_step: number;
  steps: {
    step_order: number;
    status: string;
    approver: { name: string } | null;
    approver_role: { name: string } | null;
    decided_by: { name: string } | null;
    comments: string | null;
    decided_at: string | null;
  }[];
};

export default function TaskDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { t, tx, locale } = useI18n();
  const [task, setTask] = useState<Task | null>(null);
  const [comments, setComments] = useState<Comment[]>([]);
  const [history, setHistory] = useState<HistoryItem[]>([]);
  const [workflows, setWorkflows] = useState<Workflow[]>([]);
  const [newComment, setNewComment] = useState("");
  const [note, setNote] = useState("");
  const [feedback, setFeedback] = useState<{ ok: boolean; text: string } | null>(null);

  const load = useCallback(() => {
    api<Task>(`tasks/${id}`).then((r) => setTask(r.data));
    api<Comment[]>(`tasks/${id}/comments`).then((r) => setComments(r.data));
    api<HistoryItem[]>(`tasks/${id}/history`).then((r) => setHistory(r.data));
    api<Workflow[]>(`tasks/${id}/approvals`).then((r) => setWorkflows(r.data));
  }, [id]);

  useEffect(load, [load]);

  async function act(path: string, method: string, body: object = {}) {
    setFeedback(null);
    try {
      const r = await api(`tasks/${id}/${path}`, { method, body: JSON.stringify(body) });
      setFeedback({ ok: true, text: r.message ?? t("actionDone") });
      setNote("");
      load();
    } catch (err) {
      if (err instanceof ApiError) {
        const details = err.body.errors ? Object.values(err.body.errors).flat().join(" ") : "";
        setFeedback({ ok: false, text: `${err.body.message} ${details}`.trim() });
      }
    }
  }

  if (!task) return <p className="text-slate-500">{t("loading")}</p>;

  const responsible = task.assignees?.find((a) => a.role === "responsible");
  const collaborators = task.assignees?.filter((a) => a.role === "collaborator") ?? [];
  const current = workflows.find((w) => w.status === "pending");

  return (
    <div className="space-y-5">
      <div>
        <Link href="/taches" className="text-sm text-slate-500 hover:underline">
          ← {t("tasks")}
        </Link>
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <span className="font-mono text-xs text-slate-500">{task.reference}</span>
          <StatusBadge status={task.status} />
          {task.is_overdue && <OverdueBadge />}
          <PriorityLabel priority={task.priority} />
        </div>
        <h1 className="mt-1 text-2xl font-bold">{task.title}</h1>
      </div>

      {feedback && (
        <p className={`rounded p-3 text-sm ${feedback.ok ? "bg-emerald-50 text-emerald-800" : "bg-red-50 text-red-700"}`}>{feedback.text}</p>
      )}

      {/* Actions disponibles pour la personne connectée, calculées par le serveur. */}
      {task.can && (task.can.execute || task.can.submit || task.can.decide) && (
        <div className="space-y-3 rounded-xl border border-blue-200 bg-blue-50 p-4">
          <div className="flex flex-wrap gap-2">
            {task.can.execute && (task.status === "todo" || task.status === "rejected") && (
              <button onClick={() => act("status", "PATCH", { status: "in_progress" })} className="btn">
                {t("start")}
              </button>
            )}
            {task.can.execute && task.status === "on_hold" && (
              <button onClick={() => act("status", "PATCH", { status: "in_progress" })} className="btn">
                {t("resume")}
              </button>
            )}
            {task.can.execute && (task.status === "in_progress" || task.status === "todo") && (
              <button onClick={() => act("status", "PATCH", { status: "on_hold", reason: note })} className="btn-secondary">
                {t("putOnHold")}
              </button>
            )}
            {task.can.submit && (
              <button onClick={() => act("submit", "POST", { comment: note || undefined })} className="btn">
                {t("submit")}
              </button>
            )}
            {task.can.decide && (
              <>
                <button onClick={() => act("approve", "POST", { comments: note || undefined })} className="btn bg-emerald-700">
                  {t("approve")}
                </button>
                <button onClick={() => act("reject", "POST", { comments: note })} className="btn bg-orange-600">
                  {t("reject")}
                </button>
              </>
            )}
          </div>
          <input
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder={`${t("reasonPrompt")} / ${t("comments").toLowerCase()}`}
            className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
          />
        </div>
      )}

      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-5 lg:col-span-2">
          <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-5 text-sm">
            {task.description && <p className="whitespace-pre-wrap">{task.description}</p>}
            {task.expected_result && (
              <p>
                <span className="text-slate-500">{t("expectedResult")} : </span>
                {task.expected_result}
              </p>
            )}
            <div>
              <div className="mb-1 flex justify-between text-xs text-slate-500">
                <span>{t("progress")}</span>
                <span>{task.progress} %</span>
              </div>
              <div className="h-2 rounded bg-slate-100">
                <div className="h-2 rounded bg-[var(--brand)]" style={{ width: `${task.progress}%` }} />
              </div>
            </div>
            {task.subtasks && task.subtasks.length > 0 && (
              <div>
                <h3 className="mb-1 font-semibold">{t("subtasks")}</h3>
                <ul className="space-y-1">
                  {task.subtasks.map((s) => (
                    <li key={s.id} className="flex items-center gap-2">
                      <StatusBadge status={s.status} />
                      <Link href={`/taches/${s.id}`} className="hover:underline">
                        {s.title}
                      </Link>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </section>

          <section className="rounded-xl border border-slate-200 bg-white p-5">
            <h2 className="mb-3 font-semibold">{t("comments")}</h2>
            <ul className="space-y-3">
              {comments.map((c) => (
                <li key={c.id} className="rounded-lg bg-slate-50 p-3 text-sm">
                  <div className="mb-1 text-xs text-slate-500">
                    <strong className="text-slate-700">{c.author.name}</strong> · {formatDate(c.created_at, locale)}
                  </div>
                  <p className="whitespace-pre-wrap">{c.content}</p>
                </li>
              ))}
            </ul>
            <form
              onSubmit={(e) => {
                e.preventDefault();
                if (!newComment.trim()) return;
                api(`tasks/${id}/comments`, { method: "POST", body: JSON.stringify({ content: newComment }) }).then(() => {
                  setNewComment("");
                  load();
                });
              }}
              className="mt-3 flex gap-2"
            >
              <input
                value={newComment}
                onChange={(e) => setNewComment(e.target.value)}
                placeholder={t("addComment")}
                className="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm"
              />
              <button className="btn">{t("send")}</button>
            </form>
          </section>
        </div>

        <aside className="space-y-5">
          <section className="space-y-2 rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <Row label={t("unit")} value={task.organization_unit?.name} />
            <Row label={t("type")} value={tx(`t_${task.task_type}`)} />
            <Row label={t("responsible")} value={responsible?.name} />
            {collaborators.length > 0 && <Row label={t("collaborators")} value={collaborators.map((c) => c.name).join(", ")} />}
            <Row label={t("startAt")} value={formatDate(task.start_at, locale)} />
            <Row label={t("dueAt")} value={formatDate(task.due_at, locale)} danger={task.is_overdue} />
            <Row label={t("createdBy")} value={task.created_by?.name} />
          </section>

          {workflows.length > 0 && (
            <section className="rounded-xl border border-slate-200 bg-white p-5 text-sm">
              <h2 className="mb-3 font-semibold">{t("approvals")}</h2>
              {(current ? [current] : workflows.slice(-1)).map((w) => (
                <ol key={w.id} className="space-y-2">
                  {w.steps.map((s) => (
                    <li key={s.step_order} className="rounded-lg bg-slate-50 p-2">
                      <div className="flex justify-between">
                        <span>
                          {t("step")} {s.step_order} · {s.approver?.name ?? s.approver_role?.name}
                        </span>
                        <span
                          className={
                            s.status === "approved" ? "text-emerald-700" : s.status === "rejected" ? "text-orange-700" : "text-slate-500"
                          }
                        >
                          {s.status === "pending" ? t("waitingFor") : s.status === "approved" ? "✓" : s.status === "rejected" ? "✗" : "…"}
                        </span>
                      </div>
                      {s.comments && <p className="mt-1 text-xs text-slate-600">« {s.comments} »</p>}
                    </li>
                  ))}
                </ol>
              ))}
            </section>
          )}

          <section className="rounded-xl border border-slate-200 bg-white p-5 text-sm">
            <h2 className="mb-3 font-semibold">{t("history")}</h2>
            <ol className="space-y-2 border-l border-slate-200 pl-3">
              {history.map((h) => (
                <li key={h.id}>
                  <div className="font-medium">
                    {tx(`e_${h.event}`)}
                    {h.new_status && ` → ${tx(`s_${h.new_status}`)}`}
                  </div>
                  <div className="text-xs text-slate-500">
                    {h.by?.name ?? "—"} · {formatDate(h.changed_at, locale)}
                  </div>
                  {h.reason && <div className="text-xs text-slate-600">« {h.reason} »</div>}
                </li>
              ))}
            </ol>
          </section>
        </aside>
      </div>
    </div>
  );
}

function Row({ label, value, danger }: { label: string; value?: string; danger?: boolean }) {
  return (
    <div className="flex justify-between gap-3">
      <span className="text-slate-500">{label}</span>
      <span className={`text-right ${danger ? "font-medium text-red-700" : ""}`}>{value ?? "—"}</span>
    </div>
  );
}
