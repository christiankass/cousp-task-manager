import * as ImagePicker from "expo-image-picker";
import { Stack, useFocusEffect, useLocalSearchParams } from "expo-router";
import { useCallback, useState } from "react";
import { Alert, KeyboardAvoidingView, Platform, ScrollView, Text, TextInput, View } from "react-native";
import { Badge, Button, Loading, StatusBadge, colors, styles } from "@/components/ui";
import { ApiError, api, formatDate, type Task, type TaskStatus } from "@/lib/api";
import { useI18n } from "@/lib/i18n";

type Comment = { id: string; content: string; author: { name: string }; created_at: string };
type Attachment = { id: string; file_name: string; uploaded_by: { name: string }; created_at: string };

export default function TaskScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, tx, locale } = useI18n();
  const [task, setTask] = useState<Task | null>(null);
  const [comments, setComments] = useState<Comment[]>([]);
  const [attachments, setAttachments] = useState<Attachment[]>([]);
  const [note, setNote] = useState("");
  const [comment, setComment] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [tk, c, a] = await Promise.all([
        api<Task>(`tasks/${id}`, { locale }),
        api<Comment[]>(`tasks/${id}/comments`, { locale }),
        api<Attachment[]>(`tasks/${id}/attachments`, { locale }),
      ]);
      setTask(tk.data);
      setComments(c.data);
      setAttachments(a.data);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : t("networkError"));
    }
  }, [id, locale, t]);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  /** Lance une action et recharge la fiche ; affiche l'erreur du serveur s'il refuse. */
  const run = async (key: string, fn: () => Promise<unknown>) => {
    setBusy(key);
    setError(null);
    try {
      await fn();
      setNote("");
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.details : t("networkError"));
    } finally {
      setBusy(null);
    }
  };

  const setStatus = (status: TaskStatus) =>
    run(status, () => api(`tasks/${id}/status`, { method: "PATCH", locale, body: JSON.stringify({ status, reason: note || undefined }) }));

  const submit = () => run("submit", () => api(`tasks/${id}/submit`, { method: "POST", locale, body: JSON.stringify({ comment: note || undefined }) }));
  const approve = () => run("approve", () => api(`tasks/${id}/approve`, { method: "POST", locale, body: JSON.stringify({ comments: note || undefined }) }));
  const reject = () => {
    if (!note.trim()) {
      setError(t("notePlaceholder"));
      return;
    }
    run("reject", () => api(`tasks/${id}/reject`, { method: "POST", locale, body: JSON.stringify({ comments: note }) }));
  };

  const sendComment = () =>
    run("comment", async () => {
      await api(`tasks/${id}/comments`, { method: "POST", locale, body: JSON.stringify({ content: comment }) });
      setComment("");
    });

  const addPhoto = async (fromCamera: boolean) => {
    const permission = fromCamera ? await ImagePicker.requestCameraPermissionsAsync() : await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!permission.granted) return;
    const options: ImagePicker.ImagePickerOptions = { mediaTypes: ["images"], quality: 0.6 };
    const result = fromCamera ? await ImagePicker.launchCameraAsync(options) : await ImagePicker.launchImageLibraryAsync(options);
    if (result.canceled || !result.assets[0]) return;

    const asset = result.assets[0];
    const form = new FormData();
    form.append("file", {
      uri: asset.uri,
      name: asset.fileName ?? `photo-${Date.now()}.jpg`,
      type: asset.mimeType ?? "image/jpeg",
    } as unknown as Blob);

    await run("photo", () => api(`tasks/${id}/attachments`, { method: "POST", locale, body: form }));
    Alert.alert(t("uploaded"));
  };

  if (!task) return error ? <Text style={[styles.error, { padding: 16 }]}>{error}</Text> : <Loading />;

  const responsible = task.assignees?.find((a) => a.role === "responsible");
  const can = task.can;
  const showNote = can?.execute || can?.submit || can?.decide;

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === "ios" ? "padding" : undefined} keyboardVerticalOffset={90}>
      <Stack.Screen options={{ title: task.reference }} />
      <ScrollView style={styles.screen} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        <View style={styles.card}>
          <View style={styles.row}>
            <StatusBadge status={task.status} />
            <Badge label={tx(`p_${task.priority}`)} color={task.priority === "critical" ? colors.red : task.priority === "high" ? colors.amber : colors.muted} />
          </View>
          <Text style={[styles.h1, { marginTop: 8 }]}>{task.title}</Text>
          <Text style={[styles.meta, { marginTop: 8 }, task.is_overdue && { color: colors.red, fontWeight: "700" }]}>
            {t("dueAt")} : {formatDate(task.due_at, locale)}
            {task.is_overdue ? ` · ${t("overdue")}` : ""}
          </Text>
          {responsible && (
            <Text style={styles.meta}>
              {t("responsible")} : {responsible.name}
            </Text>
          )}
          {task.organization_unit && (
            <Text style={styles.meta}>
              {t("unit")} : {task.organization_unit.name}
            </Text>
          )}
          {task.description ? (
            <>
              <Text style={[styles.label, { marginTop: 10 }]}>{t("description")}</Text>
              <Text style={{ color: colors.text }}>{task.description}</Text>
            </>
          ) : null}
          {task.expected_result ? (
            <>
              <Text style={[styles.label, { marginTop: 10 }]}>{t("expectedResult")}</Text>
              <Text style={{ color: colors.text }}>{task.expected_result}</Text>
            </>
          ) : null}
        </View>

        {showNote && (
          <View style={[styles.card, { gap: 10 }]}>
            <TextInput style={[styles.input, { minHeight: 60 }]} placeholder={t("notePlaceholder")} value={note} onChangeText={setNote} multiline />
            {can?.execute && (task.status === "todo" || task.status === "rejected") && (
              <Button title={t("start")} onPress={() => setStatus("in_progress")} loading={busy === "in_progress"} />
            )}
            {can?.execute && task.status === "on_hold" && <Button title={t("resume")} onPress={() => setStatus("in_progress")} loading={busy === "in_progress"} />}
            {can?.execute && task.status === "in_progress" && (
              <Button title={t("putOnHold")} variant="secondary" onPress={() => setStatus("on_hold")} loading={busy === "on_hold"} />
            )}
            {can?.submit && <Button title={t("submit")} onPress={submit} loading={busy === "submit"} />}
            {can?.decide && (
              <>
                <Button title={t("approve")} onPress={approve} loading={busy === "approve"} style={{ backgroundColor: colors.green }} />
                <Button title={t("reject")} variant="danger" onPress={reject} loading={busy === "reject"} />
              </>
            )}
          </View>
        )}

        {error && <Text style={styles.error}>{error}</Text>}

        <Text style={styles.h2}>
          {t("attachments")} ({attachments.length})
        </Text>
        {attachments.map((a) => (
          <View key={a.id} style={styles.card}>
            <Text style={{ color: colors.text }}>{a.file_name}</Text>
            <Text style={styles.meta}>
              {a.uploaded_by.name} · {formatDate(a.created_at, locale)}
            </Text>
          </View>
        ))}
        {can?.execute && (
          <View style={{ flexDirection: "row", gap: 10 }}>
            <Button title={t("addPhoto")} variant="secondary" onPress={() => addPhoto(true)} loading={busy === "photo"} style={{ flex: 1 }} />
            <Button title={t("addFromGallery")} variant="secondary" onPress={() => addPhoto(false)} style={{ flex: 1 }} />
          </View>
        )}

        <Text style={styles.h2}>
          {t("comments")} ({comments.length})
        </Text>
        {comments.map((c) => (
          <View key={c.id} style={styles.card}>
            <Text style={[styles.meta, { fontWeight: "600" }]}>
              {c.author.name} · {formatDate(c.created_at, locale)}
            </Text>
            <Text style={{ color: colors.text, marginTop: 4 }}>{c.content}</Text>
          </View>
        ))}
        <View style={{ flexDirection: "row", gap: 8 }}>
          <TextInput style={[styles.input, { flex: 1 }]} placeholder={t("addComment")} value={comment} onChangeText={setComment} multiline />
          <Button title={t("send")} onPress={sendComment} disabled={!comment.trim()} loading={busy === "comment"} />
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
