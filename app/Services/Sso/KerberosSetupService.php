<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Core\Config;
use App\Core\Logger;
use App\Services\Ldap\LdapClientInterface;
use App\Support\Kerberos;
use RuntimeException;

/**
 * Richtet Kerberos im Container ein, damit Apache (mod_auth_gssapi) den am Client
 * angemeldeten Windows-Benutzer zuverlässig ermitteln kann.
 *
 * Ablauf beim Containerstart (bin/kerberos-setup.php, aufgerufen aus dem Entrypoint):
 *  1. Realm, KDC und Dienstprinzipal HTTP/<Servername>@REALM bestimmen
 *  2. Dienstkonto im AD nachschlagen – **dasselbe Konto wie für den AD-Sync** (AD_BIND_DN) –
 *     und dessen Schlüsselversion (msDS-KeyVersionNumber) lesen
 *  3. krb5.conf schreiben und aus dem Kontopasswort ein Keytab erzeugen (ktutil, Salt = REALM + sAMAccountName)
 *  4. Keytab gegen den KDC prüfen (kinit -k) und die Apache-Konfiguration schreiben
 *
 * Das Passwort wird ausschließlich über die Standardeingabe an `ktutil` übergeben und
 * taucht weder in Prozesslisten noch im Log auf.
 */
final class KerberosSetupService
{
    /** LDAP-Attribute des Dienstkontos */
    private const ACCOUNT_ATTRIBUTES = ['sAMAccountName', 'msDS-KeyVersionNumber', 'servicePrincipalName', 'userPrincipalName'];

    public function __construct(
        private readonly Config $config,
        private readonly LdapClientInterface $ldap,
        private readonly Logger $logger
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('kerberos.enabled', false);
    }

    /**
     * Einrichtung ausführen.
     *
     * @return array{status:string,message:string,principal:?string,kvno:?int,keytab:?string}
     */
    public function run(): array
    {
        if (!$this->isEnabled()) {
            $this->removeApacheConf();

            return $this->result('disabled', 'Windows-SSO ist deaktiviert (KERBEROS_ENABLED=false).');
        }

        try {
            $realm = Kerberos::realm((string) $this->config->get('kerberos.realm', ''))
                ?? throw new RuntimeException('Kerberos-Realm fehlt oder ist ungültig (KERBEROS_REALM bzw. AD_BASE_DN prüfen).');
            $host = Kerberos::host((string) $this->config->get('kerberos.service_host', ''))
                ?? throw new RuntimeException('Servername für den SPN fehlt oder ist ungültig (KERBEROS_SERVICE_HOST bzw. APP_URL prüfen).');
            $principal = Kerberos::servicePrincipal($host, $realm)
                ?? throw new RuntimeException('Dienstprinzipal konnte nicht gebildet werden.');
            $account = Kerberos::accountName((string) $this->config->get('kerberos.account.dn', ''))
                ?? throw new RuntimeException('Dienstkonto fehlt oder ist ungültig (AD_BIND_DN prüfen).');
            $password = (string) $this->config->get('kerberos.account.password', '');
            if ($password === '') {
                throw new RuntimeException('Passwort des Dienstkontos fehlt (AD_BIND_PASSWORD).');
            }

            $this->writeFile((string) $this->config->get('kerberos.krb5_conf', '/etc/krb5.conf'), $this->renderKrb5Conf($realm, $this->kdcs($realm)), 0644);

            $kvno = $this->keyVersion($account);
            $keytab = (string) $this->config->get('kerberos.keytab', '/etc/apache2/krb5.keytab');
            $this->writeKeytab($keytab, $principal, $kvno, $password, (string) Kerberos::userSalt($realm, $account));
            $this->verifyKeytab($keytab, $principal);
            $this->writeFile((string) $this->config->get('kerberos.apache_conf', '/etc/apache2/conf-enabled/kerberos.conf'), $this->renderApacheConf($principal, $keytab), 0644);
        } catch (\Throwable $e) {
            $this->removeApacheConf();
            $this->logger->warning('Kerberos-Einrichtung fehlgeschlagen', ['error' => $e->getMessage()]);

            return $this->result('error', $e->getMessage());
        } finally {
            $this->ldap->close();
        }

        $this->logger->info('Kerberos eingerichtet', ['principal' => $principal, 'kvno' => $kvno]);

        return [
            'status' => 'success',
            'message' => sprintf('Keytab für %s (kvno %d) erzeugt, Apache nutzt Kerberos.', $principal, $kvno),
            'principal' => $principal,
            'kvno' => $kvno,
            'keytab' => $keytab,
        ];
    }

