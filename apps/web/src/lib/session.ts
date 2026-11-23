import { cookies } from "next/headers";

export const TOKEN_COOKIE = "cousp_token";
export const API_URL = process.env.API_URL ?? "http://localhost:8000/api/v1";

/** Le jeton reste dans un cookie httpOnly : il n'est jamais accessible au JavaScript du navigateur. */
export async function getToken(): Promise<string | undefined> {
  return (await cookies()).get(TOKEN_COOKIE)?.value;
}
