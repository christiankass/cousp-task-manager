import { cookies } from "next/headers";

export const TOKEN_COOKIE = "cousp_token";
export const API_URL = process.env.API_URL ?? "http://localhost:8000/api/v1";

/** Le jeton reste dans un cookie httpOnly : il n'est jamais accessible au JavaScript du navigateur. */
export async function getToken(): Promise<string | undefined> {
  return (await cookies()).get(TOKEN_COOKIE)?.value;
}

/**
 * Adresse IP du navigateur, transmise à l'API pour que la limitation des tentatives de connexion
 * et le journal d'audit visent la vraie personne, et non le serveur web.
 * En production, Nginx remplace l'en-tête X-Real-IP par l'adresse réelle (voir infrastructure/production).
 */
export function clientIpHeaders(request: Request): Record<string, string> {
  const ip = request.headers.get("x-real-ip") ?? request.headers.get("x-forwarded-for")?.split(",")[0]?.trim();
  return ip ? { "X-Forwarded-For": ip } : {};
}
