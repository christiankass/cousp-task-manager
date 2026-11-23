"use client";

import { createContext, useContext, useEffect, useState, type ReactNode } from "react";
import { api, type Me } from "@/lib/api";

const MeContext = createContext<Me | null>(null);

export function MeProvider({ children, fallback }: { children: ReactNode; fallback: ReactNode }) {
  const [me, setMe] = useState<Me | null>(null);
  useEffect(() => {
    api<Me>("auth/me").then((r) => setMe(r.data)).catch(() => undefined);
  }, []);
  return me ? <MeContext.Provider value={me}>{children}</MeContext.Provider> : <>{fallback}</>;
}

export function useMe(): Me {
  const me = useContext(MeContext);
  if (!me) throw new Error("useMe outside MeProvider");
  return me;
}

export const can = (me: Me, ...codes: string[]) => codes.some((c) => me.permissions.includes(c));
