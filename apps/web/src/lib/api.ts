export type ApiResult<T> = {
  success: boolean;
  message: string;
  data: T;
  errors?: Record<string, string[]>;
  code?: string;
  meta?: { pagination?: { page: number; per_page: number; total: number; last_page: number } };
};

export class ApiError extends Error {
  constructor(public status: number, public body: ApiResult<unknown>) {
    super(body?.message ?? `HTTP ${status}`);
  }
}

/** Appels navigateur → relais Next.js → API Laravel. */
export async function api<T>(path: string, init: RequestInit = {}): Promise<ApiResult<T>> {
  const response = await fetch(`/api/proxy/${path.replace(/^\//, "")}`, {
    ...init,
    headers: { "Content-Type": "application/json", ...(init.headers ?? {}) },
  });
  if (response.status === 401 && typeof window !== "undefined") {
    window.location.href = "/login";
  }
  const body = response.status === 204 ? ({ success: true } as ApiResult<T>) : await response.json();
  if (!response.ok) throw new ApiError(response.status, body);
  return body;
}

export type User = {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  preferred_language: "fr" | "en";
  status: string;
  units?: { id: string; name: string; membership_type: string; is_primary: boolean }[];
  roles?: { assignment_id: string; code: string; name: string; organization_unit_id: string | null }[];
};

export type Institution = { id: string; name: string; code: string };

export type OrganizationUnit = {
  id: string;
  parent_id: string | null;
  name: string;
  code: string;
  unit_type: string;
  unit_type_label: string;
  manager: { id: string; name: string } | null;
  is_active: boolean;
  children?: OrganizationUnit[];
};

export type Me = { user: User; institution: Institution; permissions: string[]; is_platform_admin: boolean };

export type TaskStatus = "todo" | "in_progress" | "on_hold" | "submitted" | "validated" | "rejected";

export type Task = {
  id: string;
  reference: string;
  title: string;
  description: string | null;
  expected_result: string | null;
  task_type: "administrative" | "operational";
  priority: "low" | "normal" | "high" | "critical";
  status: TaskStatus;
  approval_status: string;
  is_overdue: boolean;
  progress: number;
  organization_unit?: { id: string; name: string };
  parent_task_id: string | null;
  start_at: string | null;
  due_at: string | null;
  validated_at: string | null;
  created_by?: { id: string; name: string };
  assignees?: { assignment_id: string; user_id: string; name: string; role: "responsible" | "collaborator" }[];
  subtasks?: { id: string; reference: string; title: string; status: TaskStatus; is_overdue: boolean }[];
  can?: { update: boolean; execute: boolean; submit: boolean; decide: boolean; delete: boolean };
};

export type Dashboard = {
  total: number;
  by_status: Record<TaskStatus, number>;
  overdue: number;
  critical_open: number;
  execution_rate: number | null;
  validation_rate: number | null;
  average_execution_hours: number | null;
  by_unit: { id: string; name: string; total: number; validated: number; overdue: number }[];
  monthly: { month: string; created: number; validated: number }[];
};

export type AppNotification = {
  id: string;
  type: string;
  title: string;
  message: string;
  related_entity_id: string | null;
  read_at: string | null;
  created_at: string;
};

export function formatDate(iso: string | null, locale: string): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString(locale === "en" ? "en-GB" : "fr-FR", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}
