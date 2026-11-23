"use client";

import { useI18n } from "@/lib/i18n";

export function LanguageSwitch() {
  const { locale, setLocale, t } = useI18n();
  return (
    <div className="flex items-center gap-1 text-sm" aria-label={t("language")}>
      {(["fr", "en"] as const).map((l) => (
        <button
          key={l}
          onClick={() => setLocale(l)}
          className={`rounded px-2 py-1 uppercase ${locale === l ? "bg-[var(--brand)] text-white" : "text-slate-600 hover:bg-slate-200"}`}
        >
          {l}
        </button>
      ))}
    </div>
  );
}
