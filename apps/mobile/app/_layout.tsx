import * as Notifications from "expo-notifications";
import { Stack, router, useSegments } from "expo-router";
import { StatusBar } from "expo-status-bar";
import { useEffect } from "react";
import { SafeAreaProvider } from "react-native-safe-area-context";
import { Loading, colors } from "@/components/ui";
import { I18nProvider } from "@/lib/i18n";
import { SessionProvider, useSession } from "@/lib/session";

/** Redirige vers la connexion quand personne n'est connecté, et inversement. */
function AuthGate() {
  const { me, loading } = useSession();
  const segments = useSegments();

  useEffect(() => {
    if (loading) return;
    const onLogin = segments[0] === "login";
    if (!me && !onLogin) router.replace("/login");
    if (me && onLogin) router.replace("/");
  }, [me, loading, segments]);

  // Ouvre la tâche quand on touche une notification push.
  useEffect(() => {
    const sub = Notifications.addNotificationResponseReceivedListener((response) => {
      const taskId = response.notification.request.content.data?.task_id;
      if (typeof taskId === "string") router.push(`/tache/${taskId}`);
    });
    return () => sub.remove();
  }, []);

  if (loading) return <Loading />;

  return (
    <Stack
      screenOptions={{
        headerStyle: { backgroundColor: colors.primary },
        headerTintColor: "#fff",
        contentStyle: { backgroundColor: colors.bg },
      }}
    >
      <Stack.Screen name="login" options={{ headerShown: false }} />
      <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
      <Stack.Screen name="tache/[id]" options={{ title: "" }} />
    </Stack>
  );
}

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <I18nProvider>
        <SessionProvider>
          <StatusBar style="light" />
          <AuthGate />
        </SessionProvider>
      </I18nProvider>
    </SafeAreaProvider>
  );
}
