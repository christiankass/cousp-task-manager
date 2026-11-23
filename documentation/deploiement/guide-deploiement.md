# Guide de mise en ligne : COUSP Task Manager

Ce guide explique comment installer la plateforme sur un serveur, la mettre à jour, la
sauvegarder et la restaurer. Il s'adresse à la personne chargée de l'informatique. Chaque
commande se copie telle quelle.

---

## 1. Ce qu'il faut avant de commencer

| Élément | Recommandation |
|---|---|
| Serveur | Linux Ubuntu 24.04, 4 processeurs, 8 Go de mémoire, 100 Go de disque (SSD) |
| Accès | Un compte administrateur (`sudo`) sur le serveur, par SSH |
| Nom de domaine | Une adresse, par exemple `taches.cousp.cd`, qui pointe vers l'adresse IP du serveur |
| Pare-feu | Ports 80 et 443 ouverts vers Internet, port 22 (SSH) limité à l'équipe informatique |
| E-mail | Un compte SMTP pour l'envoi des notifications (adresse, identifiant, mot de passe) |
| Sauvegardes | Un second emplacement hors du serveur (autre serveur, disque externe, stockage cloud) |

L'application web, l'API, l'application mobile et la base de données passent toutes par
cette seule adresse en HTTPS.

## 2. Installer Docker sur le serveur

```bash
sudo apt update && sudo apt install -y ca-certificates curl git gnupg
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER      # puis se déconnecter et se reconnecter
docker compose version             # doit afficher une version 2 ou plus
```

## 3. Récupérer l'application

```bash
sudo mkdir -p /srv/cousp && sudo chown $USER /srv/cousp
git clone https://github.com/christiankass/cousp-task-manager.git /srv/cousp
cd /srv/cousp
```

## 4. Remplir les réglages

```bash
cp infrastructure/production/env.production.example .env.production
chmod 600 .env.production
nano .env.production
```

À compléter obligatoirement :

1. `APP_DOMAIN`, `APP_URL`, `WEB_URL` : l'adresse choisie (par exemple `taches.cousp.cd`).
2. `POSTGRES_ADMIN_PASSWORD`, `APP_DB_PASSWORD`, `REDIS_PASSWORD` : trois mots de passe
   différents. Pour en créer un : `openssl rand -base64 32`.
3. Les réglages `MAIL_…` du compte d'envoi d'e-mails.
4. `APP_KEY`, la clé de chiffrement. Une fois les mots de passe remplis, lancer :

   ```bash
   docker compose -f docker-compose.prod.yml --env-file .env.production build api
   docker compose -f docker-compose.prod.yml --env-file .env.production run --rm --no-deps --entrypoint php api artisan key:generate --show
   ```

   Copier le résultat (`base64:…`) dans `APP_KEY`.

> **Important.** Gardez une copie de `.env.production` dans un endroit sûr, hors du serveur
> (coffre-fort de mots de passe). La clé `APP_KEY` protège les secrets de la connexion en
> deux étapes : si elle est perdue, chaque utilisateur devra la réactiver.

`MFA_ENFORCE_PRIVILEGED=true` (valeur conseillée) oblige les comptes qui administrent la
plateforme (utilisateurs, rôles, paramètres, circuits de validation) à activer la connexion
en deux étapes avant de pouvoir travailler. Les autres comptes peuvent l'activer quand ils
le souhaitent depuis **Sécurité du compte**.

## 5. Obtenir le certificat HTTPS (une seule fois)

