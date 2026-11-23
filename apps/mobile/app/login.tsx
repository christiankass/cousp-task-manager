import { useState } from "react";
import { KeyboardAvoidingView, Platform, ScrollView, Text, TextInput, View } from "react-native";
import { Button, colors, styles } from "@/components/ui";
import { ApiError } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { useSession } from "@/lib/session";

export default function LoginScreen() {
  const { login } = useSession();
  const { t, locale, setLocale } = useI18n();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [needsCode, setNeedsCode] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      await login(email, password, code);
    } catch (e) {
      if (e instanceof ApiError) {
        if (e.body.code === "INSTITUTION_REQUIRED") setNeedsCode(true);
        setError(e.message);
      } else {
        setError(t("networkError"));
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: colors.primary }} behavior={Platform.OS === "ios" ? "padding" : undefined}>
      <ScrollView contentContainerStyle={{ flexGrow: 1, justifyContent: "center", padding: 24 }} keyboardShouldPersistTaps="handled">
        <Text style={{ color: "#fff", fontSize: 30, fontWeight: "800", textAlign: "center" }}>{t("appName")}</Text>
        <Text style={{ color: "#CBD5E1", textAlign: "center", marginBottom: 24 }}>COUSP · INSP</Text>

        <View style={[styles.card, { gap: 12, padding: 20 }]}>
          <Text style={styles.h1}>{t("loginTitle")}</Text>
          <View>
            <Text style={styles.label}>{t("email")}</Text>
            <TextInput
              style={styles.input}
              value={email}
              onChangeText={setEmail}
              autoCapitalize="none"
              autoComplete="email"
              keyboardType="email-address"
              testID="email"
            />
          </View>
          <View>
            <Text style={styles.label}>{t("password")}</Text>
            <TextInput style={styles.input} value={password} onChangeText={setPassword} secureTextEntry testID="password" />
          </View>
          {needsCode && (
            <View>
              <Text style={styles.label}>{t("institutionCode")}</Text>
              <TextInput style={styles.input} value={code} onChangeText={setCode} autoCapitalize="characters" />
            </View>
          )}
          {error && <Text style={styles.error}>{error}</Text>}
          <Button title={t("signIn")} onPress={submit} loading={busy} disabled={!email || !password} />
        </View>

        <Button
          title={locale === "fr" ? "English" : "Français"}
          variant="secondary"
          onPress={() => setLocale(locale === "fr" ? "en" : "fr")}
          style={{ marginTop: 16, alignSelf: "center", backgroundColor: "transparent", borderColor: "#fff" }}
        />
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
