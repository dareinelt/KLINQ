<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Core\Config;

/**
 * Wirksame Kerberos-Einstellungen des Webservers.
 *
 * Bündelt die Ableitung fehlender Werte (Realm, KDC, Dienstprincipal) aus den AD_*-Angaben,
 * die Prüfung der Konfiguration und die Erzeugung der beiden Dateien, die der Container beim
 * Start schreibt: `/etc/krb5.conf` (Kerberos-Bibliothek) und die Apache-Konfiguration für
 * mod_auth_gssapi. Die Klasse arbeitet ohne Seiteneffekte – geschrieben wird in
 * `bin/kerberos-setup.php`, angezeigt werden die Werte unter `/admin/sso`.
 */
final class KerberosConfig
{
    /**
     * @param array<string,mixed> $kerberos Werte aus config/kerberos.php
     * @param string $adHost AD_HOST (darf ein Schema wie ldaps:// enthalten)
     * @param string $baseDn AD_BASE_DN
     * @param string $appUrl APP_URL
     */
    public function __construct(
        private readonly array $kerberos,
        private readonly string $adHost = '',
        private readonly string $baseDn = '',
        private readonly string $appUrl = ''
    ) {}

    public static function fromConfig(Config $config): self
    {
        return new self(
            (array) $config->get('kerberos', []),
            (string) $config->get('ldap.host', ''),
            (string) $config->get('ldap.base_dn', ''),
            (string) $config->get('app.url', '')
        );
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->kerberos['enabled'] ?? false);
    }

    public function mode(): string
    {
        return ($this->kerberos['mode'] ?? 'optional') === 'required' ? 'required' : 'optional';
    }

    public function entryPath(): string
    {
        return (string) ($this->kerberos['entry_path'] ?? '/sso/anmeldung');
    }

    public function failurePath(): string
    {
        return (string) ($this->kerberos['failure_path'] ?? '/sso/fehlgeschlagen');
    }

    public function keytabPath(): string
    {
        return (string) ($this->kerberos['keytab'] ?? '');
    }

    public function krb5ConfPath(): string
    {
        return (string) ($this->kerberos['krb5_conf_path'] ?? '/etc/krb5.conf');
    }

    public function apacheConfPath(): string
    {
        return (string) ($this->kerberos['apache_conf_path'] ?? '/etc/apache2/conf-available/zz-kerberos.conf');
    }

    public function sessionTtlSeconds(): int
    {
        return max(60, (int) ($this->kerberos['session_ttl_minutes'] ?? 480) * 60);
    }

    public function retryAfterSeconds(): int
    {
        return max(10, (int) ($this->kerberos['retry_after_seconds'] ?? 900));
    }

    public function sslOnly(): bool
    {
        return (bool) ($this->kerberos['ssl_only'] ?? false);
    }

    public function basicFallback(): bool
    {
        return (bool) ($this->kerberos['basic_fallback'] ?? false);
    }

    public function dnsLookupKdc(): bool
    {
        return (bool) ($this->kerberos['dns_lookup_kdc'] ?? false);
    }

    /** Realm in Großbuchstaben: ausdrücklich gesetzt, sonst aus AD_BASE_DN, sonst aus AD_HOST. */
    public function realm(): string
    {
        return strtoupper(trim($this->domain(), '. '));
    }

    /** DNS-Domäne der Anmeldung in Kleinschreibung, z. B. „example.local“. */
    public function domain(): string
    {
        $realm = trim((string) ($this->kerberos['realm'] ?? ''));
        if ($realm !== '') {
            return strtolower(trim($realm, '. '));
        }
        $fromDn = self::domainFromBaseDn($this->baseDn);
        if ($fromDn !== '') {
            return $fromDn;
        }
        // AD_HOST ist typischerweise der Domänencontroller („dc01.example.local“) – erste Marke entfernen
        $host = strtolower(self::hostname($this->adHost));
        $parts = explode('.', $host);

        return count($parts) > 2 ? implode('.', array_slice($parts, 1)) : $host;
    }

    /** Domänencontroller, die als KDC angefragt werden. @return list<string> */
    public function kdcs(): array
    {
        $raw = trim((string) ($this->kerberos['kdc'] ?? ''));
        $values = $raw !== '' ? explode(',', $raw) : [$this->adHost];
        $hosts = [];
        foreach ($values as $value) {
            $host = self::hostname($value);
            if ($host !== '' && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /** Dienstprincipal des Webservers, z. B. „HTTP/assets.example.local@EXAMPLE.LOCAL“. */
    public function servicePrincipal(): string
    {
        $principal = trim((string) ($this->kerberos['service_principal'] ?? ''));
        if ($principal === '') {
            $host = strtolower(self::hostname($this->appUrl));
            $principal = $host !== '' ? 'HTTP/' . $host : '';
        }
        if ($principal !== '' && !str_contains($principal, '@') && $this->realm() !== '') {
            $principal .= '@' . $this->realm();
        }

        return $principal;
    }

    /** Rechnername aus dem Dienstprincipal („assets.example.local“). */
    public function serviceHost(): string
    {
        $principal = $this->servicePrincipal();
        $slash = strpos($principal, '/');
        if ($slash === false) {
            return '';
        }
        $host = substr($principal, $slash + 1);
        $at = strpos($host, '@');

        return $at === false ? $host : substr($host, 0, $at);
    }

    /**
     * Fehler, die Kerberos unmöglich machen (leere Liste = einsatzbereit).
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if ($this->realm() === '') {
            $problems[] = 'Kein Kerberos-Realm ermittelbar: KRB5_REALM oder AD_BASE_DN/AD_HOST setzen.';
        }
        if ($this->kdcs() === [] && !$this->dnsLookupKdc()) {
            $problems[] = 'Kein KDC bekannt: KRB5_KDC (Domänencontroller) oder AD_HOST setzen bzw. KRB5_DNS_LOOKUP_KDC aktivieren.';
        }
        $principal = $this->servicePrincipal();
        if ($principal === '' || !str_contains($principal, '/')) {
            $problems[] = 'Kein Dienstprincipal ermittelbar: KRB5_SERVICE_PRINCIPAL (z. B. HTTP/assets.example.local@EXAMPLE.LOCAL) oder APP_URL setzen.';
        }
        $keytab = $this->keytabPath();
        if ($keytab === '') {
            $problems[] = 'Keine Keytab angegeben: KRB5_KEYTAB setzen.';
        } elseif (!is_file($keytab)) {
            $problems[] = sprintf('Keytab %s fehlt – Datei des Dienstkontos in den Container einhängen.', $keytab);
        } elseif (!is_readable($keytab)) {
            $problems[] = sprintf('Keytab %s ist nicht lesbar (Eigentümer/Rechte prüfen, z. B. chown root:www-data und chmod 640).', $keytab);
        }

        return $problems;
    }

    /** Hinweise, die den Betrieb nicht verhindern. @return list<string> */
    public function warnings(): array
    {
        $warnings = [];
        if ($this->mode() === 'required') {
            $warnings[] = 'Modus „required“: Ohne gültiges Kerberos-Ticket ist die Anwendung nicht erreichbar – auch nicht die lokale Anmeldung.';
        }
        if (!$this->sslOnly()) {
            $warnings[] = 'KRB5_SSL_ONLY ist aus: Kerberos-Tickets werden auch über unverschlüsseltes HTTP entgegengenommen.';
        }
        if ($this->basicFallback()) {
            $warnings[] = 'KRB5_BASIC_FALLBACK ist an: Ohne Ticket fragt der Browser Benutzername/Passwort per Basic-Auth ab – nur mit HTTPS betreiben.';
        }
        $host = self::hostname($this->appUrl);
        if ($host !== '' && $this->serviceHost() !== '' && strcasecmp($host, $this->serviceHost()) !== 0) {
            $warnings[] = sprintf('APP_URL zeigt auf %s, der Dienstprincipal gilt für %s – Kerberos schlägt fehl, wenn die Anwendung unter einem anderen Namen aufgerufen wird.', $host, $this->serviceHost());
        }
        if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $warnings[] = 'APP_URL verwendet eine IP-Adresse; Windows fordert nur für Rechnernamen (FQDN) ein Kerberos-Ticket an.';
        }

        return $warnings;
    }

    /** Inhalt der /etc/krb5.conf. */
    public function renderKrb5Conf(): string
    {
        $realm = $this->realm();
        $domain = $this->domain();
        $kdcs = $this->kdcs();
        $kdcLines = '';
        foreach ($kdcs as $kdc) {
            $kdcLines .= sprintf("        kdc = %s\n", $kdc);
        }

        return "# Automatisch erzeugt von bin/kerberos-setup.php – Änderungen werden beim Containerstart überschrieben.\n"
            . "[libdefaults]\n"
            . sprintf("    default_realm = %s\n", $realm)
            . "    dns_lookup_realm = false\n"
            . sprintf("    dns_lookup_kdc = %s\n", $this->dnsLookupKdc() ? 'true' : 'false')
            . "    rdns = false\n"
            . "    forwardable = true\n"
            . "    ticket_lifetime = 24h\n"
            . "    renew_lifetime = 7d\n"
            . "    udp_preference_limit = 0\n"
            . "\n[realms]\n"
            . sprintf("    %s = {\n", $realm)
            . $kdcLines
            . (isset($kdcs[0]) ? sprintf("        admin_server = %s\n", $kdcs[0]) : '')
            . ($domain !== '' ? sprintf("        default_domain = %s\n", $domain) : '')
            . "    }\n"
            . ($domain !== '' ? sprintf("\n[domain_realm]\n    .%s = %s\n    %s = %s\n", $domain, $realm, $domain, $realm) : '');
    }

    /** Inhalt der Apache-Konfiguration für mod_auth_gssapi. */
    public function renderApacheConf(): string
    {
        $conf = "# Automatisch erzeugt von bin/kerberos-setup.php – Änderungen werden beim Containerstart überschrieben.\n"
            . "# Windows-SSO: Apache prüft das Kerberos-Ticket des Clients (SPNEGO) und setzt REMOTE_USER.\n"
            . "<IfModule auth_gssapi_module>\n"
            . "    # Einstiegspunkt: verlangt immer ein Ticket; die Anwendung leitet gezielt hierher weiter.\n"
            . sprintf("    <Location \"%s\">\n%s    </Location>\n\n", $this->entryPath(), $this->authBlock('        '));

        if ($this->mode() === 'required') {
            $conf .= "    # Modus \"required\": die gesamte Anwendung verlangt ein Kerberos-Ticket\n"
                . sprintf("    <Location \"/\">\n%s    </Location>\n\n", $this->authBlock('        '));
        }

        // Die Hinweisseite muss ohne Ticket erreichbar bleiben, sonst entsteht eine Weiterleitungsschleife.
        return $conf
            . sprintf("    <Location \"%s\">\n        Require all granted\n    </Location>\n\n", $this->failurePath())
            . "    # Browser ohne Ticket erhalten eine erklärende Seite statt einer leeren 401-Antwort\n"
            . sprintf("    ErrorDocument 401 %s\n", $this->failurePath())
            . "</IfModule>\n";
    }

    private function authBlock(string $indent): string
    {
        $lines = [
            'AuthType GSSAPI',
            'AuthName "Windows-Anmeldung"',
            sprintf('GssapiCredStore keytab:%s', $this->keytabPath()),
            'GssapiAllowedMech krb5',
            // Der Anmeldename wird in der Anwendung normalisiert; Apache liefert ihn unverändert.
            'GssapiLocalName Off',
            'GssapiNegotiateOnce On',
            sprintf('GssapiSSLonly %s', $this->sslOnly() ? 'On' : 'Off'),
            sprintf('GssapiBasicAuth %s', $this->basicFallback() ? 'On' : 'Off'),
        ];
        if ($this->basicFallback()) {
            $lines[] = 'GssapiBasicAuthMech krb5';
        }
        $lines[] = 'Require valid-user';

        return $indent . implode("\n" . $indent, $lines) . "\n";
    }

    /** Zusammenfassung für die Diagnoseseite. @return array<string,mixed> */
    public function summary(): array
    {
        $keytab = $this->keytabPath();

        return [
            'enabled' => $this->isEnabled(),
            'mode' => $this->mode(),
            'realm' => $this->realm(),
            'domain' => $this->domain(),
            'kdcs' => $this->kdcs(),
            'service_principal' => $this->servicePrincipal(),
            'keytab' => $keytab,
            'keytab_exists' => $keytab !== '' && is_file($keytab),
            'keytab_readable' => $keytab !== '' && is_readable($keytab),
            'krb5_conf' => $this->krb5ConfPath(),
            'krb5_conf_exists' => is_file($this->krb5ConfPath()),
            'apache_conf' => $this->apacheConfPath(),
            'apache_conf_exists' => is_file($this->apacheConfPath()),
            'module_loaded' => self::moduleLoaded(),
            'ssl_only' => $this->sslOnly(),
            'basic_fallback' => $this->basicFallback(),
            'entry_path' => $this->entryPath(),
            'problems' => $this->problems(),
            'warnings' => $this->warnings(),
        ];
    }

    /** Ist mod_auth_gssapi im laufenden Apache geladen? null = nicht feststellbar (CLI/FPM). */
    public static function moduleLoaded(): ?bool
    {
        if (!function_exists('apache_get_modules')) {
            return null;
        }

        return in_array('mod_auth_gssapi', apache_get_modules(), true);
    }

    /** „OU=Users,DC=example,DC=local“ → „example.local“. */
    public static function domainFromBaseDn(string $baseDn): string
    {
        if (preg_match_all('/DC=([^,]+)/i', $baseDn, $matches) < 1) {
            return '';
        }
        $parts = array_filter(array_map('trim', $matches[1]), static fn (string $p): bool => $p !== '');

        return strtolower(implode('.', $parts));
    }

    /** Rechnername aus „ldaps://dc01.example.local:636“, „http://host:8080“ oder „dc01“. */
    public static function hostname(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);

            return is_string($host) ? trim($host, '[]') : '';
        }
        $value = rtrim($value, '/');
        // IPv6-Adressen stehen in eckigen Klammern und enthalten selbst Doppelpunkte
        if (str_starts_with($value, '[')) {
            $end = strpos($value, ']');

            return $end === false ? $value : substr($value, 1, $end - 1);
        }
        $colon = strrpos($value, ':');
        if ($colon !== false && ctype_digit(substr($value, $colon + 1))) {
            $value = substr($value, 0, $colon);
        }

        return $value;
    }
}
