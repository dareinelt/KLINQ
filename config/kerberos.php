<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * Windows-Single-Sign-on über Kerberos (SPNEGO/„Negotiate“).
 *
 * Der Apache im Container prüft das Kerberos-Ticket des Clients mit mod_auth_gssapi und
 * setzt daraufhin REMOTE_USER. Die dafür nötige `/etc/krb5.conf` und die Apache-Konfiguration
 * werden beim Containerstart aus diesen Werten erzeugt (`php bin/kerberos-setup.php`).
 *
 * Leere Werte werden – soweit möglich – aus den AD_*-Variablen abgeleitet:
 *  - Realm            aus AD_BASE_DN (DC=…) bzw. AD_HOST
 *  - KDC              aus AD_HOST (Domänencontroller)
 *  - Dienstprincipal  aus APP_URL („HTTP/<host>@<REALM>“)
 *  - Dienstkonto      aus AD_BIND_DN/AD_BIND_PASSWORD (dasselbe Konto wie für die AD-Synchronisation)
 */
return [
    'enabled' => Env::bool('KRB5_ENABLED', false),
    // Kerberos-Realm, immer in Großbuchstaben (leer = aus AD_BASE_DN/AD_HOST ableiten)
    'realm' => trim((string) Env::get('KRB5_REALM', '')),
    // Domänencontroller als KDC, mehrere kommagetrennt (leer = AD_HOST)
    'kdc' => trim((string) Env::get('KRB5_KDC', '')),
    // KDC zusätzlich per DNS-SRV suchen (nur sinnvoll, wenn der Container die AD-DNS-Server nutzt)
    'dns_lookup_kdc' => Env::bool('KRB5_DNS_LOOKUP_KDC', false),
    // Dienstprincipal des Webservers, z. B. HTTP/assets.example.local@EXAMPLE.LOCAL (leer = aus APP_URL ableiten)
    'service_principal' => trim((string) Env::get('KRB5_SERVICE_PRINCIPAL', '')),
    // AD-Konto, auf das der Dienstprincipal registriert ist (setspn). Leer = Konto aus AD_BIND_DN,
    // also dasselbe Dienstkonto, das auch die AD-Synchronisation verwendet.
    'service_account' => trim((string) Env::get('KRB5_SERVICE_ACCOUNT', '')),
    // Passwort dieses Kontos; leer = AD_BIND_PASSWORD
    'service_password' => (string) (Env::get('KRB5_SERVICE_PASSWORD', '') !== '' ? Env::get('KRB5_SERVICE_PASSWORD', '') : Env::get('AD_BIND_PASSWORD', '')),
    // Keytab mit dem Schlüssel des Dienstkontos
    'keytab' => trim((string) Env::get('KRB5_KEYTAB', '/etc/apache2/keytab/http.keytab')) ?: '/etc/apache2/keytab/http.keytab',
    // Keytab beim Containerstart aus dem Kontopasswort erzeugen (false = vorhandene Keytab-Datei verwenden)
    'keytab_auto' => Env::bool('KRB5_KEYTAB_AUTO', true),
    // Schlüsselversion des Kontos; 0 = per LDAP aus msDS-KeyVersionNumber lesen
    'kvno' => max(0, Env::int('KRB5_KVNO', 0)),
    // Verschlüsselungsarten der Keytab (AD unterstützt AES seit Windows Server 2008)
    'enctypes' => trim((string) Env::get('KRB5_ENCTYPES', 'aes256-cts-hmac-sha1-96,aes128-cts-hmac-sha1-96')),
    // optional = nur der SSO-Einstiegspunkt verlangt ein Ticket (Anwendung bleibt ohne Kerberos nutzbar)
    // required = die gesamte Anwendung ist nur mit gültigem Kerberos-Ticket erreichbar
    'mode' => strtolower(trim((string) Env::get('KRB5_MODE', 'optional'))) === 'required' ? 'required' : 'optional',
    // Kerberos nur über HTTPS zulassen (empfohlen, sobald die Anwendung hinter TLS läuft)
    'ssl_only' => Env::bool('KRB5_SSL_ONLY', false),
    // Rückfall auf Benutzername/Passwort gegen das AD, wenn der Browser kein Ticket schickt (Basic-Auth!)
    'basic_fallback' => Env::bool('KRB5_BASIC_FALLBACK', false),
    // Erkanntes Konto so lange in der Browsersitzung weiterverwenden (Minuten)
    'session_ttl_minutes' => max(1, Env::int('KRB5_SESSION_TTL_MINUTES', 480)),
    // Nach einem erfolglosen Versuch erst nach dieser Zeit erneut ein Ticket anfordern (Sekunden, verhindert Schleifen)
    'retry_after_seconds' => max(10, Env::int('KRB5_RETRY_AFTER_SECONDS', 900)),
    // Pfade innerhalb der Anwendung (fest verdrahtet in routes/modules/sso.php)
    'entry_path' => '/sso/anmeldung',
    'failure_path' => '/sso/fehlgeschlagen',
    // Zieldateien im Container (werden beim Start erzeugt)
    'krb5_conf_path' => trim((string) Env::get('KRB5_CONF_PATH', '/etc/krb5.conf')) ?: '/etc/krb5.conf',
    'apache_conf_path' => trim((string) Env::get('KRB5_APACHE_CONF_PATH', '/etc/apache2/conf-available/zz-kerberos.conf')) ?: '/etc/apache2/conf-available/zz-kerberos.conf',
];
