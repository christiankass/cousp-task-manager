"use client";

import { useMe } from "@/components/MeProvider";
import { useI18n } from "@/lib/i18n";

export default function DashboardPage() {
  const me = useMe();
  const { t } = useI18n();

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold">
        {t("welcome")}, {me.user.name}
      </h1>
      <div className="grid gap-4 md:grid-cols-2">
        <section className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-3 font-semibold">{t("myUnits")}</h2>
          <ul className="space-y-1 text-sm">
            {me.user.units?.length ? me.user.units.map((u) => <li key={u.id}>{u.name}</li>) : <li>{t("noData")}</li>}
          </ul>
        </section>
        <section className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-3 font-semibold">{t("myRoles")}</h2>
          <ul className="space-y-1 text-sm">
            {me.user.roles?.map((r) => <li key={r.assignment_id}>{r.name}</li>)}
          </ul>
        </section>
      </div>
      <p className="rounded-lg bg-blue-50 p-4 text-sm text-blue-900">{t("nextSteps")}</p>
    </div>
  );
}
