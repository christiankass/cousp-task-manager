"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { useI18n, type MessageKey } from "@/lib/i18n";
import { can, useMe } from "./MeProvider";
import { LanguageSwitch } from "./LanguageSwitch";

/** Menu de l'application (§11). Les entrées hors V1 sont affichées comme « bientôt disponibles ». */
const MENU: { key: MessageKey; href?: string; permissions?: string[] }[] = [
  { key: "dashboard", href: "/" },
  { key: "tasks", href: "/taches" },
  { key: "recurring", href: "/taches/recurrentes" },
  { key: "planning", href: "/planification" },
  { key: "users", href: "/utilisateurs", permissions: ["users.view", "users.manage"] },
  { key: "organisation", href: "/organisation", permissions: ["org_units.view", "org_units.manage"] },
  { key: "meetings", href: "/reunions" },
  { key: "emergencies", href: "/urgences" },
  { key: "missions", href: "/missions" },
  { key: "notifications", href: "/notifications" },
  { key: "documents" },
  { key: "reports", href: "/rapports", permissions: ["reports.view", "reports.export"] },
  { key: "audit" },
  { key: "settings" },
  { key: "security", href: "/securite" },
];

/** Entrée active : celle dont l'adresse correspond le plus précisément à la page affichée. */
function activeHref(pathname: string): string | undefined {
  return MENU.map((m) => m.href)
    .filter((h): h is string => !!h && (h === "/" ? pathname === "/" : pathname === h || pathname.startsWith(`${h}/`)))
    .sort((a, b) => b.length - a.length)[0];
}

export function Sidebar() {
  const { t } = useI18n();
  const me = useMe();
  const pathname = usePathname();
  const router = useRouter();
  const [unread, setUnread] = useState(0);

  useEffect(() => {
    const refresh = () => api<{ count: number }>("notifications/unread-count").then((r) => setUnread(r.data.count)).catch(() => undefined);
    refresh();
    const timer = setInterval(refresh, 60_000);
    return () => clearInterval(timer);
  }, [pathname]);

  async function logout() {
    await fetch("/api/auth/logout", { method: "POST" });
    router.push("/login");
  }

  return (
    <aside className="flex w-full flex-col bg-[var(--brand)] text-white md:min-h-screen md:w-64">
      <div className="border-b border-white/10 p-4">
        <div className="text-lg font-bold">{t("appName")}</div>
        <div className="text-xs text-white/70">{me.institution.name}</div>
      </div>
      <nav className="flex flex-1 flex-row flex-wrap gap-1 p-2 md:flex-col">
        {MENU.filter((m) => !m.permissions || can(me, ...m.permissions)).map((item) =>
          item.href ? (
            <Link
              key={item.key}
              href={item.href}
              className={`flex items-center justify-between rounded px-3 py-2 text-sm ${
                item.href === activeHref(pathname) ? "bg-white/20 font-semibold" : "hover:bg-white/10"
              }`}
            >
              {t(item.key)}
              {item.key === "notifications" && unread > 0 && (
                <span className="ml-2 rounded-full bg-red-500 px-1.5 text-xs font-semibold text-white">{unread}</span>
              )}
            </Link>
          ) : (
            <span key={item.key} title={t("comingSoon")} className="hidden cursor-not-allowed rounded px-3 py-2 text-sm text-white/40 md:block">
              {t(item.key)}
            </span>
          ),
        )}
      </nav>
      <div className="flex items-center justify-between gap-2 border-t border-white/10 p-3">
        <div className="rounded bg-white p-0.5">
          <LanguageSwitch />
        </div>
        <button onClick={logout} className="text-sm text-white/80 hover:text-white">
          {t("logout")}
        </button>
      </div>
    </aside>
  );
}
