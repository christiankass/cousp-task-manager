import { router, useFocusEffect } from "expo-router";
import { useCallback, useState } from "react";
import { FlatList, Pressable, Text, View } from "react-native";
import { Empty, Loading, TaskRow, colors, styles } from "@/components/ui";
import { api, type Task } from "@/lib/api";
import { useI18n } from "@/lib/i18n";

type Filter = "open" | "all";

export default function MyTasksScreen() {
  const { t, locale } = useI18n();
  const [filter, setFilter] = useState<Filter>("open");
  const [tasks, setTasks] = useState<Task[] | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    const status = filter === "open" ? "&status=todo,in_progress,on_hold,rejected,submitted" : "";
    try {
      const r = await api<Task[]>(`tasks?assignee=me${status}&sort=due_at&per_page=100`, { locale });
      setTasks(r.data);
    } catch {
      setTasks([]);
    }
  }, [filter, locale]);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  return (
    <View style={styles.screen}>
      <View style={{ flexDirection: "row", gap: 8, padding: 16, paddingBottom: 0 }}>
        {(["open", "all"] as Filter[]).map((f) => (
          <Pressable
            key={f}
            onPress={() => setFilter(f)}
            style={[styles.badge, { paddingHorizontal: 14, paddingVertical: 7, backgroundColor: filter === f ? colors.primary : colors.card, borderWidth: 1, borderColor: colors.border }]}
          >
            <Text style={{ color: filter === f ? "#fff" : colors.text, fontWeight: "600" }}>{f === "open" ? t("filterOpen") : t("filterAll")}</Text>
          </Pressable>
        ))}
      </View>
      {tasks === null ? (
        <Loading />
      ) : (
        <FlatList
          data={tasks}
          keyExtractor={(item) => item.id}
          contentContainerStyle={styles.content}
          renderItem={({ item }) => <TaskRow task={item} onPress={() => router.push(`/tache/${item.id}`)} />}
          ListEmptyComponent={<Empty text={t("noTasks")} />}
          refreshing={refreshing}
          onRefresh={async () => {
            setRefreshing(true);
            await load();
            setRefreshing(false);
          }}
        />
      )}
    </View>
  );
}
