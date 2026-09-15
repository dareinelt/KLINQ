<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config;
use App\Core\Request;
use App\Support\Url;

/**
 * Ermittelt den am Client angemeldeten Windows-Benutzer.
 *
 * Quelle ist ausschließlich der Webserver: Apache handelt im Container per Kerberos
 * (mod_auth_gssapi, SPNEGO) mit dem Browser aus und stellt den Anmeldenamen in
 * REMOTE_USER bereit. Diese Angabe ist – anders als ein HTTP-Header – nicht fälschbar,
 * weil der Client dafür ein gültiges, vom Domänencontroller ausgestelltes Ticket
 * vorlegen muss, das der Server mit dem Schlüssel aus seinem Keytab prüft.
 *
 * Im Modus „probe“ ist nur die Route /sso/pruefung Kerberos-geschützt. Seiten, die den
 * Benutzer benötigen, schicken den Besucher einmalig dorthin; das Ergebnis wird für die
 * Dauer der Sitzung gemerkt (KERBEROS_SESSION_TTL_MINUTES), damit weder wiederholte
 * Aushandlungen noch Weiterleitungsschleifen entstehen.
 */
final class WindowsIdentity
{
    /** Vom Webserver gesetzte Variablen mit dem angemeldeten Windows-Konto */
    public const SERVER_KEYS = ['REMOTE_USER', 'REDIRECT_REMOTE_USER', 'AUTH_USER', 'PHP_AUTH_USER'];

    public const PROBE_PATH = '/sso/pruefung';
    public const CANCEL_PATH = '/sso/abbruch';

    private const SESSION_USER = '_windows_user';
    private const SESSION_TIME = '_windows_user_at';
    private const SESSION_PROBED = '_windows_probed';

    public function __construct(private readonly Config $config) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('kerberos.enabled', false);
    }

    /** Anmeldename aus der Serverumgebung der aktuellen Anfrage (Kerberos/NTLM/IIS). */
    public function fromServer(Request $request): ?string
    {
        foreach (self::SERVER_KEYS as $key) {
            $value = self::normalize($request->serverValue($key));
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** Anmeldename der aktuellen Anfrage, ersatzweise das gemerkte Ergebnis der SSO-Prüfung. */
    public function detect(Request $request): ?string
    {
        return $this->fromServer($request) ?? $this->remembered();
    }

    /** Gemerktes Ergebnis der letzten Kerberos-Aushandlung, sofern noch gültig. */
    public function remembered(): ?string
    {
        $username = self::normalize(isset($_SESSION[self::SESSION_USER]) ? (string) $_SESSION[self::SESSION_USER] : null);
        if ($username === null) {
            return null;
        }
        $ttl = (int) $this->config->get('kerberos.session_ttl_minutes', 60) * 60;
        $at = (int) ($_SESSION[self::SESSION_TIME] ?? 0);
        if ($ttl > 0 && $at > 0 && (time() - $at) <= $ttl) {
            return $username;
        }
        $this->forget();

        return null;
    }

    /** Ergebnis der SSO-Prüfung merken (auch ein Misserfolg, um Schleifen zu vermeiden). */
    public function remember(?string $username): void
    {
        $_SESSION[self::SESSION_PROBED] = time();
        if ($username === null) {
            unset($_SESSION[self::SESSION_USER], $_SESSION[self::SESSION_TIME]);

            return;
        }
        $_SESSION[self::SESSION_USER] = $username;
        $_SESSION[self::SESSION_TIME] = time();
    }

    public function forget(): void
    {
        unset($_SESSION[self::SESSION_USER], $_SESSION[self::SESSION_TIME]);
    }

    /** Wurde in dieser Sitzung bereits eine Kerberos-Aushandlung versucht? */
    public function wasProbed(): bool
    {
        return isset($_SESSION[self::SESSION_PROBED]);
    }

    /**
     * Ziel-URL für die einmalige Kerberos-Aushandlung oder null, wenn sie nicht nötig
     * bzw. nicht möglich ist (SSO aus, Benutzer bereits bekannt, bereits versucht,
     * kein GET, gesamte Anwendung ohnehin geschützt).
     */
    public function probeRedirect(Request $request): ?string
    {
        if (!$this->isEnabled()
            || (string) $this->config->get('kerberos.mode', 'probe') !== 'probe'
            || $request->method() !== 'GET'
            || $this->wasProbed()
            || $this->detect($request) !== null) {
            return null;
        }
        $path = $request->path();
        if ($path === self::PROBE_PATH || $path === self::CANCEL_PATH) {
            return null;
        }

        return self::PROBE_PATH . '?next=' . rawurlencode(self::safeNext($path));
    }

    /** „DOMAIN\\benutzer“, „benutzer@domain.tld“ und „benutzer“ auf den Anmeldenamen reduzieren. */
    public static function normalize(?string $raw): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        $backslash = strrpos($value, '\\');
        if ($backslash !== false) {
            $value = substr($value, $backslash + 1);
        }
        $at = strpos($value, '@');
        if ($at !== false) {
            $value = substr($value, 0, $at);
        }
        $value = trim($value);

        return $value !== '' && preg_match('/^[a-zA-Z0-9._\-]{1,100}$/', $value) === 1 ? $value : null;
    }

    /** Nur anwendungsinterne Pfade als Rücksprungziel zulassen (keine offene Weiterleitung). */
    public static function safeNext(?string $next, string $fallback = '/'): string
    {
        return Url::safeLocalPath($next, $fallback);
    }
}
