import { router, useFocusEffect } from "expo-router";
import { useCallback, useState } from "react";
import { FlatList, Pressable, Text, View } from "react-native";
import { Button, Empty, Loading, colors, styles } from "@/components/ui";
import { api, formatDate, type AppNotification } from "@/lib/api";
import { useI18n } from "@/lib/i18n";

export default function NotificationsScreen() {
  const { t, locale } = useI18n();
  const [items, setItems] = useState<AppNotification[] | null>(null);

  const load = useCallback(async () => {
    try {
      const r = await api<AppNotification[]>("notifications?per_page=50", { locale });
      setItems(r.data);
    } catch {
      setItems([]);
    }
  }, [locale]);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  const open = async (n: AppNotification) => {
    if (!n.read_at) {
      api(`notifications/${n.id}/read`, { method: "PATCH" }).catch(() => {});
      setItems((list) => list?.map((x) => (x.id === n.id ? { ...x, read_at: new Date().toISOString() } : x)) ?? null);
    }
    if (n.related_entity_id) router.push(`/tache/${n.related_entity_id}`);
  };

  const markAll = async () => {
    await api("notifications/read-all", { method: "POST" }).catch(() => {});
    load();
  };

  if (items === null) return <Loading />;

  return (
    <FlatList
      style={styles.screen}
      data={items}
      keyExtractor={(n) => n.id}
      contentContainerStyle={styles.content}
      ListHeaderComponent={items.some((n) => !n.read_at) ? <Button title={t("markAllRead")} variant="secondary" onPress={markAll} /> : undefined}
      ListEmptyComponent={<Empty text="—" />}
      renderItem={({ item: n }) => (
        <Pressable onPress={() => open(n)} style={[styles.card, !n.read_at && { borderLeftWidth: 4, borderLeftColor: colors.primary }]}>
          <View style={styles.row}>
            <Text style={[styles.title, { marginTop: 0, flex: 1 }, !n.read_at && { fontWeight: "800" }]}>{n.title}</Text>
          </View>
          <Text style={[styles.meta, { marginTop: 4 }]}>{n.message}</Text>
          <Text style={[styles.meta, { fontSize: 12, marginTop: 6 }]}>{formatDate(n.created_at, locale)}</Text>
        </Pressable>
      )}
    />
  );
}
