import { NextRequest, NextResponse } from "next/server";
import { API_URL, TOKEN_COOKIE } from "@/lib/session";

export async function POST(request: NextRequest) {
  const body = await request.json();
  const upstream = await fetch(`${API_URL}/auth/login`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      "Accept-Language": request.headers.get("accept-language") ?? "fr",
    },
    body: JSON.stringify({ ...body, device_name: "web" }),
  });
  const payload = await upstream.json();

  if (!upstream.ok) {
    return NextResponse.json(payload, { status: upstream.status });
  }

  const { access_token, expires_at, ...rest } = payload.data;
  const response = NextResponse.json({ ...payload, data: rest });
  response.cookies.set(TOKEN_COOKIE, access_token, {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "strict",
    path: "/",
    expires: new Date(expires_at),
  });
  return response;
}
