import { ScrollView, Text, View } from "react-native";
import { Button, styles } from "@/components/ui";
import { api, API_URL } from "@/lib/api";
import { useI18n, type Locale } from "@/lib/i18n";
import { useSession } from "@/lib/session";

export default function ProfileScreen() {
  const { me, logout } = useSession();
  const { t, locale, setLocale } = useI18n();

  const changeLanguage = (l: Locale) => {
    setLocale(l);
    // La langue choisie sert aussi pour les e-mails et notifications envoyés par le serveur.
    if (me) api("auth/preferences", { method: "PUT", body: JSON.stringify({ preferred_language: l }) }).catch(() => {});
  };

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content}>
      <View style={styles.card}>
        <Text style={styles.h1}>{me?.user.name}</Text>
        <Text style={styles.meta}>{me?.user.email}</Text>
        <Text style={[styles.meta, { marginTop: 6 }]}>{me?.institution.name}</Text>
      </View>

      <Text style={styles.h2}>{t("language")}</Text>
      <View style={{ flexDirection: "row", gap: 10 }}>
        <Button title="Français" variant={locale === "fr" ? "primary" : "secondary"} onPress={() => changeLanguage("fr")} style={{ flex: 1 }} />
        <Button title="English" variant={locale === "en" ? "primary" : "secondary"} onPress={() => changeLanguage("en")} style={{ flex: 1 }} />
      </View>

      <Button title={t("logout")} variant="danger" onPress={logout} style={{ marginTop: 24 }} />
      <Text style={[styles.meta, { fontSize: 11, textAlign: "center", marginTop: 12 }]}>{API_URL}</Text>
    </ScrollView>
  );
}
