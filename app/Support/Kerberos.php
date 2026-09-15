<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Fachlogikfreie Helfer rund um Kerberos-Namen.
 *
 * Alle Werte landen in Konfigurationsdateien (krb5.conf, Apache) bzw. in Aufrufen von
 * `ktutil`. Sie werden deshalb streng geprüft; ungültige Eingaben ergeben null und führen
 * dazu, dass die Einrichtung abbricht, statt eine Datei mit Fremdinhalt zu schreiben.
 */
final class Kerberos
{
    /** Realm aus den DC-Bestandteilen eines DN ableiten, z. B. „DC=firma,DC=local“ → „FIRMA.LOCAL“. */
    public static function realmFromDn(string ...$dns): ?string
    {
        foreach ($dns as $dn) {
            if (preg_match_all('/DC=([^,]+)/i', $dn, $matches) === 0) {
                continue;
            }
            $parts = array_values(array_filter(array_map(static fn (string $p): string => trim($p), $matches[1]), static fn (string $p): bool => $p !== ''));
            if ($parts !== []) {
                return self::realm(implode('.', $parts));
            }
        }

        return null;
    }

    /** Realm normalisieren (Großbuchstaben) und prüfen. */
    public static function realm(?string $value): ?string
    {
        $realm = strtoupper(trim((string) $value));

        return $realm !== '' && preg_match('/^[A-Z0-9][A-Z0-9.-]{0,252}$/', $realm) === 1 ? $realm : null;
    }

    /**
     * Hostnamen aus Host, „host:port“ oder einer URL gewinnen (Kleinschreibung).
     * Liefert null, wenn kein gültiger DNS-Name übrig bleibt.
     */
    public static function host(?string $value): ?string
    {
        $host = trim((string) $value);
        if ($host === '') {
            return null;
        }
        if (str_contains($host, '://')) {
            $host = (string) (parse_url($host, PHP_URL_HOST) ?: '');
        }
        $host = trim($host, '/');
        if (preg_match('/^(?<host>\[[^\]]+\]|[^:]+):\d+$/', $host, $matches) === 1) {
            $host = $matches['host'];
        }
        $host = strtolower(trim($host, '[]'));

        return $host !== '' && preg_match('/^[a-z0-9]([a-z0-9.-]{0,252}[a-z0-9])?$/', $host) === 1 ? $host : null;
    }

    /**
     * Anmeldenamen des Dienstkontos ermitteln – akzeptiert „DOMAIN\\konto“, „konto@domain“
     * und einen DN („CN=svc-assets,OU=Service,DC=…“).
     */
    public static function accountName(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^CN=(?<cn>(?:[^,\\\\]|\\\\.)+)/i', $raw, $matches) === 1) {
            $raw = str_replace(['\\,', '\\+', '\\"', '\\<', '\\>', '\\;', '\\='], [',', '+', '"', '<', '>', ';', '='], $matches['cn']);
        }
        $backslash = strrpos($raw, '\\');
        if ($backslash !== false) {
            $raw = substr($raw, $backslash + 1);
        }
        $at = strpos($raw, '@');
        if ($at !== false) {
            $raw = substr($raw, 0, $at);
        }
        $raw = trim($raw);

        return $raw !== '' && preg_match('/^[A-Za-z0-9._$-]{1,64}$/', $raw) === 1 ? $raw : null;
    }

    /** Dienstprinzipal des Webservers: HTTP/<fqdn>@REALM (SPN am AD-Dienstkonto). */
    public static function servicePrincipal(string $host, string $realm): ?string
    {
        $host = self::host($host);
        $realm = self::realm($realm);

        return $host === null || $realm === null ? null : "HTTP/{$host}@{$realm}";
    }

    /**
     * Salt, mit dem das Active Directory den Schlüssel eines Benutzerkontos ableitet:
     * Realm + sAMAccountName. Er wird benötigt, weil der Schlüssel im Keytab auf den
     * Dienstprinzipal (HTTP/…) lautet, das Passwort aber zum Benutzerkonto gehört.
     */
    public static function userSalt(string $realm, string $account): ?string
    {
        $realm = self::realm($realm);
        $account = self::accountName($account);

        return $realm === null || $account === null ? null : $realm . $account;
    }

    /**
     * Liste von KDC-Hostnamen aus einer kommagetrennten Angabe.
     * @return list<string>
     */
    public static function hostList(?string $value): array
    {
        $hosts = [];
        foreach (explode(',', (string) $value) as $entry) {
            $host = self::host($entry);
            if ($host !== null && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }
}
