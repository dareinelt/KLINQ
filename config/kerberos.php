<?php

declare(strict_types=1);

use App\Core\Env;
use App\Support\Kerberos;

/**
 * Windows-SSO per Kerberos (Apache mod_auth_gssapi).
 *
 * Es wird bewusst **dasselbe** AD-Dienstkonto wie für die AD-Synchronisation verwendet
 * (AD_BIND_DN/AD_BIND_PASSWORD). Aus dessen Passwort erzeugt der Container beim Start ein
 * Keytab für den Dienstprinzipal HTTP/<Servername>@REALM; der SPN muss im AD einmalig auf
 * dieses Konto gesetzt sein (setspn -S HTTP/<Servername> <Konto>).
 */
$realm = Kerberos::realm(Env::get('KERBEROS_REALM', ''))
    ?? Kerberos::realmFromDn((string) Env::get('AD_BASE_DN', ''), (string) Env::get('AD_BIND_DN', ''))
    ?? '';
$host = Kerberos::host(Env::get('KERBEROS_SERVICE_HOST', ''))
    ?? Kerberos::host(Env::get('APP_URL', ''))
    ?? '';
$kdcs = Kerberos::hostList(Env::get('KERBEROS_KDC', ''));
if ($kdcs === []) {
    $kdcs = Kerberos::hostList(Env::get('AD_HOST', ''));
}

return [
    'enabled' => Env::bool('KERBEROS_ENABLED', false),
    // probe = nur /sso/pruefung verlangt Kerberos (Standard), required = gesamte Anwendung
    'mode' => Env::get('KERBEROS_MODE', 'probe') === 'required' ? 'required' : 'probe',
    'realm' => $realm,
    'kdc' => $kdcs,
    'service_host' => $host,
    'service_principal' => $host !== '' && $realm !== '' ? Kerberos::servicePrincipal($host, $realm) : null,
    // Dienstkonto: identisch mit dem Konto der AD-Synchronisation
    'account' => [
        'dn' => (string) Env::get('AD_BIND_DN', ''),
        'name' => Kerberos::accountName(Env::get('AD_BIND_DN', '')) ?? '',
        'password' => (string) Env::get('AD_BIND_PASSWORD', ''),
    ],
    'keytab' => trim((string) Env::get('KERBEROS_KEYTAB', '/etc/apache2/krb5.keytab')) ?: '/etc/apache2/krb5.keytab',
    // Schlüsselversion des Dienstkontos; 0 = beim Start aus dem AD lesen (msDS-KeyVersionNumber)
    'kvno' => max(0, Env::int('KERBEROS_KVNO', 0)),
    'krb5_conf' => trim((string) Env::get('KERBEROS_KRB5_CONF', '/etc/krb5.conf')) ?: '/etc/krb5.conf',
    'apache_conf' => trim((string) Env::get('KERBEROS_APACHE_CONF', '/etc/apache2/conf-enabled/kerberos.conf')) ?: '/etc/apache2/conf-enabled/kerberos.conf',
    // Verschlüsselungsverfahren des Keytabs (AD ab Windows Server 2008: AES)
    'enctypes' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('KERBEROS_ENCTYPES', 'aes256-cts-hmac-sha1-96,aes128-cts-hmac-sha1-96'))))),
    // Aushandlung nur über HTTPS zulassen
    'ssl_only' => Env::bool('KERBEROS_SSL_ONLY', false),
    // Rückfall auf Benutzername/Passwort (Basic gegen das AD), wenn der Browser kein Kerberos-Ticket liefert
    'basic_fallback' => Env::bool('KERBEROS_BASIC_FALLBACK', false),
    // Erkannten Benutzer so lange in der Sitzung vorhalten (Minuten, 0 = nur die aktuelle Anfrage)
    'session_ttl_minutes' => max(0, Env::int('KERBEROS_SESSION_TTL_MINUTES', 60)),
];
