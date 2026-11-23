#!/usr/bin/env bash
# Sauvegarde quotidienne chiffrée de COUSP Task Manager : base de données + fichiers joints.
#
# À lancer depuis la racine du dépôt, sur le serveur (tâche cron de root, voir le guide) :
#   infrastructure/production/scripts/backup.sh
#
# Produit, dans BACKUP_DIR :
#   cousp-AAAAMMJJ-HHMM.db.dump.gpg     base PostgreSQL (format pg_dump « custom »)
#   cousp-AAAAMMJJ-HHMM.files.tar.gz.gpg   pièces jointes et rapports de mission
#   cousp-AAAAMMJJ-HHMM.sha256          empreintes pour vérifier l'intégrité
# Les fichiers sont chiffrés (AES-256) avec la phrase secrète de BACKUP_PASSPHRASE_FILE.
set -euo pipefail

cd "$(dirname "$0")/../../.."
ENV_FILE="${ENV_FILE:-.env.production}"
COMPOSE=(docker compose -f docker-compose.prod.yml --env-file "$ENV_FILE")

# Lit une valeur de .env.production sans exécuter le fichier.
conf() { grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

BACKUP_DIR="${BACKUP_DIR:-$(conf BACKUP_DIR)}"
RETENTION="${BACKUP_RETENTION_DAYS:-$(conf BACKUP_RETENTION_DAYS)}"
PASSFILE="${BACKUP_PASSPHRASE_FILE:-$(conf BACKUP_PASSPHRASE_FILE)}"
DB="$(conf POSTGRES_DB)"; DB="${DB:-cousp_task}"
ADMIN="$(conf POSTGRES_ADMIN_USER)"; ADMIN="${ADMIN:-cousp_admin}"
RETENTION="${RETENTION:-30}"

[ -n "$BACKUP_DIR" ] || { echo "BACKUP_DIR non défini" >&2; exit 1; }
[ -r "$PASSFILE" ] || { echo "Phrase secrète introuvable : $PASSFILE" >&2; exit 1; }
command -v gpg >/dev/null || { echo "gpg n'est pas installé (apt install gnupg)" >&2; exit 1; }

umask 077
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
STAMP="$(date +%Y%m%d-%H%M)"
BASE="$BACKUP_DIR/cousp-$STAMP"
encrypt() { gpg --batch --yes --quiet --symmetric --cipher-algo AES256 --passphrase-file "$PASSFILE" -o "$1"; }

echo "[$(date -Is)] Sauvegarde de la base $DB…"
"${COMPOSE[@]}" exec -T postgres pg_dump -U "$ADMIN" -d "$DB" --format=custom --no-owner --no-privileges | encrypt "$BASE.db.dump.gpg"

echo "[$(date -Is)] Sauvegarde des fichiers joints…"
"${COMPOSE[@]}" exec -T api tar -czf - -C /var/www/html/storage app | encrypt "$BASE.files.tar.gz.gpg"

( cd "$BACKUP_DIR" && sha256sum "cousp-$STAMP.db.dump.gpg" "cousp-$STAMP.files.tar.gz.gpg" > "cousp-$STAMP.sha256" )

# Une sauvegarde vide ou minuscule signale un problème : on échoue bruyamment.
size=$(stat -c %s "$BASE.db.dump.gpg")
[ "$size" -gt 10000 ] || { echo "Sauvegarde de la base anormalement petite ($size octets)" >&2; exit 1; }

echo "[$(date -Is)] Suppression des sauvegardes de plus de $RETENTION jours…"
find "$BACKUP_DIR" -maxdepth 1 -name 'cousp-*' -type f -mtime +"$RETENTION" -delete

echo "[$(date -Is)] Terminé : $BASE.*"
