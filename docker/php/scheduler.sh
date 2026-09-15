#!/bin/sh
# Einfacher Zeitplaner ohne Cron-Abhängigkeit. Läuft als Hintergrundprozess im App-Container und startet
#  - die AD-Synchronisation alle AD_SYNC_INTERVAL_MINUTES Minuten (0 = aus),
#  - den Help-Desk-Job (SLA-Prüfung, Eskalation, Auto-Close) alle HELPDESK_ESCALATION_INTERVAL_MINUTES Minuten (0 = aus).
# Beide Intervalle werden im Minutentakt geprüft; ein Lauf blockiert den jeweils anderen nicht länger als seine Laufzeit.

ad_interval="${AD_SYNC_INTERVAL_MINUTES:-0}"
case "$ad_interval" in ''|*[!0-9]*) ad_interval=0;; esac
if [ "${AD_ENABLED:-false}" != "true" ]; then ad_interval=0; fi

hd_interval="${HELPDESK_ESCALATION_INTERVAL_MINUTES:-0}"
case "$hd_interval" in ''|*[!0-9]*) hd_interval=0;; esac
if [ "${HELPDESK_ENABLED:-true}" != "true" ] || [ "${HELPDESK_ESCALATION_ENABLED:-true}" != "true" ]; then hd_interval=0; fi

if [ "$ad_interval" -le 0 ] && [ "$hd_interval" -le 0 ]; then
    echo "[scheduler] Keine Jobs geplant (AD_SYNC_INTERVAL_MINUTES=${ad_interval}, HELPDESK_ESCALATION_INTERVAL_MINUTES=${hd_interval})."
    exit 0
fi
[ "$ad_interval" -gt 0 ] && echo "[scheduler] AD-Synchronisation alle ${ad_interval} Minuten."
[ "$hd_interval" -gt 0 ] && echo "[scheduler] Help-Desk-Job alle ${hd_interval} Minuten."

tick=0
while true; do
    sleep 60
    tick=$((tick + 1))
    if [ "$ad_interval" -gt 0 ] && [ $((tick % ad_interval)) -eq 0 ]; then
        php /var/www/html/bin/sync-ad.php --by=scheduler --quiet || echo "[scheduler] AD-Synchronisation fehlgeschlagen (Exit $?)" >&2
    fi
    if [ "$hd_interval" -gt 0 ] && [ $((tick % hd_interval)) -eq 0 ]; then
        php /var/www/html/bin/helpdesk.php process --quiet || echo "[scheduler] Help-Desk-Job fehlgeschlagen (Exit $?)" >&2
    fi
done
