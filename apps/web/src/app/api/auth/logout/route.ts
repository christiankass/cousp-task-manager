import { NextResponse } from "next/server";
import { API_URL, TOKEN_COOKIE, getToken } from "@/lib/session";

export async function POST() {
  const token = await getToken();
  if (token) {
    await fetch(`${API_URL}/auth/logout`, {
      method: "POST",
      headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
    }).catch(() => undefined);
  }
  const response = NextResponse.json({ success: true });
  response.cookies.delete(TOKEN_COOKIE);
  return response;
}
