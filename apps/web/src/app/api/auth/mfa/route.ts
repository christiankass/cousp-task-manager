import { NextRequest, NextResponse } from "next/server";
import { API_URL, clientIpHeaders } from "@/lib/session";
import { withSessionCookie } from "@/lib/sessionCookie";

/** Deuxième étape de connexion : code de l'application d'authentification ou code de secours. */
export async function POST(request: NextRequest) {
  const upstream = await fetch(`${API_URL}/auth/mfa/verify`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      "Accept-Language": request.headers.get("accept-language") ?? "fr",
      ...clientIpHeaders(request),
    },
    body: JSON.stringify(await request.json()),
  });
  const payload = await upstream.json();
  if (!upstream.ok) return NextResponse.json(payload, { status: upstream.status });
  return withSessionCookie(payload);
}
