<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Core\Request;
use App\Repositories\EmployeeRepository;
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Services\Ad\AdUserLookupService;

/**
 * Erkennt den Melder einer Störungsmeldung ohne zusätzliche Eingabefelder.
 *
 * Reihenfolge der Benutzererkennung:
 *  1. Windows-Anmeldung am Webserver (REMOTE_USER/AUTH_USER, gesetzt von Kerberos/NTLM bzw. IIS)
 *  2. optionaler Proxy-Header (nur wenn ausdrücklich freigegeben, da fälschbar)
 *  3. bestehende Anmeldung in der Anwendung (Sitzung)
 *
 * Zum erkannten Anmeldenamen werden Mitarbeiter- und Benutzerdatensatz gesucht und
 * anschließend mit einem Live-Abgleich aus dem AD/LDAP angereichert (Telefon, E-Mail,
 * Abteilung, Position, Standort). Der Rechnername stammt aus der Rückwärtsauflösung
 * der IP-Adresse; ist keine Auflösung möglich, bleibt die IP-Adresse als Kennung.
 */
final class ReporterIdentityService
{
    /** Vom Webserver gesetzte Variablen mit dem angemeldeten Windows-Konto */
    private const SERVER_KEYS = ['REMOTE_USER', 'REDIRECT_REMOTE_USER', 'AUTH_USER', 'PHP_AUTH_USER'];

    public function __construct(
        private readonly Config $config,
        private readonly CurrentUser $currentUser,
        private readonly UserRepository $users,
        private readonly EmployeeRepository $employees,
        private readonly AdUserLookupService $directory
    ) {}

    /**
     * Melderdaten zur aktuellen Anfrage zusammenstellen.
     *
     * @return array{username:?string,detected_by:?string,display_name:?string,email:?string,phone:?string,
     *               department:?string,position:?string,location:?string,employee_id:?int,user_id:?int,
     *               directory_match:bool,host:?string,ip:string}
     */
    public function detect(Request $request): array
    {
        [$username, $detectedBy] = $this->username($request);

        $user = $username !== null ? $this->users->findByUsername($username) : null;
        if ($user === null && $this->currentUser->isAuthenticated()) {
            $user = $this->users->find((int) $this->currentUser->id());
        }

        $employee = $username !== null ? $this->employees->findByUsername($username) : null;
        if ($employee === null && $user !== null && !empty($user['employee_id'])) {
            $employee = $this->employees->find((int) $user['employee_id']);
        }
        if ($username === null && $user !== null) {
            $username = (string) $user['username'];
            $detectedBy = 'session';
        }

        $ad = $username !== null ? $this->directory->findByUsername($username) : null;
        if ($employee === null && $ad !== null) {
            // Ohne Treffer über den Anmeldenamen: Mitarbeiter über Personalnummer bzw. E-Mail aus dem AD suchen
            $employee = !empty($ad['personnel_number']) ? $this->employees->findByPersonnelNumber((string) $ad['personnel_number']) : null;
            if ($employee === null && !empty($ad['email'])) {
                $employee = $this->employees->findByEmail((string) $ad['email']);
            }
        }
        $ip = $request->ip();

        return [
            'username' => $username,
            'detected_by' => $detectedBy,
            // AD-Werte haben Vorrang (aktuellster Stand), danach Mitarbeiter- und Benutzerdatensatz
            'display_name' => $this->pick($ad['display_name'] ?? null, $employee['display_name'] ?? null, $user['display_name'] ?? null, $username),
            'email' => $this->pick($ad['email'] ?? null, $employee['email'] ?? null, $user['email'] ?? null),
            'phone' => $this->pick($ad['phone'] ?? null, $employee['phone'] ?? null),
            'department' => $this->pick($ad['department'] ?? null, $employee['department'] ?? null),
            'position' => $this->pick($ad['position'] ?? null, $employee['position'] ?? null),
            'location' => $this->pick($ad['ad_location'] ?? null, $employee['location_path'] ?? null, $employee['ad_location'] ?? null),
            'employee_id' => $employee !== null ? (int) $employee['id'] : null,
            'user_id' => $user !== null ? (int) $user['id'] : null,
            'directory_match' => $ad !== null,
            'host' => $this->hostname($ip),
            'ip' => $ip,
        ];
    }

    /**
     * Angemeldetes Konto ermitteln und auf den reinen Anmeldenamen normalisieren.
     * @return array{0:?string,1:?string} [Benutzername, Erkennungsquelle]
     */
    private function username(Request $request): array
    {
        foreach (self::SERVER_KEYS as $key) {
            $value = self::normalize($request->serverValue($key));
            if ($value !== null) {
                return [$value, 'sso'];
            }
        }
        if ((bool) $this->config->get('helpdesk.quick_report.trust_user_header', false)) {
            $header = (string) $this->config->get('helpdesk.quick_report.user_header', 'X-Remote-User');
            $value = self::normalize($request->header($header));
            if ($value !== null) {
                return [$value, 'header'];
            }
        }
        if ($this->currentUser->isAuthenticated()) {
            $value = self::normalize($this->currentUser->username());
            if ($value !== null) {
                return [$value, 'session'];
            }
        }

        return [null, null];
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

    /** Rechnername per Reverse-DNS; liefert null, wenn nur die IP zurückkommt. */
    private function hostname(string $ip): ?string
    {
        if ($ip === '' || !(bool) $this->config->get('helpdesk.quick_report.resolve_hostname', true)) {
            return null;
        }
        $host = @gethostbyaddr($ip);
        if (!is_string($host) || $host === '' || $host === $ip) {
            return null;
        }

        return mb_substr($host, 0, 255);
    }

    private function pick(?string ...$values): ?string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
