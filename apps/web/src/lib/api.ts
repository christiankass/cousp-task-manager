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
