#!/usr/bin/env bash
# Test de restauration : vérifie qu'une sauvegarde peut vraiment être relue.
# Restaure la sauvegarde la plus récente (ou celle donnée en argument) dans une base
# temporaire « cousp_restore_test », compte les principales données, puis supprime cette base.
# La base de production n'est jamais touchée.
#
#   infrastructure/production/scripts/restore-test.sh
#   infrastructure/production/scripts/restore-test.sh /srv/cousp-backups/cousp-20261201-0200.db.dump.gpg
set -euo pipefail

cd "$(dirname "$0")/../../.."
ENV_FILE="${ENV_FILE:-.env.production}"
COMPOSE=(docker compose -f docker-compose.prod.yml --env-file "$ENV_FILE")
conf() { grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

BACKUP_DIR="${BACKUP_DIR:-$(conf BACKUP_DIR)}"
PASSFILE="${BACKUP_PASSPHRASE_FILE:-$(conf BACKUP_PASSPHRASE_FILE)}"
ADMIN="$(conf POSTGRES_ADMIN_USER)"; ADMIN="${ADMIN:-cousp_admin}"
TEST_DB=cousp_restore_test

DUMP="${1:-$(ls -1t "$BACKUP_DIR"/cousp-*.db.dump.gpg 2>/dev/null | head -n1)}"
[ -f "$DUMP" ] || { echo "Aucune sauvegarde trouvée dans $BACKUP_DIR" >&2; exit 1; }
echo "Sauvegarde testée : $DUMP"

# 1. Intégrité du fichier (empreinte enregistrée au moment de la sauvegarde).
SUM="${DUMP%.db.dump.gpg}.sha256"
if [ -f "$SUM" ]; then
  ( cd "$(dirname "$DUMP")" && grep "$(basename "$DUMP")" "$(basename "$SUM")" | sha256sum -c --quiet ) && echo "Empreinte : OK"
fi

psql_admin() { "${COMPOSE[@]}" exec -T postgres psql -v ON_ERROR_STOP=1 -U "$ADMIN" -d postgres "$@"; }
cleanup() { psql_admin -qc "DROP DATABASE IF EXISTS $TEST_DB;" >/dev/null 2>&1 || true; }
trap cleanup EXIT

# 2. Déchiffrement et restauration dans une base temporaire.
cleanup
psql_admin -qc "CREATE DATABASE $TEST_DB;"
gpg --batch --quiet --decrypt --passphrase-file "$PASSFILE" "$DUMP" \
  | "${COMPOSE[@]}" exec -T postgres pg_restore -U "$ADMIN" -d "$TEST_DB" --no-owner --no-privileges --exit-on-error

# 3. Contrôle du contenu.
echo "Contenu restauré :"
"${COMPOSE[@]}" exec -T postgres psql -U "$ADMIN" -d "$TEST_DB" -At -F ' : ' -c "
  SELECT 'institutions', count(*) FROM institutions UNION ALL
  SELECT 'utilisateurs', count(*) FROM users UNION ALL
  SELECT 'tâches', count(*) FROM tasks UNION ALL
  SELECT 'réunions', count(*) FROM meetings UNION ALL
  SELECT 'journal d''audit', count(*) FROM audit_logs UNION ALL
  SELECT 'migrations', count(*) FROM migrations;"

users=$("${COMPOSE[@]}" exec -T postgres psql -U "$ADMIN" -d "$TEST_DB" -At -c "SELECT count(*) FROM users;")
[ "${users//[^0-9]/}" -gt 0 ] || { echo "ÉCHEC : la sauvegarde ne contient aucun utilisateur" >&2; exit 1; }

echo "Test de restauration réussi. La base temporaire est supprimée."
