"use client";

import { useState } from "react";
import { useI18n } from "./i18n";
import type { TaskBrief } from "./coordination";
import type { TaskStatus } from "./api";

/* ---------- Textes du lot 4 (rapports, planification, sécurité, tâches récurrentes) ---------- */

const fr = {
  reportsTitle: "Rapports et statistiques",
  reportsIntro: "Choisissez une période et un périmètre, vérifiez l'aperçu, puis téléchargez le rapport.",
  from: "Du",
  to: "Au",
  unit: "Structure",
  allUnits: "Toutes les structures visibles",
  taskType: "Type de tâche",
  allTypes: "Tous les types",
  tt_administrative: "Administrative",
  tt_operational: "Opérationnelle",
  preview: "Aperçu",
  downloadPdf: "Télécharger en PDF",
  downloadXlsx: "Télécharger en Excel",
  downloading: "Préparation…",
  downloadFailed: "Le téléchargement a échoué.",
  noExportRight: "Vous pouvez consulter l'aperçu. Le téléchargement est réservé aux personnes autorisées à exporter.",
  totalTasks: "Tâches",
  validated: "Validées",
  inProgress: "En cours",
  overdue: "En retard",
  criticalOpen: "Critiques ouvertes",
  executionRate: "Taux d'exécution",
  validationRate: "Taux de validation",
  avgHours: "Durée moyenne (heures)",
  byUnit: "Par structure",
  unitName: "Structure",
  tasksList: "Tâches de la période",
  truncated: "Seules les 2 000 premières tâches sont listées. Réduisez la période pour tout voir.",
  noTasks: "Aucune tâche sur cette période.",
  dueAt: "Échéance",
  responsible: "Responsable",
  status: "Statut",

  planningTitle: "Planification",
  calendar: "Calendrier",
  kanban: "Tableau par statut",
  today: "Aujourd'hui",
  prev: "Précédent",
  next: "Suivant",
  more: "autres",
  kanbanHint: "Le changement de statut se fait dans la fiche de la tâche, pour garder l'historique et le circuit de validation.",
  onlyMine: "Seulement mes tâches",
  emptyColumn: "Rien ici",
  noDueDate: "Sans échéance",

  securityTitle: "Sécurité du compte",
  mfaTitle: "Connexion en deux étapes",
  mfaIntro:
    "En plus du mot de passe, un code à 6 chiffres est demandé à chaque connexion. Le code est donné par une application gratuite sur votre téléphone (Google Authenticator, Microsoft Authenticator, FreeOTP…).",
  mfaOn: "Activée",
  mfaOff: "Désactivée",
  mfaSince: "depuis le",
  mfaRecommended: "Fortement recommandée pour votre compte, car il donne des droits d'administration.",
  mfaStart: "Activer la connexion en deux étapes",
  mfaStep1: "1. Ouvrez l'application sur votre téléphone et scannez ce code.",
  mfaManual: "Impossible de scanner ? Saisissez cette clé dans l'application :",
  mfaStep2: "2. Saisissez le code à 6 chiffres affiché par l'application.",
  mfaConfirm: "Confirmer",
  mfaCodesTitle: "Codes de secours",
  mfaCodesHint:
    "Gardez ces codes en lieu sûr (imprimés ou dans un coffre-fort de mots de passe). Chacun permet une connexion si vous perdez votre téléphone. Ils ne seront plus affichés.",
  mfaCodesLeft: "Codes de secours restants",
  mfaPrint: "Imprimer",
  mfaDone: "J'ai noté mes codes",
  mfaDisable: "Désactiver",
  mfaDisableHint: "Pour désactiver, confirmez avec votre mot de passe et un code actuel.",
  password: "Mot de passe",
  code: "Code",
  cancel: "Annuler",
  mfaBannerEnforced: "Votre compte doit être protégé par la connexion en deux étapes avant de continuer.",
  mfaBannerRecommended: "Protégez votre compte : activez la connexion en deux étapes.",
  mfaBannerAction: "Activer maintenant",
  loginCodeTitle: "Code de vérification",
  loginCodeHint: "Saisissez le code à 6 chiffres de votre application d'authentification.",
  loginUseRecovery: "Utiliser un code de secours",
  loginUseApp: "Utiliser le code de l'application",
  loginRecoveryHint: "Saisissez un de vos codes de secours (format XXXXX-XXXXX).",
  verify: "Vérifier",
  back: "Retour",

  recurringTitle: "Tâches récurrentes",
  recurringIntro: "Une série crée automatiquement la même tâche à chaque échéance (rapport hebdomadaire, réunion mensuelle…).",
  newSeries: "Nouvelle série",
  reference: "Réf.",
  title: "Titre",
  description: "Description",
  expectedResult: "Résultat attendu",
  priority: "Priorité",
  p_low: "Basse",
  p_normal: "Normale",
  p_high: "Haute",
  p_critical: "Critique",
  frequency: "Fréquence",
  f_daily: "Chaque jour",
  f_weekly: "Chaque semaine",
  f_monthly: "Chaque mois",
  every: "Tous les",
  everyUnit_daily: "jour(s)",
  everyUnit_weekly: "semaine(s)",
  everyUnit_monthly: "mois",
  weekdays: "Jours",
  dayOfMonth: "Jour du mois",
  dayOfMonthHint: "31 = dernier jour du mois",
  dueTime: "Heure d'échéance",
  leadDays: "Créée combien de jours avant",
  startsOn: "Début",
  endsOn: "Fin (facultatif)",
  save: "Enregistrer",
  saved: "Enregistré",
  nextDates: "Prochaines échéances",
  generated: "tâches créées",
  active: "Active",
  stopped: "Arrêtée",
  stop: "Arrêter",
  resume: "Reprendre",
  noSeries: "Aucune série pour le moment.",
  stopConfirm: "Arrêter cette série ? Les tâches déjà créées restent.",

  eventReportPdf: "Rapport de situation (PDF)",
  exportPdf: "PDF",
  exportXlsx: "Excel",
} as const;

