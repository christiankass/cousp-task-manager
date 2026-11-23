import { ActivityIndicator, Pressable, StyleSheet, Text, View, type ViewStyle } from "react-native";
import type { Task } from "@/lib/api";
import { formatDate } from "@/lib/api";
import { useI18n } from "@/lib/i18n";

export const colors = {
  primary: "#0F4C81",
  bg: "#F1F5F9",
  card: "#FFFFFF",
  text: "#0F172A",
  muted: "#64748B",
  border: "#E2E8F0",
  red: "#DC2626",
  green: "#16A34A",
  amber: "#D97706",
};

const statusColors: Record<Task["status"], string> = {
  todo: "#64748B",
  in_progress: "#2563EB",
  on_hold: "#D97706",
  submitted: "#7C3AED",
  validated: "#16A34A",
  rejected: "#DC2626",
};

export function Button({
  title,
  onPress,
  variant = "primary",
  disabled,
  loading,
  style,
}: {
  title: string;
  onPress: () => void;
  variant?: "primary" | "secondary" | "danger";
  disabled?: boolean;
  loading?: boolean;
  style?: ViewStyle;
}) {
  const bg = variant === "primary" ? colors.primary : variant === "danger" ? colors.red : colors.card;
  const fg = variant === "secondary" ? colors.primary : "#fff";
  return (
    <Pressable
      accessibilityRole="button"
      onPress={onPress}
      disabled={disabled || loading}
      style={({ pressed }) => [
        styles.button,
        { backgroundColor: bg, opacity: disabled ? 0.5 : pressed ? 0.85 : 1 },
        variant === "secondary" && { borderWidth: 1, borderColor: colors.primary },
        style,
      ]}
    >
      {loading ? <ActivityIndicator color={fg} /> : <Text style={[styles.buttonText, { color: fg }]}>{title}</Text>}
    </Pressable>
  );
}

export function Badge({ label, color }: { label: string; color: string }) {
  return (
    <View style={[styles.badge, { backgroundColor: color + "1A" }]}>
      <Text style={[styles.badgeText, { color }]}>{label}</Text>
    </View>
  );
}

export function StatusBadge({ status }: { status: Task["status"] }) {
  const { tx } = useI18n();
  return <Badge label={tx(`s_${status}`)} color={statusColors[status]} />;
}

export function TaskRow({ task, onPress }: { task: Task; onPress: () => void }) {
  const { t, tx, locale } = useI18n();
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.card, pressed && { opacity: 0.8 }]}>
      <View style={styles.row}>
        <Text style={styles.ref}>{task.reference}</Text>
        <StatusBadge status={task.status} />
      </View>
      <Text style={styles.title} numberOfLines={2}>
        {task.title}
      </Text>
      <View style={[styles.row, { marginTop: 6 }]}>
        <Text style={[styles.meta, task.is_overdue && { color: colors.red, fontWeight: "600" }]}>
          {t("dueAt")} : {formatDate(task.due_at, locale)}
          {task.is_overdue ? ` · ${t("overdue")}` : ""}
        </Text>
        {(task.priority === "high" || task.priority === "critical") && (
          <Badge label={tx(`p_${task.priority}`)} color={task.priority === "critical" ? colors.red : colors.amber} />
        )}
      </View>
    </Pressable>
  );
}

export function Loading() {
  return (
    <View style={styles.center}>
      <ActivityIndicator size="large" color={colors.primary} />
    </View>
  );
}

export function Empty({ text }: { text: string }) {
  return <Text style={[styles.meta, { textAlign: "center", padding: 24 }]}>{text}</Text>;
}

export const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 16, gap: 12 },
  center: { flex: 1, alignItems: "center", justifyContent: "center", padding: 24 },
  card: { backgroundColor: colors.card, borderRadius: 12, padding: 14, borderWidth: 1, borderColor: colors.border },
  row: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: 8 },
  ref: { fontSize: 12, color: colors.muted, fontWeight: "600" },
  title: { fontSize: 16, fontWeight: "600", color: colors.text, marginTop: 4 },
  meta: { fontSize: 13, color: colors.muted, flexShrink: 1 },
  h1: { fontSize: 22, fontWeight: "700", color: colors.text },
  h2: { fontSize: 17, fontWeight: "700", color: colors.text, marginTop: 8 },
  label: { fontSize: 13, color: colors.muted, marginBottom: 4 },
  input: {
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 10,
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: 15,
    color: colors.text,
  },
  button: { borderRadius: 10, paddingVertical: 12, paddingHorizontal: 16, alignItems: "center", justifyContent: "center", minHeight: 46 },
  buttonText: { fontSize: 15, fontWeight: "600" },
  badge: { borderRadius: 999, paddingHorizontal: 8, paddingVertical: 3 },
  badgeText: { fontSize: 12, fontWeight: "600" },
  error: { color: colors.red, fontSize: 14 },
});
