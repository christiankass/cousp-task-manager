"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { ApiError, api, type OrganizationUnit, type Task, type User } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { useMe } from "@/components/MeProvider";

function flatten(units: OrganizationUnit[], depth = 0): { unit: OrganizationUnit; depth: number }[] {
  return units.flatMap((u) => [{ unit: u, depth }, ...flatten(u.children ?? [], depth + 1)]);
}

const toIso = (local: string) => (local ? new Date(local).toISOString() : undefined);

export default function NewTaskPage() {
  const { t, tx } = useI18n();
  const me = useMe();
  const router = useRouter();
  const [units, setUnits] = useState<{ unit: OrganizationUnit; depth: number }[]>([]);
  const [users, setUsers] = useState<User[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [form, setForm] = useState({
    title: "",
    description: "",
    expected_result: "",
    task_type: "administrative",
    priority: "normal",
    organization_unit_id: me.user.units?.[0]?.id ?? "",
    responsible: "",
    collaborators: [] as string[],
    start_at: "",
    due_at: "",
  });

  useEffect(() => {
    api<OrganizationUnit[]>("organization-units?tree=1").then((r) => setUnits(flatten(r.data)));
    api<User[]>("users?per_page=100&status=active")
      .then((r) => setUsers(r.data))
      .catch(() => setUsers([me.user]));
  }, [me.user]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setErrors({});
    setMessage(null);
    try {
      const r = await api<Task>("tasks", {
        method: "POST",
        body: JSON.stringify({
          ...form,
          start_at: toIso(form.start_at),
          due_at: toIso(form.due_at),
          assignees: [
            { user_id: form.responsible, role: "responsible" },
            ...form.collaborators.filter((c) => c !== form.responsible).map((user_id) => ({ user_id, role: "collaborator" })),
          ],
        }),
      });
      router.push(`/taches/${r.data.id}`);
    } catch (err) {
      if (err instanceof ApiError) {
        setErrors(err.body.errors ?? {});
        setMessage(err.body.message);
      }
    }
  }

  const field = "w-full rounded-md border border-slate-300 px-3 py-2 text-sm";
  const err = (k: string) => {
    const list = Object.entries(errors).filter(([key]) => key === k || key.startsWith(`${k}.`)).flatMap(([, v]) => v);
    return list.length ? <p className="mt-1 text-xs text-red-700">{list.join(" ")}</p> : null;
  };

  return (
    <form onSubmit={submit} className="max-w-3xl space-y-4">
      <h1 className="text-2xl font-bold">{t("newTask")}</h1>
      {message && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{message}</p>}
      <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
        <label className="block text-sm">
          <span className="mb-1 block text-slate-600">{t("title")} *</span>
          <input required className={field} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
          {err("title")}
        </label>
        <label className="block text-sm">
          <span className="mb-1 block text-slate-600">{t("description")}</span>
          <textarea rows={3} className={field} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        </label>
        <label className="block text-sm">
          <span className="mb-1 block text-slate-600">{t("expectedResult")}</span>
          <input className={field} value={form.expected_result} onChange={(e) => setForm({ ...form, expected_result: e.target.value })} />
        </label>
        <div className="grid gap-4 sm:grid-cols-3">
          <label className="block text-sm">
            <span className="mb-1 block text-slate-600">{t("unit")} *</span>
            <select required className={field} value={form.organization_unit_id} onChange={(e) => setForm({ ...form, organization_unit_id: e.target.value })}>
              <option value="" />
              {units.map(({ unit, depth }) => (
                <option key={unit.id} value={unit.id}>
                  {"  ".repeat(depth)}
                  {unit.name}
                </option>
              ))}
            </select>
            {err("organization_unit_id")}
          </label>
          <label className="block text-sm">
            <span className="mb-1 block text-slate-600">{t("type")}</span>
            <select className={field} value={form.task_type} onChange={(e) => setForm({ ...form, task_type: e.target.value })}>
              {["administrative", "operational"].map((v) => (
                <option key={v} value={v}>
                  {tx(`t_${v}`)}
                </option>
              ))}
            </select>
          </label>
          <label className="block text-sm">
            <span className="mb-1 block text-slate-600">{t("priority")}</span>
            <select className={field} value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}>
              {["low", "normal", "high", "critical"].map((v) => (
                <option key={v} value={v}>
                  {tx(`p_${v}`)}
                </option>
              ))}
            </select>
          </label>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block text-sm">
            <span className="mb-1 block text-slate-600">{t("startAt")}</span>
            <input type="datetime-local" className={field} value={form.start_at} onChange={(e) => setForm({ ...form, start_at: e.target.value })} />
          </label>
          <label className="block text-sm">
            <span className="mb-1 block text-slate-600">{t("dueAt")}</span>
            <input type="datetime-local" className={field} value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })} />
            {err("due_at")}
          </label>
        </div>
        <label className="block text-sm">
          <span className="mb-1 block text-slate-600">{t("responsible")} *</span>
          <select required className={field} value={form.responsible} onChange={(e) => setForm({ ...form, responsible: e.target.value })}>
            <option value="" />
            {users.map((u) => (
              <option key={u.id} value={u.id}>
                {u.name}
              </option>
            ))}
          </select>
          {err("assignees")}
        </label>
        <fieldset className="text-sm">
          <legend className="mb-1 text-slate-600">{t("collaborators")}</legend>
          <div className="flex flex-wrap gap-3">
            {users
              .filter((u) => u.id !== form.responsible)
              .map((u) => (
                <label key={u.id} className="flex items-center gap-1">
                  <input
                    type="checkbox"
                    checked={form.collaborators.includes(u.id)}
                    onChange={(e) =>
                      setForm({
                        ...form,
                        collaborators: e.target.checked ? [...form.collaborators, u.id] : form.collaborators.filter((c) => c !== u.id),
                      })
                    }
                  />
                  {u.name}
                </label>
              ))}
          </div>
        </fieldset>
      </div>
      <div className="flex gap-3">
        <button className="rounded-md bg-[var(--brand)] px-4 py-2 text-sm font-medium text-white">{t("create")}</button>
        <button type="button" onClick={() => router.back()} className="rounded-md px-4 py-2 text-sm text-slate-600 hover:bg-slate-200">
          {t("cancel")}
        </button>
      </div>
    </form>
  );
}