const en: Record<keyof typeof fr, string> = {
  reportsTitle: "Reports and statistics",
  reportsIntro: "Pick a period and a scope, check the preview, then download the report.",
  from: "From",
  to: "To",
  unit: "Unit",
  allUnits: "All visible units",
  taskType: "Task type",
  allTypes: "All types",
  tt_administrative: "Administrative",
  tt_operational: "Operational",
  preview: "Preview",
  downloadPdf: "Download PDF",
  downloadXlsx: "Download Excel",
  downloading: "Preparing…",
  downloadFailed: "The download failed.",
  noExportRight: "You can view the preview. Downloading is limited to people allowed to export.",
  totalTasks: "Tasks",
  validated: "Approved",
  inProgress: "In progress",
  overdue: "Overdue",
  criticalOpen: "Open critical",
  executionRate: "Execution rate",
  validationRate: "Approval rate",
  avgHours: "Average duration (hours)",
  byUnit: "By unit",
  unitName: "Unit",
  tasksList: "Tasks in the period",
  truncated: "Only the first 2,000 tasks are listed. Narrow the period to see everything.",
  noTasks: "No tasks in this period.",
  dueAt: "Due",
  responsible: "Responsible",
  status: "Status",

  planningTitle: "Planning",
  calendar: "Calendar",
  kanban: "Board by status",
  today: "Today",
  prev: "Previous",
  next: "Next",
  more: "more",
  kanbanHint: "Status changes happen on the task page, to keep the history and the approval flow.",
  onlyMine: "Only my tasks",
  emptyColumn: "Nothing here",
  noDueDate: "No due date",

  securityTitle: "Account security",
  mfaTitle: "Two-step sign-in",
  mfaIntro:
    "On top of your password, a 6-digit code is asked at each sign-in. The code comes from a free app on your phone (Google Authenticator, Microsoft Authenticator, FreeOTP…).",
  mfaOn: "On",
  mfaOff: "Off",
  mfaSince: "since",
  mfaRecommended: "Strongly recommended for your account, as it has administration rights.",
  mfaStart: "Turn on two-step sign-in",
  mfaStep1: "1. Open the app on your phone and scan this code.",
  mfaManual: "Can't scan? Type this key in the app:",
  mfaStep2: "2. Enter the 6-digit code shown by the app.",
  mfaConfirm: "Confirm",
  mfaCodesTitle: "Recovery codes",
  mfaCodesHint:
    "Keep these codes somewhere safe (printed or in a password manager). Each one lets you sign in once if you lose your phone. They will not be shown again.",
  mfaCodesLeft: "Recovery codes left",
  mfaPrint: "Print",
  mfaDone: "I have saved my codes",
  mfaDisable: "Turn off",
  mfaDisableHint: "To turn it off, confirm with your password and a current code.",
  password: "Password",
  code: "Code",
  cancel: "Cancel",
  mfaBannerEnforced: "Your account must be protected by two-step sign-in before you continue.",
  mfaBannerRecommended: "Protect your account: turn on two-step sign-in.",
  mfaBannerAction: "Turn on now",
  loginCodeTitle: "Verification code",
  loginCodeHint: "Enter the 6-digit code from your authenticator app.",
  loginUseRecovery: "Use a recovery code",
  loginUseApp: "Use the app code",
  loginRecoveryHint: "Enter one of your recovery codes (XXXXX-XXXXX).",
  verify: "Verify",
  back: "Back",

  recurringTitle: "Recurring tasks",
  recurringIntro: "A series automatically creates the same task for each due date (weekly report, monthly meeting…).",
  newSeries: "New series",
  reference: "Ref.",
  title: "Title",
  description: "Description",
  expectedResult: "Expected result",
  priority: "Priority",
  p_low: "Low",
  p_normal: "Normal",
  p_high: "High",
  p_critical: "Critical",
  frequency: "Frequency",
  f_daily: "Every day",
  f_weekly: "Every week",
  f_monthly: "Every month",
  every: "Every",
  everyUnit_daily: "day(s)",
  everyUnit_weekly: "week(s)",
  everyUnit_monthly: "month(s)",
  weekdays: "Days",
  dayOfMonth: "Day of month",
  dayOfMonthHint: "31 = last day of the month",
  dueTime: "Due time",
  leadDays: "Created how many days before",
  startsOn: "Start",
  endsOn: "End (optional)",
  save: "Save",
  saved: "Saved",
  nextDates: "Next due dates",
  generated: "tasks created",
  active: "Active",
  stopped: "Stopped",
  stop: "Stop",
  resume: "Resume",
  noSeries: "No series yet.",
  stopConfirm: "Stop this series? Tasks already created stay.",

  exportPdf: "PDF",
  exportXlsx: "Excel",
  eventReportPdf: "Situation report (PDF)",
};

