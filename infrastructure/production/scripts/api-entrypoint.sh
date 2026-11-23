#!/bin/sh
# Démarrage d'un conteneur de l'API (php-fpm, planificateur ou file d'attente).
set -e
cd /var/www/html

# Dossiers attendus dans le volume de stockage (pièces jointes, journaux, caches).
mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs

# Attend que PostgreSQL réponde (au plus 60 secondes).
i=0
until php -r 'try { new PDO("pgsql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 5432).";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
  i=$((i + 1))
  [ "$i" -ge 30 ] && echo "PostgreSQL injoignable" && exit 1
  sleep 2
done

php artisan config:cache
php artisan route:cache
php artisan view:cache

# Seul le conteneur « api » applique les migrations, pour éviter que plusieurs conteneurs le fassent en même temps.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force
fi

exec "$@"
