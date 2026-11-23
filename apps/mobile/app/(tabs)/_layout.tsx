import { Tabs } from "expo-router";
import { useCallback, useState } from "react";
import { Text, type ColorValue } from "react-native";
import { useFocusEffect } from "expo-router";
import { colors } from "@/components/ui";
import { api } from "@/lib/api";
import { useI18n } from "@/lib/i18n";

const icon = (glyph: string) => ({ color }: { color: ColorValue }) => <Text style={{ color, fontSize: 18 }}>{glyph}</Text>;

export default function TabsLayout() {
  const { t } = useI18n();
  const [unread, setUnread] = useState(0);

  useFocusEffect(
    useCallback(() => {
      api<{ count: number }>("notifications/unread-count")
        .then((r) => setUnread(r.data.count))
        .catch(() => {});
    }, []),
  );

  return (
    <Tabs
      screenOptions={{
        headerStyle: { backgroundColor: colors.primary },
        headerTintColor: "#fff",
        tabBarActiveTintColor: colors.primary,
      }}
    >
      <Tabs.Screen name="index" options={{ title: t("home"), tabBarIcon: icon("⌂") }} />
      <Tabs.Screen name="taches" options={{ title: t("myTasks"), tabBarIcon: icon("☰") }} />
      <Tabs.Screen
        name="notifications"
        options={{ title: t("notifications"), tabBarIcon: icon("🔔"), tabBarBadge: unread > 0 ? unread : undefined }}
      />
      <Tabs.Screen name="profil" options={{ title: t("profile"), tabBarIcon: icon("☺") }} />
    </Tabs>
  );
}
