#!/bin/sh
# Premier démarrage de PostgreSQL : crée le compte utilisé par l'application.
# Ce compte n'est PAS super-utilisateur et ne contourne pas la sécurité par ligne (RLS),
# sinon l'isolation entre institutions ne serait plus garantie par la base.
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE ROLE ${APP_DB_USER} LOGIN PASSWORD '${APP_DB_PASSWORD}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
ALTER DATABASE ${POSTGRES_DB} OWNER TO ${APP_DB_USER};
ALTER SCHEMA public OWNER TO ${APP_DB_USER};
SQL
