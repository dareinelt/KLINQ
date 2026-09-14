#!/bin/sh
set -e

# Wartet auf MySQL, führt Migrationen + Seeder aus und startet danach den eigentlichen Prozess.
if [ "${SKIP_MIGRATIONS:-0}" != "1" ]; then
    echo "[entrypoint] Warte auf Datenbank ${DB_HOST:-mysql}:${DB_PORT:-3306} ..."
    i=0
    until php -r 'try { new PDO(sprintf("mysql:host=%s;port=%s", getenv("DB_HOST") ?: "mysql", getenv("DB_PORT") ?: "3306"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0); } catch (Throwable $e) { exit(1); }'; do
        i=$((i+1))
        if [ "$i" -ge 60 ]; then
            echo "[entrypoint] Datenbank nicht erreichbar – Abbruch." >&2
            exit 1
        fi
        sleep 2
    done
    php bin/migrate.php
fi

mkdir -p storage/uploads storage/logs storage/labels storage/tmp
chown -R www-data:www-data storage

# Zeitgesteuerte AD-Synchronisation als Hintergrundprozess (nur wenn konfiguriert)
if [ "${AD_ENABLED:-false}" = "true" ] && [ "${AD_SYNC_INTERVAL_MINUTES:-0}" != "0" ]; then
    su -s /bin/sh www-data -c "/usr/local/bin/app-scheduler" &
fi

exec "$@"
