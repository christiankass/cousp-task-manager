"use client";

import { createContext, useContext, useEffect, useState, type ReactNode } from "react";

const dictionaries = {
  fr: {
    appName: "COUSP Task Manager",
    login: "Connexion",
    loginTitle: "Connexion à la plateforme",
    email: "Adresse e-mail",
    password: "Mot de passe",
    institutionCode: "Code de l'institution (si vous en avez plusieurs)",
    signIn: "Se connecter",
    signingIn: "Connexion…",
    logout: "Se déconnecter",
    dashboard: "Tableau de bord",
    tasks: "Gestion des tâches",
    planning: "Planification",
    users: "Équipes et utilisateurs",
    organisation: "Structures institutionnelles",
    meetings: "Réunions et recommandations",
    emergencies: "Urgences sanitaires",
    notifications: "Notifications",
    documents: "Documents",
    reports: "Rapports et statistiques",
    audit: "Audit et sécurité",
    settings: "Paramètres",
    comingSoon: "Bientôt disponible",
    welcome: "Bonjour",
    myUnits: "Mes structures",
    myRoles: "Mes rôles",
    permissions: "Mes autorisations",
    name: "Nom",
    roles: "Rôles",
    units: "Structures",
    status: "Statut",
    manager: "Responsable",
    noData: "Aucune donnée",
    loading: "Chargement…",
    language: "Langue",
    nextSteps: "Les modules de tâches, de validation et de rapports arrivent dans les prochaines étapes.",
  },
  en: {
    appName: "COUSP Task Manager",
    login: "Login",
    loginTitle: "Sign in to the platform",
    email: "Email address",
    password: "Password",
    institutionCode: "Institution code (if you have several)",
    signIn: "Sign in",
    signingIn: "Signing in…",
    logout: "Log out",
    dashboard: "Dashboard",
    tasks: "Task management",
    planning: "Planning",
    users: "Teams and users",
    organisation: "Organisation units",
    meetings: "Meetings and recommendations",
    emergencies: "Health emergencies",
    notifications: "Notifications",
    documents: "Documents",
    reports: "Reports and statistics",
    audit: "Audit and security",
    settings: "Settings",
    comingSoon: "Coming soon",
    welcome: "Hello",
    myUnits: "My units",
    myRoles: "My roles",
    permissions: "My permissions",
    name: "Name",
    roles: "Roles",
    units: "Units",
    status: "Status",
    manager: "Manager",
    noData: "No data",
    loading: "Loading…",
    language: "Language",
    nextSteps: "Task, approval and reporting modules are coming in the next steps.",
  },
} as const;

export type Locale = keyof typeof dictionaries;
export type MessageKey = keyof (typeof dictionaries)["fr"];

const I18nContext = createContext<{ locale: Locale; setLocale: (l: Locale) => void; t: (k: MessageKey) => string }>({
  locale: "fr",
  setLocale: () => undefined,
  t: (k) => dictionaries.fr[k],
});

export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>("fr");

  useEffect(() => {
    try {
      const saved = localStorage.getItem("locale");
      if (saved === "fr" || saved === "en") setLocaleState(saved);
    } catch {}
  }, []);

  const setLocale = (l: Locale) => {
    setLocaleState(l);
    try {
      localStorage.setItem("locale", l);
    } catch {}
  };

  return (
    <I18nContext.Provider value={{ locale, setLocale, t: (k) => dictionaries[locale][k] }}>{children}</I18nContext.Provider>
  );
}

export const useI18n = () => useContext(I18nContext);
