import Constants from "expo-constants";
import * as SecureStore from "expo-secure-store";

/** Adresse de l'API : app.json > extra.apiUrl, ou variable EXPO_PUBLIC_API_URL. */
export const API_URL: string =
  process.env.EXPO_PUBLIC_API_URL ?? (Constants.expoConfig?.extra?.apiUrl as string) ?? "http://10.0.2.2:8000/api/v1";

const TOKEN_KEY = "cousp_token";

export type ApiResult<T> = { success: boolean; message: string; data: T; errors?: Record<string, string[]>; code?: string };

export class ApiError extends Error {
  constructor(public status: number, public body: Partial<ApiResult<unknown>>) {
    super(body?.message ?? `HTTP ${status}`);
  }
  /** Message lisible, avec le détail des champs invalides. */
  get details(): string {
    const fields = this.body.errors ? Object.values(this.body.errors).flat().join(" ") : "";
    return [this.message, fields].filter(Boolean).join(" ");
  }
}

let onUnauthorized: () => void = () => {};
export const setUnauthorizedHandler = (fn: () => void) => (onUnauthorized = fn);

export const getToken = () => SecureStore.getItemAsync(TOKEN_KEY);
export const saveToken = (t: string) => SecureStore.setItemAsync(TOKEN_KEY, t);
export const clearToken = () => SecureStore.deleteItemAsync(TOKEN_KEY);

export async function api<T>(path: string, init: RequestInit & { locale?: string } = {}): Promise<ApiResult<T>> {
  const token = await getToken();
  const isForm = typeof FormData !== "undefined" && init.body instanceof FormData;
  const response = await fetch(`${API_URL}/${path.replace(/^\//, "")}`, {
    ...init,
    headers: {
      Accept: "application/json",
      ...(isForm ? {} : { "Content-Type": "application/json" }),
      ...(init.locale ? { "Accept-Language": init.locale } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(init.headers ?? {}),
    },
  });

  if (response.status === 401 && token) {
    await clearToken();
    onUnauthorized();
  }

  const body = response.status === 204 ? { success: true } : await response.json().catch(() => ({}));
  if (!response.ok) throw new ApiError(response.status, body);
  return body as ApiResult<T>;
}

export type TaskStatus = "todo" | "in_progress" | "on_hold" | "submitted" | "validated" | "rejected";

export type Task = {
  id: string;
  reference: string;
  title: string;
  description: string | null;
  expected_result: string | null;
  priority: "low" | "normal" | "high" | "critical";
  status: TaskStatus;
  is_overdue: boolean;
  progress: number;
  organization_unit?: { id: string; name: string };
  due_at: string | null;
  assignees?: { user_id: string; name: string; role: "responsible" | "collaborator" }[];
  can?: { update: boolean; execute: boolean; submit: boolean; decide: boolean; delete: boolean };
};

export type Me = {
  user: { id: string; name: string; email: string; preferred_language: "fr" | "en" };
  institution: { id: string; name: string; code: string };
  permissions: string[];
};

export type AppNotification = {
  id: string;
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
    hour: "2-digit",
    minute: "2-digit",
  });
}
