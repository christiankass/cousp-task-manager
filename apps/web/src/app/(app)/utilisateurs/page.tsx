"use client";

import { useEffect, useState } from "react";
import { api, type User } from "@/lib/api";
import { useI18n } from "@/lib/i18n";

export default function UsersPage() {
  const { t } = useI18n();
  const [users, setUsers] = useState<User[] | null>(null);

  useEffect(() => {
    api<User[]>("users?per_page=100").then((r) => setUsers(r.data));
  }, []);

  return (
    <div>
      <h1 className="mb-4 text-2xl font-bold">{t("users")}</h1>
      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="bg-slate-50 text-slate-600">
            <tr>
              <th className="p-3">{t("name")}</th>
              <th className="p-3">{t("email")}</th>
              <th className="p-3">{t("units")}</th>
              <th className="p-3">{t("roles")}</th>
            </tr>
          </thead>
          <tbody>
            {users === null ? (
              <tr><td className="p-3" colSpan={4}>{t("loading")}</td></tr>
            ) : (
              users.map((u) => (
                <tr key={u.id} className="border-t border-slate-100">
                  <td className="p-3 font-medium">{u.name}</td>
                  <td className="p-3 text-slate-600">{u.email}</td>
                  <td className="p-3">{u.units?.map((x) => x.name).join(", ")}</td>
                  <td className="p-3">{u.roles?.map((x) => x.name).join(", ")}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
