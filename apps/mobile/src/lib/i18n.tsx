import { getLocales } from "expo-localization";
import * as SecureStore from "expo-secure-store";
import { createContext, useContext, useEffect, useState, type ReactNode } from "react";

const fr = {
  appName: "COUSP Task",
  loginTitle: "Connexion",
  email: "Adresse e-mail",
  password: "Mot de passe",
  institutionCode: "Code institution (facultatif)",
  signIn: "Se connecter",
  home: "Accueil",
  myTasks: "Mes tâches",
  toValidate: "À valider",
  notifications: "Notifications",
  profile: "Profil",
  hello: "Bonjour",
  inProgress: "En cours",
  overdue: "En retard",
  total: "Total",
  noTasks: "Aucune tâche",
  dueAt: "Échéance",
  responsible: "Responsable",
  unit: "Structure",
  description: "Description",
  expectedResult: "Résultat attendu",
  start: "Commencer",
  resume: "Reprendre",
  putOnHold: "Mettre en attente",
  submit: "Soumettre pour validation",
  approve: "Valider",
  reject: "Demander des corrections",
  notePlaceholder: "Motif ou commentaire",
  comments: "Commentaires",
  addComment: "Écrire un commentaire…",
  send: "Envoyer",
  addPhoto: "Prendre une photo",
  addFromGallery: "Choisir une photo",
  attachments: "Documents joints",
  uploaded: "Photo envoyée",
  language: "Langue",
  logout: "Se déconnecter",
  markAllRead: "Tout marquer comme lu",
  error: "Erreur",
  networkError: "Connexion impossible. Vérifiez votre réseau.",
  retry: "Réessayer",
  filterOpen: "En cours",
  filterAll: "Toutes",
  s_todo: "À faire", s_in_progress: "En cours", s_on_hold: "En attente", s_submitted: "Soumise", s_validated: "Validée", s_rejected: "À corriger",
  p_low: "Basse", p_normal: "Normale", p_high: "Élevée", p_critical: "Critique",
};

const en: typeof fr = {
  appName: "COUSP Task",
  loginTitle: "Sign in",
  email: "Email address",
  password: "Password",
  institutionCode: "Institution code (optional)",
  signIn: "Sign in",
  home: "Home",
  myTasks: "My tasks",
  toValidate: "To approve",
  notifications: "Notifications",
  profile: "Profile",
  hello: "Hello",
  inProgress: "In progress",
  overdue: "Overdue",
  total: "Total",
  noTasks: "No tasks",
  dueAt: "Due",
  responsible: "Lead",
  unit: "Unit",
  description: "Description",
  expectedResult: "Expected result",
  start: "Start",
  resume: "Resume",
  putOnHold: "Put on hold",
  submit: "Submit for approval",
  approve: "Approve",
  reject: "Request corrections",
  notePlaceholder: "Reason or comment",
  comments: "Comments",
  addComment: "Write a comment…",
  send: "Send",
  addPhoto: "Take a photo",
  addFromGallery: "Choose a photo",
  attachments: "Attachments",
  uploaded: "Photo uploaded",
  language: "Language",
  logout: "Log out",
  markAllRead: "Mark all as read",
  error: "Error",
  networkError: "Cannot connect. Check your network.",
  retry: "Retry",
  filterOpen: "Open",
  filterAll: "All",
  s_todo: "To do", s_in_progress: "In progress", s_on_hold: "Pending", s_submitted: "Submitted", s_validated: "Approved", s_rejected: "To correct",
  p_low: "Low", p_normal: "Normal", p_high: "High", p_critical: "Critical",
};

export type Locale = "fr" | "en";
export type Key = keyof typeof fr;
const dictionaries = { fr, en };

type Ctx = { locale: Locale; setLocale: (l: Locale) => void; t: (k: Key) => string; tx: (k: string) => string };
const I18nContext = createContext<Ctx>({ locale: "fr", setLocale: () => {}, t: (k) => fr[k], tx: (k) => k });

export function I18nProvider({ children }: { children: ReactNode }) {
  const device = getLocales()[0]?.languageCode === "en" ? "en" : "fr";
  const [locale, setLocaleState] = useState<Locale>(device);

  useEffect(() => {
    SecureStore.getItemAsync("locale").then((v) => (v === "fr" || v === "en") && setLocaleState(v));
  }, []);

  const setLocale = (l: Locale) => {
    setLocaleState(l);
    SecureStore.setItemAsync("locale", l);
  };

  const dict = dictionaries[locale];
  return (
    <I18nContext.Provider value={{ locale, setLocale, t: (k) => dict[k], tx: (k) => (dict as Record<string, string>)[k] ?? k }}>
      {children}
    </I18nContext.Provider>
  );
}

export const useI18n = () => useContext(I18nContext);
