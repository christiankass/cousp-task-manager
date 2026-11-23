import { NextRequest, NextResponse } from "next/server";
import { API_URL, clientIpHeaders, getToken } from "@/lib/session";

/** Relais vers l'API Laravel : ajoute le jeton du cookie httpOnly. */
async function forward(request: NextRequest, { params }: { params: Promise<{ path: string[] }> }) {
  const { path } = await params;
  const token = await getToken();
  if (!token) {
    return NextResponse.json({ success: false, code: "UNAUTHENTICATED" }, { status: 401 });
  }

  const url = `${API_URL}/${path.map(encodeURIComponent).join("/")}${request.nextUrl.search}`;
  const hasBody = !["GET", "HEAD"].includes(request.method);
  const upstream = await fetch(url, {
    method: request.method,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
      "Accept-Language": request.headers.get("accept-language") ?? "fr",
      ...clientIpHeaders(request),
    },
    body: hasBody ? await request.text() : undefined,
  });

  if (upstream.status === 204) return new NextResponse(null, { status: 204 });
  return NextResponse.json(await upstream.json(), { status: upstream.status });
}

export { forward as GET, forward as POST, forward as PUT, forward as PATCH, forward as DELETE };
