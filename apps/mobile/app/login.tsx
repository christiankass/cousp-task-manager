import { useState } from "react";
import { KeyboardAvoidingView, Platform, ScrollView, Text, TextInput, View } from "react-native";
import { Button, colors, styles } from "@/components/ui";
import { ApiError } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { useSession } from "@/lib/session";

export default function LoginScreen() {
  const { login, verifyCode } = useSession();
  const { t, locale, setLocale } = useI18n();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [needsCode, setNeedsCode] = useState(false);
  const [challenge, setChallenge] = useState<string | null>(null);
  const [otp, setOtp] = useState("");
  const [recovery, setRecovery] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      if (challenge) {
        await verifyCode(challenge, otp, recovery);
      } else {
        const next = await login(email, password, code);
        if (next) {
          setChallenge(next.challenge);
          setOtp("");
        }
      }
    } catch (e) {
      if (e instanceof ApiError) {
        if (e.body.code === "INSTITUTION_REQUIRED") setNeedsCode(true);
        if (e.body.code === "MFA_EXPIRED") setChallenge(null);
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

        {challenge ? (
          <View style={[styles.card, { gap: 12, padding: 20 }]}>
            <Text style={styles.h1}>{t("mfaTitle")}</Text>
            <Text style={styles.label}>{recovery ? t("mfaRecoveryHint") : t("mfaHint")}</Text>
            <TextInput
              style={[styles.input, { fontSize: 22, letterSpacing: 6, textAlign: "center" }]}
              value={otp}
              onChangeText={(v) => setOtp(recovery ? v.toUpperCase() : v.replace(/\D/g, ""))}
              keyboardType={recovery ? "default" : "number-pad"}
              autoComplete="one-time-code"
              autoCapitalize="characters"
              maxLength={recovery ? 11 : 6}
              autoFocus
              testID="otp"
            />
            {error && <Text style={styles.error}>{error}</Text>}
            <Button title={t("verify")} onPress={submit} loading={busy} disabled={otp.length < 6} />
            <Button
              title={recovery ? t("mfaUseApp") : t("mfaUseRecovery")}
              variant="secondary"
              onPress={() => {
                setRecovery(!recovery);
                setOtp("");
              }}
            />
            <Button title={t("back")} variant="secondary" onPress={() => setChallenge(null)} />
          </View>
        ) : (
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
        )}

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
