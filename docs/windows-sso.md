# Windows-SSO per Kerberos

Der Webserver im App-Container ermittelt den am Client angemeldeten **Windows-Benutzer** über
Kerberos (SPNEGO). Der Browser legt dafür ein Dienstticket vor, das Apache mit dem Schlüssel
aus seinem Keytab prüft; anschließend steht der Anmeldename in `REMOTE_USER` und damit der
Anwendung zur Verfügung. Anders als ein HTTP-Header ist diese Angabe nicht fälschbar – sie
setzt ein vom Domänencontroller ausgestelltes Ticket voraus.

Verwendet wird **dasselbe AD-Dienstkonto wie für die AD-Synchronisation** (`AD_BIND_DN`,
`AD_BIND_PASSWORD`). Ein zweites Konto ist nicht nötig.

## Voraussetzungen im Active Directory

1. Der Webserver ist unter einem festen DNS-Namen erreichbar (z. B. `assets.firma.local`,
   idealerweise ein A-Record, kein CNAME).
2. Der SPN wird einmalig auf das Dienstkonto gesetzt:

   ```
   setspn -S HTTP/assets.firma.local FIRMA\svc-assets
   ```

3. Das Dienstkonto benötigt keine zusätzlichen Rechte; es muss lediglich sein eigenes
   Attribut `msDS-KeyVersionNumber` lesen dürfen (Standard).
4. Wird das Passwort des Dienstkontos geändert, erhöht das AD die Schlüsselversion – der
   Container erzeugt beim nächsten Start automatisch ein neues Keytab.

## Konfiguration (`.env`)

| Variable | Bedeutung |
|---|---|
| `KERBEROS_ENABLED` | Windows-SSO ein-/ausschalten |
| `KERBEROS_MODE` | `probe` (Standard): nur `/sso/pruefung` verlangt ein Ticket · `required`: gesamte Anwendung |
| `KERBEROS_REALM` | Realm in Großbuchstaben; leer = aus `AD_BASE_DN` abgeleitet (`DC=firma,DC=local` → `FIRMA.LOCAL`) |
| `KERBEROS_KDC` | Domänencontroller (kommagetrennt); leer = `AD_HOST`, sonst DNS-SRV-Einträge |
| `KERBEROS_SERVICE_HOST` | DNS-Name für den SPN `HTTP/<Name>`; leer = Host aus `APP_URL` |
| `KERBEROS_KVNO` | Schlüsselversion; `0` = beim Start aus dem AD lesen |
| `KERBEROS_KEYTAB`, `KERBEROS_ENCTYPES` | Pfad und Verschlüsselungsverfahren des Keytabs |
| `KERBEROS_SSL_ONLY` | Aushandlung nur über HTTPS zulassen |
| `KERBEROS_BASIC_FALLBACK` | Rückfall auf Benutzername/Passwort (Basic gegen das AD), wenn kein Ticket kommt |
| `KERBEROS_SESSION_TTL_MINUTES` | Wie lange der erkannte Benutzer in der Sitzung vorgehalten wird |

## Ablauf beim Containerstart

`docker/php/entrypoint.sh` ruft bei `KERBEROS_ENABLED=true` das Skript
`bin/kerberos-setup.php` auf (`App\Services\Sso\KerberosSetupService`):

1. Realm, KDC und Dienstprinzipal `HTTP/<Servername>@REALM` bestimmen.
2. Schlüsselversion des Dienstkontos per LDAP lesen (`msDS-KeyVersionNumber`).
3. `/etc/krb5.conf` schreiben und mit `ktutil` ein Keytab aus dem Kontopasswort erzeugen.
   Der AD-typische Salt `REALM + sAMAccountName` wird dabei explizit gesetzt, weil der
   Schlüssel zum Benutzerkonto gehört, der Eintrag aber auf den Dienstprinzipal lautet.
   Das Passwort geht ausschließlich über die Standardeingabe an `ktutil`.
4. Keytab mit `kinit -k` gegen den Domänencontroller prüfen.
5. Apache-Konfiguration (`mod_auth_gssapi`) schreiben; das Keytab gehört `root:www-data`
   und ist mit `0640` nur für den Webserver lesbar.

Schlägt ein Schritt fehl, wird die Apache-Konfiguration entfernt, der Grund im Log vermerkt
und der Container startet **ohne** Kerberos weiter – die Anwendung bleibt nutzbar. Eine
manuelle Prüfung ist jederzeit möglich:

```
docker compose exec app php bin/kerberos-setup.php
```

## Erkennung in der Anwendung

`App\Security\WindowsIdentity` liest `REMOTE_USER`/`REDIRECT_REMOTE_USER`/`AUTH_USER`/
`PHP_AUTH_USER` und reduziert `DOMAIN\benutzer` bzw. `benutzer@domain.tld` auf den reinen
Anmeldenamen.

Im Modus `probe` ist nur **eine** URL Kerberos-geschützt: Seiten, die den Benutzer benötigen
(z. B. das Störungsformular `/stoerung`), leiten einmalig auf `/sso/pruefung?next=…` um.
Dort handelt der Browser das Ticket aus; das Ergebnis wird für `KERBEROS_SESSION_TTL_MINUTES`
in der Sitzung gemerkt. Liefert der Browser kein Ticket, ruft Apache `/sso/abbruch` auf: Der
Versuch wird vermerkt (keine Weiterleitungsschleife) und der Besucher kann ohne SSO
weiterarbeiten. Beide Routen ändern keine Daten und geben nur den erkannten Namen zurück.

Im Modus `required` verlangt Apache für alle Seiten (außer `/health`) ein Ticket; `REMOTE_USER`
steht dann bei jeder Anfrage zur Verfügung.

## Clientseitige Hinweise

- Der Aufruf muss über den SPN-Namen erfolgen (`http://assets.firma.local`), nicht über IP.
- Edge/Chrome/Firefox handeln Kerberos in der Intranetzone automatisch aus; ggf. den Namen in
  der Gruppenrichtlinie als „lokales Intranet“ bzw. in `network.negotiate-auth.trusted-uris`
  hinterlegen.
- Der AD-Abgleich der erkannten Kennung (Telefon, Abteilung, Mitarbeiterdatensatz) erfolgt wie
  bisher über `AdUserLookupService` – siehe [Help Desk](helpdesk.md) und [AD-Sync](ad-sync.md).

## Fehlersuche

| Symptom | Ursache |
|---|---|
| `Keytab wurde vom Domänencontroller abgelehnt` | SPN nicht gesetzt, falsches Passwort oder veraltete Schlüsselversion |
| `Schlüsselversion … nicht lesbar` | LDAP nicht erreichbar oder Konto ohne Leserecht – `KERBEROS_KVNO` setzen |
| Browser fragt nach Benutzername/Passwort | Kein Ticket (Aufruf über IP, fremde Zone) – mit `KERBEROS_BASIC_FALLBACK=true` erlaubt |
| `REMOTE_USER` bleibt leer | Modul `auth_gssapi` nicht aktiv (Einrichtung fehlgeschlagen, Log prüfen) |