export type LKey = keyof typeof fr;

export function useL() {
  const { locale } = useI18n();
  const dict: Record<string, string> = locale === "en" ? en : fr;
  return { l: (k: LKey) => dict[k], lx: (k: string) => dict[k] ?? k };
}

/* ---------- Téléchargements ---------- */

/** Télécharge un fichier produit par l'API (PDF, Excel) via le relais authentifié. */
export function DownloadButton({ path, label, className = "btn-secondary" }: { path: string; label: string; className?: string }) {
  const { l } = useL();
  const { locale } = useI18n();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function download() {
    setBusy(true);
    setError(null);
    try {
      const response = await fetch(`/api/download/${path.replace(/^\//, "")}`, { headers: { "Accept-Language": locale } });
      if (!response.ok) {
        const body = await response.json().catch(() => null);
        throw new Error(body?.message ?? l("downloadFailed"));
      }
      const name = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(response.headers.get("content-disposition") ?? "")?.[1];
      const url = URL.createObjectURL(await response.blob());
      const link = document.createElement("a");
      link.href = url;
      link.download = name ? decodeURIComponent(name) : "rapport";
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      setError(e instanceof Error ? e.message : l("downloadFailed"));
    } finally {
      setBusy(false);
    }
  }

  return (
    <span className="inline-flex flex-col">
      <button type="button" onClick={download} disabled={busy} className={`${className} disabled:opacity-60`}>
        {busy ? l("downloading") : label}
      </button>
      {error && <span className="mt-1 text-xs text-red-600">{error}</span>}
    </span>
  );
}

/* ---------- Types ---------- */

export type Indicators = {
  total: number;
  by_status: Record<TaskStatus, number>;
  overdue: number;
  critical_open: number;
  execution_rate: number | null;
  validation_rate: number | null;
  average_execution_hours: number | null;
};

export type TaskReport = {
  institution: string;
  generated_at: string;
  period: { from: string | null; to: string | null };
  scope: string | null;
  indicators: Indicators;
  by_unit: { id: string; name: string; total: number; validated: number; in_progress: number; overdue: number; execution_rate: number | null }[];
  truncated: boolean;
  tasks: TaskBrief[];
};

export type Recurrence = {
  id: string;
  title: string;
  description: string | null;
  expected_result: string | null;
  task_type: "administrative" | "operational";
  priority: "low" | "normal" | "high" | "critical";
  organization_unit: { id: string; name: string } | null;
  responsible: { id: string; name: string } | null;
  collaborator_ids: string[];
  frequency: "daily" | "weekly" | "monthly";
  interval: number;
  weekdays: number[];
  day_of_month: number | null;
  due_time: string;
  lead_days: number;
  starts_on: string;
  ends_on: string | null;
  next_occurrence_on: string | null;
  upcoming: string[];
  is_active: boolean;
  generated_count: number;
};

export type MfaStatus = { enabled: boolean; confirmed_at: string | null; recovery_codes_left: number; recommended: boolean };

/** Date « AAAA-MM-JJ » lisible, sans décalage de fuseau. */
export function formatDay(day: string | null, locale: string, opts: Intl.DateTimeFormatOptions = { weekday: "short", day: "2-digit", month: "short", year: "numeric" }) {
  if (!day) return "—";
  const [y, m, d] = day.split("-").map(Number);
  return new Date(y, m - 1, d).toLocaleDateString(locale === "en" ? "en-GB" : "fr-FR", opts);
}
