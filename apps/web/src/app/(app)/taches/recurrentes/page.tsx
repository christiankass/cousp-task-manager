"use client";

import { useCallback, useEffect, useState } from "react";
import { can, useMe } from "@/components/MeProvider";
import { ApiError, api } from "@/lib/api";
import { Card, Field, Pill, UnitSelect, UserSelect, useDirectory } from "@/lib/coordination";
import { useI18n } from "@/lib/i18n";
import { formatDay, useL, type Recurrence } from "@/lib/lot4";

const empty = () => ({
  title: "",
  description: "",
  expected_result: "",
  organization_unit_id: "",
  task_type: "administrative",
  priority: "normal",
  responsible_id: "",
  frequency: "weekly" as Recurrence["frequency"],
  interval: 1,
  weekdays: [1] as number[],
  day_of_month: 1,
  due_time: "17:00",
  lead_days: 1,
  starts_on: new Date().toLocaleDateString("sv-SE"),
  ends_on: "",
});

export default function RecurringTasksPage() {
  const me = useMe();
  const { locale } = useI18n();
  const { l, lx } = useL();
  const { units, users } = useDirectory();
  const [items, setItems] = useState<Recurrence[] | null>(null);
  const [form, setForm] = useState(empty());
  const [editing, setEditing] = useState<string | null>(null);
  const [open, setOpen] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => api<Recurrence[]>("task-recurrences").then((r) => setItems(r.data)), []);
  useEffect(() => {
    load();
  }, [load]);

  const weekdayLabels = [1, 2, 3, 4, 5, 6, 7].map((d) => new Date(2024, 0, d).toLocaleDateString(locale === "en" ? "en-GB" : "fr-FR", { weekday: "short" }));

  async function call(fn: () => Promise<unknown>) {
    setErrors({});
    setError(null);
    try {
      await fn();
      await load();
      return true;
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.body.errors ?? {});
        setError(e.message);
      }
      return false;
    }
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    const payload = {
      ...form,
      description: form.description || null,
      expected_result: form.expected_result || null,
      weekdays: form.frequency === "weekly" ? form.weekdays : null,
      day_of_month: form.frequency === "monthly" ? form.day_of_month : null,
      ends_on: form.ends_on || null,
    };
    const ok = await call(() =>
      editing
        ? api(`task-recurrences/${editing}`, { method: "PUT", body: JSON.stringify(payload) })
        : api("task-recurrences", { method: "POST", body: JSON.stringify(payload) }),
    );
    if (ok) {
      setOpen(false);
      setEditing(null);
      setForm(empty());
    }
  }

  function edit(r: Recurrence) {
    setEditing(r.id);
    setOpen(true);
    setForm({
      title: r.title,
      description: r.description ?? "",
      expected_result: r.expected_result ?? "",
      organization_unit_id: r.organization_unit?.id ?? "",
      task_type: r.task_type,
      priority: r.priority,
      responsible_id: r.responsible?.id ?? "",
      frequency: r.frequency,
      interval: r.interval,
      weekdays: r.weekdays.length ? r.weekdays : [1],
      day_of_month: r.day_of_month ?? 1,
      due_time: r.due_time,
      lead_days: r.lead_days,
      starts_on: r.starts_on,
      ends_on: r.ends_on ?? "",
    });
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  const summary = (r: Recurrence) => {
    const every = r.interval > 1 ? `${l("every")} ${r.interval} ${lx(`everyUnit_${r.frequency}`)}` : lx(`f_${r.frequency}`);
    if (r.frequency === "weekly") return `${every} · ${r.weekdays.map((d) => weekdayLabels[d - 1]).join(", ")} · ${r.due_time}`;
    if (r.frequency === "monthly") return `${every} · ${l("dayOfMonth").toLowerCase()} ${r.day_of_month} · ${r.due_time}`;
    return `${every} · ${r.due_time}`;
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold">{l("recurringTitle")}</h1>
          <p className="text-sm text-slate-600">{l("recurringIntro")}</p>
        </div>
        {can(me, "tasks.create") && !open && (
          <button className="btn" onClick={() => (setEditing(null), setForm(empty()), setOpen(true))}>
            + {l("newSeries")}
          </button>
        )}
      </div>

      {open && (
        <Card title={editing ? form.title : l("newSeries")}>
          <form onSubmit={submit} className="grid gap-3 md:grid-cols-2">
            <Field label={l("title")} error={errors.title} className="md:col-span-2">
              <input className="input" required value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
            </Field>
            <Field label={l("unit")} error={errors.organization_unit_id}>
              <UnitSelect value={form.organization_unit_id} onChange={(v) => setForm({ ...form, organization_unit_id: v })} units={units} required />
            </Field>
            <Field label={l("responsible")} error={errors.responsible_id}>
              <UserSelect value={form.responsible_id} onChange={(v) => setForm({ ...form, responsible_id: v })} users={users} required />
            </Field>
            <Field label={l("taskType")} error={errors.task_type}>
              <select className="input" value={form.task_type} onChange={(e) => setForm({ ...form, task_type: e.target.value })}>
                <option value="administrative">{l("tt_administrative")}</option>
                <option value="operational">{l("tt_operational")}</option>
              </select>
            </Field>
            <Field label={l("priority")} error={errors.priority}>
              <select className="input" value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}>
                {["low", "normal", "high", "critical"].map((p) => (
                  <option key={p} value={p}>
                    {lx(`p_${p}`)}
                  </option>
                ))}
              </select>
            </Field>
            <Field label={l("frequency")} error={errors.frequency}>
              <select className="input" value={form.frequency} onChange={(e) => setForm({ ...form, frequency: e.target.value as Recurrence["frequency"] })}>
                {(["daily", "weekly", "monthly"] as const).map((f) => (
                  <option key={f} value={f}>
                    {lx(`f_${f}`)}
                  </option>
                ))}
              </select>
            </Field>
            <Field label={`${l("every")} … ${lx(`everyUnit_${form.frequency}`)}`} error={errors.interval}>
              <input type="number" min={1} max={52} className="input" value={form.interval} onChange={(e) => setForm({ ...form, interval: Number(e.target.value) })} />
            </Field>
            {form.frequency === "weekly" && (
              <Field label={l("weekdays")} error={errors.weekdays} className="md:col-span-2">
                <div className="flex flex-wrap gap-1">
                  {weekdayLabels.map((label, i) => {
                    const d = i + 1;
                    const on = form.weekdays.includes(d);
                    return (
                      <button
                        type="button"
                        key={d}
                        onClick={() => setForm({ ...form, weekdays: on ? form.weekdays.filter((x) => x !== d) : [...form.weekdays, d].sort() })}
                        className={`rounded-md border px-3 py-1 text-sm capitalize ${on ? "border-[var(--brand)] bg-[var(--brand)] text-white" : "border-slate-300 bg-white text-slate-700"}`}
                      >
                        {label}
                      </button>
                    );
                  })}
                </div>
              </Field>
            )}
            {form.frequency === "monthly" && (
              <Field label={`${l("dayOfMonth")} (${l("dayOfMonthHint")})`} error={errors.day_of_month}>
                <input type="number" min={1} max={31} className="input" value={form.day_of_month} onChange={(e) => setForm({ ...form, day_of_month: Number(e.target.value) })} />
              </Field>
            )}
            <Field label={l("dueTime")} error={errors.due_time}>
              <input type="time" className="input" value={form.due_time} onChange={(e) => setForm({ ...form, due_time: e.target.value })} />
            </Field>
            <Field label={l("leadDays")} error={errors.lead_days}>
              <input type="number" min={0} max={30} className="input" value={form.lead_days} onChange={(e) => setForm({ ...form, lead_days: Number(e.target.value) })} />
            </Field>
            <Field label={l("startsOn")} error={errors.starts_on}>
              <input type="date" className="input" required value={form.starts_on} onChange={(e) => setForm({ ...form, starts_on: e.target.value })} />
            </Field>
            <Field label={l("endsOn")} error={errors.ends_on}>
              <input type="date" className="input" value={form.ends_on} onChange={(e) => setForm({ ...form, ends_on: e.target.value })} />
            </Field>
            <Field label={l("description")} error={errors.description} className="md:col-span-2">
              <textarea className="input" rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </Field>
            <Field label={l("expectedResult")} error={errors.expected_result} className="md:col-span-2">
              <textarea className="input" rows={2} value={form.expected_result} onChange={(e) => setForm({ ...form, expected_result: e.target.value })} />
            </Field>
            {error && <p className="rounded bg-red-50 p-2 text-sm text-red-700 md:col-span-2">{error}</p>}
            <div className="flex gap-2 md:col-span-2">
              <button className="btn">{l("save")}</button>
              <button type="button" className="btn-secondary" onClick={() => (setOpen(false), setEditing(null))}>
                {l("cancel")}
              </button>
            </div>
          </form>
        </Card>
      )}

      {items === null ? (
        <p className="text-slate-500">…</p>
      ) : items.length === 0 ? (
        <Card>
          <p className="text-sm text-slate-500">{l("noSeries")}</p>
        </Card>
      ) : (
        <div className="grid gap-3 lg:grid-cols-2">
          {items.map((r) => (
            <section key={r.id} className={`rounded-xl border bg-white p-4 ${r.is_active ? "border-slate-200" : "border-slate-200 opacity-70"}`}>
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <h2 className="font-semibold">{r.title}</h2>
                  <p className="text-xs text-slate-500">
                    {r.organization_unit?.name} · {r.responsible?.name} · {lx(`p_${r.priority}`)}
                  </p>
                </div>
                <Pill tone={r.is_active ? "green" : "slate"}>{r.is_active ? l("active") : l("stopped")}</Pill>
              </div>
              <p className="mt-2 text-sm">{summary(r)}</p>
              {r.upcoming.length > 0 && (
                <div className="mt-2 text-sm">
                  <span className="text-slate-500">{l("nextDates")} : </span>
                  {r.upcoming.slice(0, 4).map((d) => formatDay(d, locale, { weekday: "short", day: "2-digit", month: "short" })).join(" · ")}
                </div>
              )}
              <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                <span className="text-xs text-slate-500">
                  {r.generated_count} {l("generated")}
                </span>
                <div className="flex gap-2">
                  <button className="btn-secondary" onClick={() => edit(r)}>
                    ✎
                  </button>
                  {r.is_active ? (
                    <button
                      className="btn-secondary"
                      onClick={() => window.confirm(l("stopConfirm")) && call(() => api(`task-recurrences/${r.id}`, { method: "DELETE" }))}
                    >
                      {l("stop")}
                    </button>
                  ) : (
                    <button className="btn-secondary" onClick={() => call(() => api(`task-recurrences/${r.id}`, { method: "PUT", body: JSON.stringify({ is_active: true }) }))}>
                      {l("resume")}
                    </button>
                  )}
                </div>
              </div>
            </section>
          ))}
        </div>
      )}
      {error && !open && <p className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
    </div>
  );
}
