"use client";

import { useEffect, useState, type ReactNode } from "react";
import { api, type OrganizationUnit, type TaskStatus, type User } from "./api";
import { useI18n } from "./i18n";
import { useMe } from "@/components/MeProvider";

/* ---------- Types renvoyés par l'API du lot 3 ---------- */

export type Person = { id: string; name: string } | null;

export type TaskBrief = {
  id: string;
  reference: string;
  title: string;
  status: TaskStatus;
  priority: "low" | "normal" | "high" | "critical";
  is_overdue: boolean;
  due_at: string | null;
  responsible: Person;
  organization_unit: { id: string; name: string } | null;
};

export type FollowUp = {
  total: number;
  not_converted: number;
  in_progress: number;
  overdue: number;
  done: number;
  cancelled: number;
  execution_rate: number | null;
};

export type ExecutionStatus = "not_converted" | "in_progress" | "overdue" | "done" | "cancelled";

export type Recommendation = {
  id: string;
  meeting_id: string;
  meeting: { id: string; reference: string; title: string; starts_at: string } | null;
  number: number;
  reference: string;
  content: string;
  expected_result: string | null;
  priority: TaskBrief["priority"] | null;
  due_at: string | null;
  responsible: Person;
  organization_unit: { id: string; name: string } | null;
  status: "open" | "converted" | "cancelled";
  execution_status: ExecutionStatus;
  tasks: TaskBrief[];
};

export type Meeting = {
  id: string;
  reference: string;
  title: string;
  meeting_type: string;
  meeting_type_label: string;
  status: "planned" | "held" | "cancelled";
  starts_at: string;
  ends_at: string | null;
  location: string | null;
  agenda: string | null;
  minutes: string | null;
  organization_unit: { id: string; name: string } | null;
  health_event: { id: string; reference: string; title: string } | null;
  chair: Person;
  secretary: Person;
  follow_up: FollowUp | null;
};

export type MeetingDetail = Meeting & {
  participants: { id: string; user: Person; external_name: string | null; organization: string | null; attendance: string }[];
  recommendations: Recommendation[];
  previous_meeting: (Meeting & { recommendations: Recommendation[] }) | null;
  can: { manage: boolean };
};

export type HealthEvent = {
  id: string;
  reference: string;
  title: string;
  event_type: string;
  event_type_label: string;
  description: string | null;
  location: string | null;
  severity: "low" | "moderate" | "high" | "critical";
  status: "active" | "monitoring" | "closed";
  status_label: string;
  start_at: string;
  end_at: string | null;
  closure_note: string | null;
  coordinator: Person;
  tasks_count: number | null;
  open_tasks_count: number | null;
  overdue_tasks_count: number | null;
  missions_count: number | null;
};

export type Mission = {
  id: string;
  reference: string;
  title: string;
  destination: string;
  objectives: string;
  departure_at: string;
  return_at: string;
  status: "planned" | "in_progress" | "report_submitted" | "completed";
  status_label: string;
  leader: Person;
  health_event: { id: string; reference: string; title: string } | null;
  task: TaskBrief | null;
  members_count: number | null;
  report_submitted_at: string | null;
  report?: string | null;
  members?: { id: string; user: Person; assignment: string | null; participation_status: string }[];
};

export type HealthEventDetail = HealthEvent & {
  stats: { tasks_total: number; by_status: Record<TaskStatus, number>; overdue: number; completion_rate: number | null };
  teams: { id: string; name: string; total: number; validated: number; overdue: number }[];
  tasks: TaskBrief[];
  missions: Mission[];
  meetings: Meeting[];
  situation_reports: { id: string; report_date: string; summary: string; actions: string | null; needs: string | null; author: Person }[];
  can: { manage: boolean };
};

/* ---------- Textes FR/EN du lot 3 ---------- */

