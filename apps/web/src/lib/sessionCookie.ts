import { NextResponse } from "next/server";
import { TOKEN_COOKIE } from "./session";

/** Pose le jeton dans un cookie httpOnly et ne renvoie au navigateur que le reste de la réponse. */
export function withSessionCookie(payload: { data: { access_token: string; expires_at: string } & Record<string, unknown> }) {
  const { access_token, expires_at, ...rest } = payload.data;
  const response = NextResponse.json({ ...payload, data: rest });
  response.cookies.set(TOKEN_COOKIE, access_token, {
    httpOnly: true,
    // COOKIE_SECURE=false uniquement pour la démonstration locale en http (docker-compose.demo.yml).
    secure: process.env.NODE_ENV === "production" && process.env.COOKIE_SECURE !== "false",
    sameSite: "strict",
    path: "/",
    expires: new Date(expires_at),
  });
  return response;
}
