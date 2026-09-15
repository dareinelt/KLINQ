<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Security\WindowsIdentity;
use App\Services\Ldap\LdapClientInterface;
use App\Services\Sso\KerberosSetupService;
use App\Support\Kerberos;
use Tests\Support\TestCase;

/**
 * Windows-SSO per Kerberos: Ableitung von Realm, Servername, Dienstkonto und Salt,
 * Erkennung des angemeldeten Benutzers aus der Serverumgebung sowie der Inhalt der
 * erzeugten krb5.conf- und Apache-Konfiguration.
 */
final class WindowsSsoTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testRealmAndAccountAreDerivedFromTheAdServiceAccount(): void
    {
        $this->assertSame('FIRMA.LOCAL', Kerberos::realmFromDn('OU=Users,DC=firma,DC=local'));
        $this->assertSame('FIRMA.LOCAL', Kerberos::realmFromDn('', 'CN=svc-assets,OU=Service,DC=firma,DC=local'));
        $this->assertNull(Kerberos::realmFromDn('OU=Users'));

        $this->assertSame('svc-assets', Kerberos::accountName('CN=svc-assets,OU=Service,DC=firma,DC=local'));
        $this->assertSame('svc-assets', Kerberos::accountName('FIRMA\\svc-assets'));
        $this->assertSame('svc-assets', Kerberos::accountName('svc-assets@firma.local'));
        $this->assertNull(Kerberos::accountName('CN=svc assets,OU=Service'));
        $this->assertNull(Kerberos::accountName(''));
    }

    public function testServicePrincipalAndSaltMatchTheActiveDirectoryRules(): void
    {
        $this->assertSame('assets.firma.local', Kerberos::host('https://assets.firma.local:8443/pfad'));
        $this->assertSame('dc01.firma.local', Kerberos::host('ldaps://dc01.firma.local:636'));
        $this->assertNull(Kerberos::host('assets firma local'));

        $this->assertSame('HTTP/assets.firma.local@FIRMA.LOCAL', Kerberos::servicePrincipal('ASSETS.firma.local', 'firma.local'));
        // Salt eines AD-Benutzerkontos: Realm + sAMAccountName (nicht der Dienstprinzipal)
        $this->assertSame('FIRMA.LOCALsvc-assets', Kerberos::userSalt('FIRMA.LOCAL', 'CN=svc-assets,OU=Service,DC=firma,DC=local'));
        $this->assertSame(['dc01.firma.local', 'dc02.firma.local'], Kerberos::hostList('dc01.firma.local, dc02.firma.local , dc01.firma.local'));
    }

    public function testRemoteUserIsNormalizedAndRejectedWhenSuspicious(): void
    {
        $this->assertSame('mmuster', WindowsIdentity::normalize('FIRMA\\mmuster'));
        $this->assertSame('mmuster', WindowsIdentity::normalize('mmuster@firma.local'));
        $this->assertNull(WindowsIdentity::normalize('mmuster)(objectClass=*'));

        $identity = new WindowsIdentity($this->config());
        $this->assertSame('mmuster', $identity->fromServer($this->request('/stoerung', ['REMOTE_USER' => 'FIRMA\\mmuster'])));
        $this->assertSame('mmuster', $identity->fromServer($this->request('/stoerung', ['REDIRECT_REMOTE_USER' => 'mmuster@firma.local'])));
        $this->assertNull($identity->fromServer($this->request('/stoerung')));
    }

    public function testDetectedUserIsRememberedForTheSessionAndExpires(): void
    {
        $identity = new WindowsIdentity($this->config(['kerberos.session_ttl_minutes' => 60]));
        $identity->remember('mmuster');
        $this->assertSame('mmuster', $identity->detect($this->request('/stoerung')));

        $_SESSION['_windows_user_at'] = time() - 7200;
        $this->assertNull($identity->detect($this->request('/stoerung')), 'Abgelaufene Erkennung darf nicht weiterverwendet werden');
    }

    public function testProbeRedirectHappensOncePerSessionAndOnlyForSafeTargets(): void
    {
        $identity = new WindowsIdentity($this->config());
        $this->assertSame('/sso/pruefung?next=%2Fstoerung', $identity->probeRedirect($this->request('/stoerung')));

        // Nach der Aushandlung (erfolgreich oder nicht) wird nicht erneut umgeleitet
        $identity->remember(null);
        $this->assertNull($identity->probeRedirect($this->request('/stoerung')));

        $_SESSION = [];
        $this->assertNull($identity->probeRedirect($this->request('/stoerung', ['REMOTE_USER' => 'mmuster'])), 'Benutzer bereits bekannt');
        $this->assertNull($identity->probeRedirect($this->request('/stoerung', [], 'POST')), 'Formularabsendungen dürfen nicht umgeleitet werden');
        $this->assertNull((new WindowsIdentity($this->config(['kerberos.enabled' => false])))->probeRedirect($this->request('/stoerung')));
        $this->assertNull((new WindowsIdentity($this->config(['kerberos.mode' => 'required'])))->probeRedirect($this->request('/stoerung')));

        $this->assertSame('/stoerung', WindowsIdentity::safeNext('/stoerung'));
        $this->assertSame('/', WindowsIdentity::safeNext('https://fremd.example/phishing'));
        $this->assertSame('/', WindowsIdentity::safeNext(null));
    }

    public function testGeneratedKrb5ConfigurationUsesRealmAndKdc(): void
    {
        $krb5 = $this->service()->renderKrb5Conf('FIRMA.LOCAL', ['dc01.firma.local']);

        $this->assertStringContains('default_realm = FIRMA.LOCAL', $krb5);
        $this->assertStringContains('kdc = dc01.firma.local', $krb5);
        $this->assertStringContains('dns_lookup_kdc = false', $krb5);
        $this->assertStringContains('.firma.local = FIRMA.LOCAL', $krb5);
        // Ohne KDC-Angabe wird über die DNS-SRV-Einträge der Domäne gesucht
        $this->assertStringContains('dns_lookup_kdc = true', $this->service()->renderKrb5Conf('FIRMA.LOCAL', []));
    }

    public function testApacheConfigurationProtectsOnlyTheProbeUrlByDefault(): void
    {
        $conf = $this->service()->renderApacheConf('HTTP/assets.firma.local@FIRMA.LOCAL', '/etc/apache2/krb5.keytab');

        $this->assertStringContains('<LocationMatch "^/sso/pruefung$">', $conf);
        $this->assertStringContains('AuthType GSSAPI', $conf);
        $this->assertStringContains('GssapiCredStore keytab:/etc/apache2/krb5.keytab', $conf);
        $this->assertStringContains('GssapiAcceptorName HTTP/assets.firma.local', $conf);
        $this->assertStringContains('GssapiLocalName On', $conf);
        $this->assertStringContains('ErrorDocument 401 /sso/abbruch', $conf);
        $this->assertFalse(str_contains($conf, '<Location />'), 'Im Modus probe darf nicht die gesamte Anwendung geschützt sein');

        $required = $this->service(['kerberos.mode' => 'required'])->renderApacheConf('HTTP/assets.firma.local@FIRMA.LOCAL', '/etc/apache2/krb5.keytab');
        $this->assertStringContains('<Location />', $required);
        $this->assertStringContains('<LocationMatch "^/health$">', $required);
    }

    public function testSetupIsSkippedWhenWindowsSsoIsDisabled(): void
    {
        $result = $this->service(['kerberos.enabled' => false])->run();

        $this->assertSame('disabled', $result['status']);
    }

    /** @param array<string,mixed> $overrides */
    private function config(array $overrides = []): Config
    {
        $config = Config::fromArray([
            'kerberos' => [
                'enabled' => true,
                'mode' => 'probe',
                'realm' => 'FIRMA.LOCAL',
                'kdc' => ['dc01.firma.local'],
                'service_host' => 'assets.firma.local',
                'account' => ['dn' => 'CN=svc-assets,OU=Service,DC=firma,DC=local', 'name' => 'svc-assets', 'password' => 'geheim'],
                'keytab' => '/etc/apache2/krb5.keytab',
                'krb5_conf' => '/etc/krb5.conf',
                'apache_conf' => '/etc/apache2/conf-enabled/kerberos.conf',
                'enctypes' => ['aes256-cts-hmac-sha1-96', 'aes128-cts-hmac-sha1-96'],
                'ssl_only' => false,
                'basic_fallback' => false,
                'kvno' => 0,
                'session_ttl_minutes' => 60,
            ],
        ]);

        return $overrides === [] ? $config : $config->with($overrides);
    }

    /** @param array<string,mixed> $overrides */
    private function service(array $overrides = []): KerberosSetupService
    {
        $ldap = new class implements LdapClientInterface {
            public function bind(string $dn, string $password): bool { return false; }
            public function search(string $baseDn, string $filter, array $attributes): array { return []; }
            public function close(): void {}
        };

        return new KerberosSetupService($this->config($overrides), $ldap, new Logger('/dev/null', 'error'));
    }

    /** @param array<string,mixed> $server */
    private function request(string $path, array $server = [], string $method = 'GET'): Request
    {
        return new Request(array_merge(['REQUEST_URI' => $path, 'REQUEST_METHOD' => $method, 'REMOTE_ADDR' => '10.0.0.5'], $server), [], [], []);
    }
}
