# COUSP Task — application mobile (Expo)

Application Android / iOS pour les agents et responsables : connexion, accueil, mes tâches,
fiche tâche (démarrer, mettre en attente, soumettre, valider / demander des corrections),
commentaires, photos jointes, notifications (dans l'appli et push), langue FR/EN.

## Lancer en développement

```bash
cd apps/mobile
npm install
# Adresse de l'API (par défaut http://10.0.2.2:8000/api/v1 = émulateur Android → machine hôte)
EXPO_PUBLIC_API_URL=http://192.168.1.20:8000/api/v1 npx expo start
```

Scannez le QR code avec l'application **Expo Go** (même réseau Wi-Fi que l'ordinateur qui fait tourner l'API).

## Notifications push

Le téléphone envoie son jeton Expo à `POST /api/v1/devices` après la connexion ; le serveur
l'utilise pour chaque notification de tâche (`PUSH_NOTIFICATIONS=true`). Les notifications
push ne fonctionnent que sur un vrai téléphone. Pour une version installable (APK / TestFlight),
créer un projet EAS (`eas init`) : son `projectId` est ajouté à `app.json` automatiquement.

## Vérifications

```bash
npm run typecheck
```