Le certificat est gratuit (Let's Encrypt). Le nom de domaine doit déjà pointer vers le serveur.

```bash
DOMAIN=taches.cousp.cd     # votre adresse
docker compose -f docker-compose.prod.yml --env-file .env.production run --rm -p 80:80 \
  --entrypoint certbot certbot certonly --standalone -d $DOMAIN \
  --agree-tos -m informatique@cousp.cd --no-eff-email
```

Il est ensuite renouvelé automatiquement par le conteneur `certbot`.

## 6. Démarrer la plateforme

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build
docker compose -f docker-compose.prod.yml --env-file .env.production ps
```

Tous les services doivent être `running` (ou `healthy`). Au premier démarrage, la base de
données est créée et les tables sont installées automatiquement.

Pour vérifier : ouvrir `https://taches.cousp.cd/up` (doit afficher une page verte), puis
`https://taches.cousp.cd`.

### Premier compte et premières données

```bash
C="docker compose -f docker-compose.prod.yml --env-file .env.production"

# 1. Rôles et permissions de référence (une fois ; en production, aucun compte de démonstration n'est créé)
$C exec api php artisan db:seed --force

# 2. L'institution et son premier administrateur
$C exec api php artisan cousp:create-institution COUSP "Centre des Opérations d'Urgence de Santé Publique" \
   --admin-email=admin@cousp.cd --admin-name="Administrateur COUSP" --platform-admin
```

La commande affiche **une seule fois** un mot de passe provisoire. Connectez-vous avec, puis :

1. activez la connexion en deux étapes dans **Sécurité du compte** ;
2. créez les structures (directions, services) et les comptes des équipes ;
3. pour une autre institution (par exemple l'INSP), relancez l'étape 2 avec son code et son nom.

`--platform-admin` donne à ce compte la gestion de toutes les institutions : ne l'utilisez que
pour le compte de l'équipe informatique.

## 7. Ce qui tourne en permanence

| Service | Rôle |
|---|---|
| `nginx` | Porte d'entrée HTTPS, envoie chaque demande au web ou à l'API |
| `web` | Application web |
| `api` | API (utilisée par le web et l'application mobile) |
| `scheduler` | Toutes les 15 min : retards et échéances proches. Toutes les heures : création des tâches récurrentes |
| `queue` | Envoi des e-mails et des notifications sur téléphone |
| `postgres` | Base de données |
| `redis` | Mémoire rapide et file d'attente |
| `certbot` | Renouvellement du certificat HTTPS |

Les conteneurs redémarrent seuls après une panne ou un redémarrage du serveur.

Voir les journaux d'un service : `docker compose -f docker-compose.prod.yml logs -f --tail=100 api`

## 8. Sauvegardes

### Mise en place (une fois)

```bash
sudo apt install -y gnupg
# Phrase secrète de chiffrement : à recopier AUSSI dans un coffre-fort hors du serveur
openssl rand -base64 48 | sudo tee /root/.cousp-backup-passphrase >/dev/null
sudo chmod 600 /root/.cousp-backup-passphrase
sudo mkdir -p /srv/cousp-backups
```

Programmer une sauvegarde chaque nuit à 2 h (heure du serveur) :

```bash
sudo crontab -e
```

et ajouter la ligne :

```
0 2 * * * /srv/cousp/infrastructure/production/scripts/backup.sh >> /var/log/cousp-backup.log 2>&1
```

Chaque sauvegarde contient la base de données et les fichiers joints, chiffrés. Les
sauvegardes de plus de 30 jours (réglage `BACKUP_RETENTION_DAYS`) sont supprimées.

### Copie hors du serveur (indispensable)

Une sauvegarde qui reste sur le serveur disparaît avec lui. Copiez chaque nuit le dossier
`/srv/cousp-backups` ailleurs, par exemple :

```
30 3 * * * rsync -a /srv/cousp-backups/ sauvegarde@autre-serveur:/sauvegardes/cousp/
```

### Test de restauration (une fois par mois)

```bash
cd /srv/cousp
sudo infrastructure/production/scripts/restore-test.sh
```

Le script relit la dernière sauvegarde dans une base temporaire, affiche le nombre
d'institutions, d'utilisateurs et de tâches, puis supprime cette base. La base réelle n'est
pas touchée. Notez la date et le résultat dans le registre d'exploitation.

## 9. Restaurer après un incident

À faire seulement si les données de production sont perdues ou abîmées.

```bash
cd /srv/cousp
C="docker compose -f docker-compose.prod.yml --env-file .env.production"
DUMP=/srv/cousp-backups/cousp-AAAAMMJJ-HHMM.db.dump.gpg     # sauvegarde choisie
FILES=${DUMP%.db.dump.gpg}.files.tar.gz.gpg

# 1. Arrêter l'application (la base reste allumée)
$C stop web api scheduler queue

# 2. Recréer la base vide et la rendre au compte de l'application
$C exec -T postgres psql -U cousp_admin -d postgres -c "DROP DATABASE cousp_task;"
$C exec -T postgres psql -U cousp_admin -d postgres -c "CREATE DATABASE cousp_task OWNER cousp_app;"

# 3. Restaurer la base (le compte de l'application redevient propriétaire des tables)
sudo gpg --batch --decrypt --passphrase-file /root/.cousp-backup-passphrase "$DUMP" \
  | $C exec -T postgres pg_restore -U cousp_admin -d cousp_task --no-owner --role=cousp_app --exit-on-error

# 4. Restaurer les fichiers joints
$C start api
sudo gpg --batch --decrypt --passphrase-file /root/.cousp-backup-passphrase "$FILES" \
  | $C exec -T api tar -xzf - -C /var/www/html/storage

# 5. Redémarrer tout
$C up -d
```

Remplacez `cousp_admin`, `cousp_app` et `cousp_task` si vous avez changé ces noms.

## 10. Mettre à jour la plateforme

```bash
cd /srv/cousp
sudo infrastructure/production/scripts/backup.sh     # toujours sauvegarder avant
git pull
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build
```

Les nouvelles tables éventuelles sont installées automatiquement au redémarrage de `api`.

## 11. Application mobile

Dans `apps/mobile`, l'adresse de l'API se règle avec `EXPO_PUBLIC_API_URL`, par exemple
`https://taches.cousp.cd/api/v1`. Les fichiers d'installation Android et iOS se produisent
avec EAS Build (`npx eas build`), voir `apps/mobile/README.md`.

## 12. Liste de contrôle de sécurité

- [ ] `.env.production` n'est lisible que par l'administrateur (`chmod 600`) et n'est pas dans Git.
- [ ] `APP_DEBUG` est à `false` (c'est imposé par `docker-compose.prod.yml`).
- [ ] Le compte PostgreSQL de l'application n'est pas super-utilisateur (créé ainsi automatiquement).
- [ ] `MFA_ENFORCE_PRIVILEGED=true`, et chaque administrateur a activé la connexion en deux étapes.
- [ ] Les comptes de démonstration n'existent pas en production.
- [ ] Sauvegarde nocturne active, copiée hors du serveur, test de restauration fait ce mois-ci.
- [ ] Le port 22 (SSH) n'est ouvert qu'à l'équipe informatique ; connexion SSH par clé uniquement.
- [ ] Les mises à jour de sécurité du système sont automatiques : `sudo apt install unattended-upgrades`.
