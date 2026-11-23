"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useI18n, type MessageKey } from "@/lib/i18n";
import { can, useMe } from "./MeProvider";
import { LanguageSwitch } from "./LanguageSwitch";

/** Menu de l'application (§11). Les entrées des lots suivants sont affichées comme « bientôt disponibles ». */
const MENU: { key: MessageKey; href?: string; permissions?: string[] }[] = [
  { key: "dashboard", href: "/" },
  { key: "tasks" },
  { key: "planning" },
  { key: "users", href: "/utilisateurs", permissions: ["users.view", "users.manage"] },
  { key: "organisation", href: "/organisation", permissions: ["org_units.view", "org_units.manage"] },
  { key: "meetings" },
  { key: "emergencies" },
  { key: "notifications" },
  { key: "documents" },
  { key: "reports" },
  { key: "audit" },
  { key: "settings" },
];

export function Sidebar() {
  const { t } = useI18n();
  const me = useMe();
  const pathname = usePathname();
  const router = useRouter();

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
              className={`rounded px-3 py-2 text-sm ${pathname === item.href ? "bg-white/20 font-semibold" : "hover:bg-white/10"}`}
            >
              {t(item.key)}
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
