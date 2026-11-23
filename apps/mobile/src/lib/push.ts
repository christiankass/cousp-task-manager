import Constants from "expo-constants";
import * as Device from "expo-device";
import * as Notifications from "expo-notifications";
import * as SecureStore from "expo-secure-store";
import { Platform } from "react-native";
import { api } from "./api";

const PUSH_KEY = "push_token";

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: true,
    shouldSetBadge: true,
  }),
});

/**
 * Demande l'autorisation, récupère le jeton Expo du téléphone et l'envoie au serveur.
 * Ne fait rien sur un simulateur ou si l'utilisateur refuse.
 */
export async function registerForPush(): Promise<string | null> {
  if (!Device.isDevice) return null;

  if (Platform.OS === "android") {
    await Notifications.setNotificationChannelAsync("default", {
      name: "COUSP Task",
      importance: Notifications.AndroidImportance.HIGH,
    });
  }

  const current = await Notifications.getPermissionsAsync();
  const status = current.granted ? "granted" : (await Notifications.requestPermissionsAsync()).status;
  if (status !== "granted") return null;

  const projectId = Constants.expoConfig?.extra?.eas?.projectId as string | undefined;
  const { data: token } = await Notifications.getExpoPushTokenAsync(projectId ? { projectId } : undefined);

  await api("devices", { method: "POST", body: JSON.stringify({ token, platform: Platform.OS === "ios" ? "ios" : "android" }) });
  await SecureStore.setItemAsync(PUSH_KEY, token);
  return token;
}

/** Oublie le jeton local et le renvoie pour que le serveur le supprime à la déconnexion. */
export async function unregisterPush(): Promise<string | null> {
  const token = await SecureStore.getItemAsync(PUSH_KEY);
  await SecureStore.deleteItemAsync(PUSH_KEY);
  return token;
}