const fr = {
  missions: "Missions de terrain",
  recommendations: "Suivi des recommandations",
  newMeeting: "Nouvelle réunion",
  newEvent: "Déclarer un événement",
  newMission: "Nouvelle mission",
  meetingType: "Type de réunion",
  date: "Date",
  endDate: "Fin",
  location: "Lieu",
  agenda: "Ordre du jour",
  minutes: "Compte rendu",
  chair: "Président de séance",
  secretary: "Secrétaire",
  participants: "Participants",
  addParticipant: "Ajouter",
  externalName: "Participant externe (nom)",
  organization: "Organisation",
  attendance: "Présence",
  a_invited: "Invité", a_present: "Présent", a_absent: "Absent", a_excused: "Excusé",
  ms_planned: "Prévue", ms_held: "Tenue", ms_cancelled: "Annulée",
  mt_coordination: "Réunion de coordination", mt_technical: "Réunion technique", mt_management: "Comité de direction", mt_crisis: "Cellule de crise", mt_other: "Autre réunion",
  addRecommendation: "Ajouter une recommandation",
  recommendation: "Recommandation",
  convert: "Transformer en tâche",
  addTask: "+ Autre tâche",
  convertHint: "Une tâche est créée avec la référence de la recommandation ; le responsable est prévenu.",
  followUp: "Suivi des décisions",
  executionRate: "Taux d'exécution",
  previousMeeting: "Recommandations de la réunion précédente",
  noRecommendations: "Aucune recommandation pour le moment.",
  es_not_converted: "Pas encore en tâche", es_in_progress: "En cours", es_overdue: "En retard", es_done: "Exécutée", es_cancelled: "Abandonnée",
  dropRecommendation: "Abandonner",
  print: "Imprimer le suivi",
  linkedEvent: "Événement sanitaire lié",
  none: "Aucun",
  eventType: "Type d'événement",
  severity: "Gravité",
  sev_low: "Faible", sev_moderate: "Modérée", sev_high: "Élevée", sev_critical: "Critique",
  ev_cholera: "Choléra", ev_measles: "Rougeole", ev_mpox: "Mpox", ev_hemorrhagic_fever: "Fièvre hémorragique", ev_flood: "Inondation à risque sanitaire", ev_other: "Autre événement",
  es2_active: "Riposte en cours", es2_monitoring: "Sous surveillance", es2_closed: "Clôturé",
  coordinator: "Coordonnateur de la riposte",
  openedOn: "Ouvert le",
  closedOn: "Clôturé le",
  responseActions: "Actions de riposte",
  addAction: "Nouvelle action",
  teams: "Équipes impliquées",
  sitreps: "Rapports de situation",
  addSitrep: "Ajouter un rapport de situation",
  summary: "Situation",
  actionsTaken: "Actions menées",
  needs: "Besoins",
  closeEvent: "Clôturer l'événement",
  closureNote: "Bilan de clôture",
  completion: "Actions validées",
  disclaimer: "Ce module suit les actions et les responsabilités de la riposte ; il ne remplace pas la surveillance épidémiologique.",
  destination: "Destination",
  objectives: "Objectifs",
  departure: "Départ",
  return: "Retour",
  leader: "Chef de mission",
  team: "Équipe",
  role: "Rôle dans l'équipe",
  addMember: "Ajouter à l'équipe",
  report: "Rapport de mission",
  submitReport: "Déposer le rapport",
  reportHint: "Le rapport part en validation, comme le livrable d'une tâche.",
  mis_planned: "Prévue", mis_in_progress: "En cours", mis_report_submitted: "Rapport en validation", mis_completed: "Terminée",
  onlyMyMissions: "Uniquement mes missions",
  supportTask: "Tâche de suivi",
  open: "Ouvrir",
  saved: "Enregistré",
  edit: "Modifier",
};

