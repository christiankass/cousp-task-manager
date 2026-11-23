"use client";

import { useEffect, useState } from "react";
import { api, type OrganizationUnit } from "@/lib/api";
import { useI18n } from "@/lib/i18n";

function Node({ unit, depth }: { unit: OrganizationUnit; depth: number }) {
  return (
    <li>
      <div className="flex items-center gap-3 border-b border-slate-100 py-2" style={{ paddingLeft: depth * 20 }}>
        <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{unit.unit_type_label}</span>
        <span className={unit.is_active ? "" : "text-slate-400 line-through"}>{unit.name}</span>
        <span className="text-xs text-slate-400">{unit.code}</span>
        {unit.manager && <span className="ml-auto text-xs text-slate-500">{unit.manager.name}</span>}
      </div>
      {unit.children?.length ? (
        <ul>
          {unit.children.map((c) => (
            <Node key={c.id} unit={c} depth={depth + 1} />
          ))}
        </ul>
      ) : null}
    </li>
  );
}

export default function OrganisationPage() {
  const { t } = useI18n();
  const [units, setUnits] = useState<OrganizationUnit[] | null>(null);

  useEffect(() => {
    api<OrganizationUnit[]>("organization-units?tree=1").then((r) => setUnits(r.data));
  }, []);

  return (
    <div>
      <h1 className="mb-4 text-2xl font-bold">{t("organisation")}</h1>
      <div className="rounded-xl border border-slate-200 bg-white p-4">
        {units === null ? t("loading") : units.length === 0 ? t("noData") : (
          <ul>
            {units.map((u) => (
              <Node key={u.id} unit={u} depth={0} />
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
