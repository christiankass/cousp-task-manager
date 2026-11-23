import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
import { api, clearToken, getToken, saveToken, setUnauthorizedHandler, type Me } from "./api";
import { registerForPush, unregisterPush } from "./push";

type Session = {
  me: Me | null;
  loading: boolean;
  login: (email: string, password: string, institutionCode?: string) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
};

const SessionContext = createContext<Session>(null as unknown as Session);

export function SessionProvider({ children }: { children: ReactNode }) {
  const [me, setMe] = useState<Me | null>(null);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    if (!(await getToken())) {
      setMe(null);
      return;
    }
    try {
      const r = await api<Me>("auth/me");
      setMe(r.data);
      registerForPush().catch(() => {});
    } catch {
      setMe(null);
    }
  }, []);

  useEffect(() => {
    setUnauthorizedHandler(() => setMe(null));
    refresh().finally(() => setLoading(false));
  }, [refresh]);

  const login = async (email: string, password: string, institutionCode?: string) => {
    const r = await api<{ access_token: string }>("auth/login", {
      method: "POST",
      body: JSON.stringify({
        email: email.trim(),
        password,
        device_name: "mobile",
        ...(institutionCode?.trim() ? { institution_code: institutionCode.trim() } : {}),
      }),
    });
    await saveToken(r.data.access_token);
    await refresh();
  };

  const logout = async () => {
    const deviceToken = await unregisterPush();
    await api("auth/logout", { method: "POST", body: JSON.stringify(deviceToken ? { device_token: deviceToken } : {}) }).catch(() => {});
    await clearToken();
    setMe(null);
  };

  return <SessionContext.Provider value={{ me, loading, login, logout, refresh }}>{children}</SessionContext.Provider>;
}

export const useSession = () => useContext(SessionContext);
