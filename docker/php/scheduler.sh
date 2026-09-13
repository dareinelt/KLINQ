#!/bin/sh
# Einfacher Zeitplaner ohne Cron-Abhängigkeit: startet die AD-Synchronisation
# alle AD_SYNC_INTERVAL_MINUTES Minuten (0 = aus). Läuft als Hintergrundprozess im App-Container.
interval="${AD_SYNC_INTERVAL_MINUTES:-0}"
case "$interval" in ''|*[!0-9]*) interval=0;; esac
if [ "$interval" -le 0 ] || [ "${AD_ENABLED:-false}" != "true" ]; then
    echo "[scheduler] AD-Synchronisation nicht geplant (AD_ENABLED=${AD_ENABLED:-false}, AD_SYNC_INTERVAL_MINUTES=${interval})."
    exit 0
fi
echo "[scheduler] AD-Synchronisation alle ${interval} Minuten."
while true; do
    sleep $((interval * 60))
    php /var/www/html/bin/sync-ad.php --by=scheduler --quiet || echo "[scheduler] AD-Synchronisation fehlgeschlagen (Exit $?)" >&2
done
