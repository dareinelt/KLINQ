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

# Windows-SSO: Keytab (aus dem AD-Dienstkonto), krb5.conf und Apache-Konfiguration erzeugen.
# Schlaegt die Einrichtung fehl, startet Apache ohne Kerberos weiter – die Anwendung bleibt nutzbar.
if [ "${KERBEROS_ENABLED:-false}" = "true" ]; then
    if ! php bin/kerberos-setup.php; then
        echo "[entrypoint] Kerberos-Einrichtung fehlgeschlagen - Windows-SSO bleibt inaktiv." >&2
    fi
fi

mkdir -p storage/uploads storage/logs storage/labels storage/tmp
chown -R www-data:www-data storage

# Zeitgesteuerte Jobs (AD-Synchronisation, Help-Desk-SLA/Eskalation, Help-Desk-E-Mail-Eingang) als Hintergrundprozess (nur wenn konfiguriert)
ad_scheduled=0; hd_scheduled=0; mail_scheduled=0
if [ "${AD_ENABLED:-false}" = "true" ] && [ "${AD_SYNC_INTERVAL_MINUTES:-0}" != "0" ]; then ad_scheduled=1; fi
if [ "${HELPDESK_ENABLED:-true}" = "true" ] && [ "${HELPDESK_ESCALATION_ENABLED:-true}" = "true" ] && [ "${HELPDESK_ESCALATION_INTERVAL_MINUTES:-5}" != "0" ]; then hd_scheduled=1; fi
if [ "${HELPDESK_ENABLED:-true}" = "true" ] && [ "${HELPDESK_MAIL_ENABLED:-false}" = "true" ] && [ "${HELPDESK_MAIL_INTERVAL_MINUTES:-2}" != "0" ]; then mail_scheduled=1; fi
if [ "$ad_scheduled" = "1" ] || [ "$hd_scheduled" = "1" ] || [ "$mail_scheduled" = "1" ]; then
    su -s /bin/sh www-data -c "/usr/local/bin/app-scheduler" &
fi

exec "$@"
