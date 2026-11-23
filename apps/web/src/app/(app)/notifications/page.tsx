"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { api, formatDate, type AppNotification } from "@/lib/api";
import { useI18n } from "@/lib/i18n";

export default function NotificationsPage() {
  const { t, locale } = useI18n();
  const [items, setItems] = useState<AppNotification[] | null>(null);

  const load = () => api<AppNotification[]>("notifications?per_page=50").then((r) => setItems(r.data));
  useEffect(() => {
    load();
  }, []);

  return (
    <div className="max-w-3xl space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">{t("notifications")}</h1>
        <button onClick={() => api("notifications/read-all", { method: "POST" }).then(load)} className="btn-secondary">
          {t("markAllRead")}
        </button>
      </div>
      <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
        {items?.length === 0 && <li className="p-4 text-sm text-slate-500">{t("noData")}</li>}
        {items?.map((n) => (
          <li key={n.id} className={`p-4 text-sm ${n.read_at ? "" : "bg-blue-50/60"}`}>
            <Link
              href={n.related_entity_id ? `/taches/${n.related_entity_id}` : "#"}
              onClick={() => !n.read_at && api(`notifications/${n.id}/read`, { method: "PATCH" })}
              className="block"
            >
              <div className="font-medium">{n.title}</div>
              <div className="text-slate-600">{n.message}</div>
              <div className="mt-1 text-xs text-slate-400">{formatDate(n.created_at, locale)}</div>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}