const en: typeof fr = {
  missions: "Field missions",
  recommendations: "Recommendation follow-up",
  newMeeting: "New meeting",
  newEvent: "Declare an event",
  newMission: "New mission",
  meetingType: "Meeting type",
  date: "Date",
  endDate: "End",
  location: "Location",
  agenda: "Agenda",
  minutes: "Minutes",
  chair: "Chair",
  secretary: "Secretary",
  participants: "Participants",
  addParticipant: "Add",
  externalName: "External participant (name)",
  organization: "Organisation",
  attendance: "Attendance",
  a_invited: "Invited", a_present: "Present", a_absent: "Absent", a_excused: "Excused",
  ms_planned: "Planned", ms_held: "Held", ms_cancelled: "Cancelled",
  mt_coordination: "Coordination meeting", mt_technical: "Technical meeting", mt_management: "Management committee", mt_crisis: "Crisis cell", mt_other: "Other meeting",
  addRecommendation: "Add a recommendation",
  recommendation: "Recommendation",
  convert: "Turn into a task",
  addTask: "+ Another task",
  convertHint: "A task is created with the recommendation's reference; the lead is notified.",
  followUp: "Decision follow-up",
  executionRate: "Completion rate",
  previousMeeting: "Recommendations from the previous meeting",
  noRecommendations: "No recommendations yet.",
  es_not_converted: "Not yet a task", es_in_progress: "In progress", es_overdue: "Overdue", es_done: "Completed", es_cancelled: "Dropped",
  dropRecommendation: "Drop",
  print: "Print follow-up",
  linkedEvent: "Linked health event",
  none: "None",
  eventType: "Event type",
  severity: "Severity",
  sev_low: "Low", sev_moderate: "Moderate", sev_high: "High", sev_critical: "Critical",
  ev_cholera: "Cholera", ev_measles: "Measles", ev_mpox: "Mpox", ev_hemorrhagic_fever: "Haemorrhagic fever", ev_flood: "Flood with health risk", ev_other: "Other event",
  es2_active: "Response ongoing", es2_monitoring: "Under monitoring", es2_closed: "Closed",
  coordinator: "Response coordinator",
  openedOn: "Opened on",
  closedOn: "Closed on",
  responseActions: "Response actions",
  addAction: "New action",
  teams: "Teams involved",
  sitreps: "Situation reports",
  addSitrep: "Add a situation report",
  summary: "Situation",
  actionsTaken: "Actions taken",
  needs: "Needs",
  closeEvent: "Close the event",
  closureNote: "Closing summary",
  completion: "Approved actions",
  disclaimer: "This module tracks response actions and responsibilities; it does not replace epidemiological surveillance.",
  destination: "Destination",
  objectives: "Objectives",
  departure: "Departure",
  return: "Return",
  leader: "Mission lead",
  team: "Team",
  role: "Role in the team",
  addMember: "Add to team",
  report: "Mission report",
  submitReport: "Submit the report",
  reportHint: "The report goes for approval, like a task deliverable.",
  mis_planned: "Planned", mis_in_progress: "In progress", mis_report_submitted: "Report under review", mis_completed: "Completed",
  onlyMyMissions: "Only my missions",
  supportTask: "Tracking task",
  open: "Open",
  saved: "Saved",
  edit: "Edit",
};

export type CKey = keyof typeof fr;

export function useC() {
  const { locale } = useI18n();
  const dict = locale === "en" ? en : fr;
  return { c: (k: CKey) => dict[k], cx: (k: string) => (dict as Record<string, string>)[k] ?? k };
}

/* ---------- Aides partagées ---------- */

export const toIso = (local: string) => (local ? new Date(local).toISOString() : undefined);

export function flattenUnits(units: OrganizationUnit[], depth = 0): { unit: OrganizationUnit; depth: number }[] {
  return units.flatMap((u) => [{ unit: u, depth }, ...flattenUnits(u.children ?? [], depth + 1)]);
}

/** Structures et utilisateurs pour les listes déroulantes. */
export function useDirectory() {
  const me = useMe();
  const [units, setUnits] = useState<{ unit: OrganizationUnit; depth: number }[]>([]);
  const [users, setUsers] = useState<User[]>([]);
  useEffect(() => {
    api<OrganizationUnit[]>("organization-units?tree=1").then((r) => setUnits(flattenUnits(r.data))).catch(() => setUnits([]));
    api<User[]>("users?per_page=100&status=active")
      .then((r) => setUsers(r.data))
      .catch(() => setUsers([me.user]));
  }, [me.user]);
  return { units, users };
}

export function UnitSelect({ value, onChange, units, required, emptyLabel }: {
  value: string; onChange: (v: string) => void; units: { unit: OrganizationUnit; depth: number }[]; required?: boolean; emptyLabel?: string;
}) {
  return (
    <select className="input" value={value} onChange={(e) => onChange(e.target.value)} required={required}>
      <option value="">{emptyLabel ?? "—"}</option>
      {units.map(({ unit, depth }) => (
        <option key={unit.id} value={unit.id}>
          {"  ".repeat(depth)}
          {unit.name}
        </option>
      ))}
    </select>
  );
}

