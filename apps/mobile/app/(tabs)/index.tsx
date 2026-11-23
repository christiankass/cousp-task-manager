import { router, useFocusEffect } from "expo-router";
import { useCallback, useState } from "react";
import { RefreshControl, ScrollView, Text, View } from "react-native";
import { Empty, TaskRow, colors, styles } from "@/components/ui";
import { api, type Task } from "@/lib/api";
import { useI18n } from "@/lib/i18n";
import { useSession } from "@/lib/session";

type Overview = { total: number; overdue: number; by_status: Record<Task["status"], number> };

function Kpi({ label, value, color = colors.primary }: { label: string; value: number | string; color?: string }) {
  return (
    <View style={[styles.card, { flex: 1, alignItems: "center" }]}>
      <Text style={{ fontSize: 26, fontWeight: "800", color }}>{value}</Text>
      <Text style={styles.meta}>{label}</Text>
    </View>
  );
}

export default function HomeScreen() {
  const { me } = useSession();
  const { t, locale } = useI18n();
  const [overview, setOverview] = useState<Overview | null>(null);
  const [mine, setMine] = useState<Task[]>([]);
  const [toValidate, setToValidate] = useState<Task[]>([]);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState(false);

  const load = useCallback(async () => {
    try {
      const [o, m, v] = await Promise.all([
        api<Overview>("dashboard/overview", { locale }),
        api<Task[]>("tasks?assignee=me&status=todo,in_progress,on_hold,rejected&sort=due_at&per_page=5", { locale }),
        api<Task[]>("tasks?status=submitted&sort=due_at&per_page=50", { locale }),
      ]);
      setOverview(o.data);
      setMine(m.data);
      setToValidate(v.data.filter((x) => x.can?.decide));
      setError(false);
    } catch {
      setError(true);
    }
  }, [locale]);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  const onRefresh = async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  };

  const open = (task: Task) => router.push(`/tache/${task.id}`);

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}>
      <View>
        <Text style={styles.h1}>
          {t("hello")}, {me?.user.name}
        </Text>
        <Text style={styles.meta}>{me?.institution.name}</Text>
      </View>

      {error && <Text style={styles.error}>{t("networkError")}</Text>}

      {overview && (
        <View style={{ flexDirection: "row", gap: 10 }}>
          <Kpi label={t("total")} value={overview.total} />
          <Kpi label={t("inProgress")} value={overview.by_status.in_progress} color="#2563EB" />
          <Kpi label={t("overdue")} value={overview.overdue} color={colors.red} />
        </View>
      )}

      {toValidate.length > 0 && (
        <>
          <Text style={styles.h2}>
            {t("toValidate")} ({toValidate.length})
          </Text>
          {toValidate.map((task) => (
            <TaskRow key={task.id} task={task} onPress={() => open(task)} />
          ))}
        </>
      )}

      <Text style={styles.h2}>{t("myTasks")}</Text>
      {mine.length === 0 ? <Empty text={t("noTasks")} /> : mine.map((task) => <TaskRow key={task.id} task={task} onPress={() => open(task)} />)}
    </ScrollView>
  );
}
