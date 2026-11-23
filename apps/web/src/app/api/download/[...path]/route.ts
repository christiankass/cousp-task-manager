import { NextRequest, NextResponse } from "next/server";
import { API_URL, clientIpHeaders, getToken } from "@/lib/session";

/** Téléchargement de fichiers (PDF, Excel) : relaie le flux binaire de l'API avec le jeton du cookie. */
export async function GET(request: NextRequest, { params }: { params: Promise<{ path: string[] }> }) {
  const { path } = await params;
  const token = await getToken();
  if (!token) {
    return NextResponse.json({ success: false, code: "UNAUTHENTICATED" }, { status: 401 });
  }

  const upstream = await fetch(`${API_URL}/${path.map(encodeURIComponent).join("/")}${request.nextUrl.search}`, {
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${token}`,
      "Accept-Language": request.headers.get("accept-language") ?? "fr",
      ...clientIpHeaders(request),
    },
  });

  if (!upstream.ok) {
    return NextResponse.json(await upstream.json().catch(() => ({ success: false })), { status: upstream.status });
  }

  const headers = new Headers({ "Cache-Control": "no-store" });
  for (const name of ["content-type", "content-disposition", "content-length"]) {
    const value = upstream.headers.get(name);
    if (value) headers.set(name, value);
  }
  return new NextResponse(upstream.body, { status: 200, headers });
}