export function UserSelect({ value, onChange, users, required, emptyLabel }: {
  value: string; onChange: (v: string) => void; users: User[]; required?: boolean; emptyLabel?: string;
}) {
  return (
    <select className="input" value={value} onChange={(e) => onChange(e.target.value)} required={required}>
      <option value="">{emptyLabel ?? "—"}</option>
      {users.map((u) => (
        <option key={u.id} value={u.id}>
          {u.name}
        </option>
      ))}
    </select>
  );
}

export function Field({ label, children, error, className = "" }: { label: string; children: ReactNode; error?: string[]; className?: string }) {
  return (
    <label className={`block text-sm ${className}`}>
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      {children}
      {error && <span className="mt-1 block text-xs text-red-600">{error.join(" ")}</span>}
    </label>
  );
}

export function Card({ title, actions, children }: { title?: string; actions?: ReactNode; children: ReactNode }) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4">
      {(title || actions) && (
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          {title && <h2 className="font-semibold">{title}</h2>}
          {actions}
        </div>
      )}
      {children}
    </section>
  );
}

const EXEC_STYLE: Record<ExecutionStatus, string> = {
  not_converted: "bg-slate-100 text-slate-700",
  in_progress: "bg-blue-100 text-blue-800",
  overdue: "bg-red-100 text-red-700",
  done: "bg-emerald-100 text-emerald-800",
  cancelled: "bg-slate-100 text-slate-400 line-through",
};

export function ExecutionBadge({ status }: { status: ExecutionStatus }) {
  const { cx } = useC();
  return <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-xs ${EXEC_STYLE[status]}`}>{cx(`es_${status}`)}</span>;
}

const SEVERITY_STYLE = { low: "bg-slate-100 text-slate-700", moderate: "bg-amber-100 text-amber-800", high: "bg-orange-100 text-orange-800", critical: "bg-red-100 text-red-700" };

export function SeverityBadge({ severity }: { severity: HealthEvent["severity"] }) {
  const { cx } = useC();
  return <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${SEVERITY_STYLE[severity]}`}>{cx(`sev_${severity}`)}</span>;
}

export function Pill({ children, tone = "slate" }: { children: ReactNode; tone?: "slate" | "blue" | "green" | "red" | "violet" | "amber" }) {
  const tones = {
    slate: "bg-slate-100 text-slate-700", blue: "bg-blue-100 text-blue-800", green: "bg-emerald-100 text-emerald-800",
    red: "bg-red-100 text-red-700", violet: "bg-violet-100 text-violet-800", amber: "bg-amber-100 text-amber-800",
  };
  return <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-xs ${tones[tone]}`}>{children}</span>;
}

export const missionTone = (s: Mission["status"]) => ({ planned: "slate", in_progress: "blue", report_submitted: "violet", completed: "green" } as const)[s];
export const eventTone = (s: HealthEvent["status"]) => ({ active: "red", monitoring: "amber", closed: "slate" } as const)[s];

/** Barre de taux d'exécution des recommandations. */
export function FollowUpBar({ followUp }: { followUp: FollowUp | null }) {
  const { c, cx } = useC();
  if (!followUp || followUp.total === 0) return <span className="text-xs text-slate-400">—</span>;
  const active = followUp.total - followUp.cancelled;
  const part = (n: number) => (active ? `${(n * 100) / active}%` : "0");
  return (
    <div className="min-w-40 space-y-1">
      <div className="flex h-2 overflow-hidden rounded-full bg-slate-100" title={`${c("executionRate")} : ${followUp.execution_rate ?? 0} %`}>
        <div className="bg-emerald-500" style={{ width: part(followUp.done) }} />
        <div className="bg-blue-400" style={{ width: part(followUp.in_progress) }} />
        <div className="bg-red-400" style={{ width: part(followUp.overdue) }} />
      </div>
      <div className="text-xs text-slate-500">
        {followUp.execution_rate ?? 0} % · {followUp.done}/{active} {cx("es_done").toLowerCase()}
        {followUp.overdue > 0 && <span className="text-red-600"> · {followUp.overdue} {cx("es_overdue").toLowerCase()}</span>}
      </div>
    </div>
  );
}
