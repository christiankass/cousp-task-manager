"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useL } from "@/lib/lot4";
import { useMe } from "./MeProvider";

/** Rappel d'activer la connexion en deux étapes (obligatoire pour les comptes à privilèges si l'institution l'exige). */
export function MfaBanner() {
  const me = useMe();
  const { l } = useL();
  const pathname = usePathname();
  const s = me.security;
  if (!s || s.mfa_enabled || (!s.mfa_recommended && !s.mfa_enforced) || pathname.startsWith("/securite")) return null;

  return (
    <div className={`no-print mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg p-3 text-sm ${s.mfa_enforced ? "bg-red-50 text-red-800" : "bg-amber-50 text-amber-900"}`}>
      <span>{s.mfa_enforced ? l("mfaBannerEnforced") : l("mfaBannerRecommended")}</span>
      <Link href="/securite" className="btn">
        {l("mfaBannerAction")}
      </Link>
    </div>
  );
}