    /** Inhalt der krb5.conf (reine Zeichenkette – ohne Seiteneffekte, dadurch testbar). @param list<string> $kdcs */
    public function renderKrb5Conf(string $realm, array $kdcs): string
    {
        $enctypes = implode(' ', $this->enctypes());
        $lines = [
            '# Automatisch erzeugt von bin/kerberos-setup.php – Änderungen gehen beim Containerstart verloren.',
            '[libdefaults]',
            '    default_realm = ' . $realm,
            '    dns_lookup_realm = false',
            '    dns_lookup_kdc = ' . ($kdcs === [] ? 'true' : 'false'),
            '    rdns = false',
            '    forwardable = true',
            '    default_tgs_enctypes = ' . $enctypes,
            '    default_tkt_enctypes = ' . $enctypes,
            '    permitted_enctypes = ' . $enctypes,
            '',
            '[realms]',
            '    ' . $realm . ' = {',
        ];
        foreach ($kdcs as $kdc) {
            $lines[] = '        kdc = ' . $kdc;
        }
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '[domain_realm]';
        $lines[] = '    .' . strtolower($realm) . ' = ' . $realm;
        $lines[] = '    ' . strtolower($realm) . ' = ' . $realm;
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Apache-Konfiguration für mod_auth_gssapi.
     *
     * Modus „probe“ (Standard): Nur /sso/pruefung verlangt ein Kerberos-Ticket, alle anderen
     * Seiten bleiben ohne Windows-Anmeldung erreichbar. Modus „required“ schützt die gesamte
     * Anwendung; /health bleibt für Healthchecks frei.
     */
    public function renderApacheConf(string $principal, string $keytab): string
    {
        $sslOnly = (bool) $this->config->get('kerberos.ssl_only', false) ? 'On' : 'Off';
        $basic = (bool) $this->config->get('kerberos.basic_fallback', false);
        $body = [
            '        AuthType GSSAPI',
            '        AuthName "Windows-Anmeldung"',
            '        GssapiCredStore keytab:' . $keytab,
            '        GssapiAcceptorName ' . strtok($principal, '@'),
            '        GssapiAllowedMech krb5',
            '        # Liefert REMOTE_USER ohne @REALM, also den reinen Anmeldenamen',
            '        GssapiLocalName On',
            '        GssapiSSLonly ' . $sslOnly,
            '        GssapiBasicAuth ' . ($basic ? 'On' : 'Off'),
            '        Require valid-user',
        ];

        $lines = [
            '# Automatisch erzeugt von bin/kerberos-setup.php – Änderungen gehen beim Containerstart verloren.',
            '<IfModule mod_auth_gssapi.c>',
        ];
        if ((string) $this->config->get('kerberos.mode', 'probe') === 'required') {
            $lines[] = '    <Location />';
            $lines = array_merge($lines, $body);
            $lines[] = '    </Location>';
            $lines[] = '    <LocationMatch "^/health$">';
            $lines[] = '        Require all granted';
            $lines[] = '    </LocationMatch>';
        } else {
            // Exakt eine URL wird ausgehandelt; der Abbruchpfad bleibt frei, damit bei
            // fehlgeschlagener Aushandlung eine verständliche Seite erscheint.
            $lines[] = '    <LocationMatch "^/sso/pruefung$">';
            $lines = array_merge($lines, $body);
            $lines[] = '        ErrorDocument 401 /sso/abbruch';
            $lines[] = '    </LocationMatch>';
        }
        $lines[] = '</IfModule>';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** KDC-Liste; leer = Auflösung über DNS-SRV-Einträge der Domäne. @return list<string> */
    private function kdcs(string $realm): array
    {
        $configured = $this->config->get('kerberos.kdc', []);
        $kdcs = [];
        foreach (is_array($configured) ? $configured : [] as $entry) {
            $host = Kerberos::host((string) $entry);
            if ($host !== null && !in_array($host, $kdcs, true)) {
                $kdcs[] = $host;
            }
        }
        if ($kdcs === []) {
            $this->logger->info('Kein KDC konfiguriert – Kerberos nutzt die DNS-SRV-Einträge', ['realm' => $realm]);
        }

        return $kdcs;
    }

    /** @return list<string> */
    private function enctypes(): array
    {
        $configured = $this->config->get('kerberos.enctypes', []);
        $valid = [];
        foreach (is_array($configured) ? $configured : [] as $enctype) {
            $enctype = strtolower(trim((string) $enctype));
            if (preg_match('/^[a-z0-9-]{3,40}$/', $enctype) === 1) {
                $valid[] = $enctype;
            }
        }

        return $valid !== [] ? $valid : ['aes256-cts-hmac-sha1-96', 'aes128-cts-hmac-sha1-96'];
    }

    /**
     * Schlüsselversion des Dienstkontos: fest konfiguriert (KERBEROS_KVNO) oder aus dem AD
     * gelesen. Sie muss stimmen, sonst weist der Server die Tickets der Clients zurück.
     */
    private function keyVersion(string $account): int
    {
        $configured = (int) $this->config->get('kerberos.kvno', 0);
        if ($configured > 0) {
            return $configured;
        }

        $dn = (string) $this->config->get('kerberos.account.dn', '');
        $password = (string) $this->config->get('kerberos.account.password', '');
        if (!$this->ldap->bind($dn, $password)) {
            throw new RuntimeException('LDAP-Bind mit dem Dienstkonto fehlgeschlagen – AD_BIND_DN/AD_BIND_PASSWORD prüfen.');
        }
        foreach ($this->ldap->search($dn, '(objectClass=*)', self::ACCOUNT_ATTRIBUTES) as $entry) {
            if (strcasecmp((string) ($entry['samaccountname'] ?? ''), $account) !== 0) {
                continue;
            }
            $kvno = (int) ($entry['msds-keyversionnumber'] ?? 0);
            if ($kvno > 0) {
                return $kvno;
            }
        }

        throw new RuntimeException('Schlüsselversion (msDS-KeyVersionNumber) des Dienstkontos nicht lesbar – KERBEROS_KVNO setzen.');
    }

    /** Keytab aus dem Kontopasswort erzeugen (Passwort nur über die Standardeingabe). */
    private function writeKeytab(string $path, string $principal, int $kvno, string $password, string $salt): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Verzeichnis {$directory} konnte nicht angelegt werden.");
        }
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException("Vorhandenes Keytab {$path} konnte nicht entfernt werden.");
        }

