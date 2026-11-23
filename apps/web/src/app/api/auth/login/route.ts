import { NextRequest, NextResponse } from "next/server";
import { API_URL, clientIpHeaders } from "@/lib/session";
import { withSessionCookie } from "@/lib/sessionCookie";

export async function POST(request: NextRequest) {
  const body = await request.json();
  const upstream = await fetch(`${API_URL}/auth/login`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      "Accept-Language": request.headers.get("accept-language") ?? "fr",
      ...clientIpHeaders(request),
    },
    body: JSON.stringify({ ...body, device_name: "web" }),
  });
  const payload = await upstream.json();

  if (!upstream.ok) {
    return NextResponse.json(payload, { status: upstream.status });
  }

  // Compte protégé par un code : pas encore de jeton, le navigateur passe à l'étape du code.
  if (payload.data?.mfa_required) {
    return NextResponse.json(payload);
  }

  return withSessionCookie(payload);
}