        [$code, $output] = $this->runProcess(['ktutil'], $this->ktutilScript($path, $principal, $kvno, $password, $salt));
        if ($code !== 0 || !is_file($path)) {
            throw new RuntimeException('Keytab konnte nicht erzeugt werden: ' . $this->redact($output, $password));
        }
        @chmod($path, 0640);
        $user = function_exists('posix_getpwnam') ? @posix_getpwnam('www-data') : false;
        if (is_array($user)) {
            @chgrp($path, (int) $user['gid']);
        }
    }

    /**
     * Eingabe für `ktutil`: je Verschlüsselungsverfahren ein Eintrag auf den Dienstprinzipal,
     * abgeleitet aus dem Passwort des AD-Dienstkontos mit dessen Salt (REALM + sAMAccountName).
     */
    private function ktutilScript(string $path, string $principal, int $kvno, string $password, string $salt): string
    {
        $script = '';
        foreach ($this->enctypes() as $enctype) {
            $script .= sprintf("addent -password -p %s -k %d -e %s -s %s\n%s\n", $principal, $kvno, $enctype, $salt, $password);
        }

        return $script . 'wkt ' . $path . "\nquit\n";
    }

    /** Keytab gegen den KDC prüfen: gelingt kinit, passen Schlüssel, Salt und Schlüsselversion. */
    private function verifyKeytab(string $keytab, string $principal): void
    {
        $cache = 'FILE:' . sys_get_temp_dir() . '/krb5cc_setup_' . getmypid();
        [$code, $output] = $this->runProcess(['kinit', '-k', '-t', $keytab, '-c', $cache, $principal], '');
        $this->runProcess(['kdestroy', '-c', $cache], '');
        if ($code !== 0) {
            throw new RuntimeException('Keytab wurde vom Domänencontroller abgelehnt (SPN, Passwort oder Schlüsselversion prüfen): ' . trim($output));
        }
    }

    /**
     * Externes Programm ausführen; die Eingabe geht über stdin, damit keine Geheimnisse in
     * der Prozessliste erscheinen.
     *
     * @param list<string> $command
     * @return array{0:int,1:string}
     */
    private function runProcess(array $command, string $input): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Programm ' . $command[0] . ' konnte nicht gestartet werden (Paket krb5-user installiert?).');
        }
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function writeFile(string $path, string $content, int $mode): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Verzeichnis {$directory} konnte nicht angelegt werden.");
        }
        if (@file_put_contents($path, $content) === false) {
            throw new RuntimeException("Datei {$path} konnte nicht geschrieben werden.");
        }
        @chmod($path, $mode);
    }

    /** Ohne gültige Einrichtung darf Apache keine Kerberos-Konfiguration laden. */
    private function removeApacheConf(): void
    {
        $path = (string) $this->config->get('kerberos.apache_conf', '');
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    private function redact(string $output, string $password): string
    {
        return trim($password === '' ? $output : str_replace($password, '***', $output));
    }

    /** @return array{status:string,message:string,principal:?string,kvno:?int,keytab:?string} */
    private function result(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message, 'principal' => null, 'kvno' => null, 'keytab' => null];
    }
}
