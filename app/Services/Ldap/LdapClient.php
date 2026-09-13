<?php

declare(strict_types=1);

namespace App\Services\Ldap;

use RuntimeException;

/** Produktiver LDAP-Client auf Basis von ext-ldap (LDAPS, seitenweise Suche). */
final class LdapClient implements LdapClientInterface
{
    /** @var \LDAP\Connection|resource|null */
    private mixed $connection = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 636,
        private readonly int $pageSize = 500
    ) {}

    public function bind(string $dn, string $password): bool
    {
        if ($dn === '' || $password === '') {
            return false;
        }
        $connection = $this->connect();

        return @ldap_bind($connection, $dn, $password);
    }

    public function search(string $baseDn, string $filter, array $attributes): array
    {
        $connection = $this->connect();
        $results = [];
        $cookie = '';

        do {
            $controls = [[
                'oid' => LDAP_CONTROL_PAGEDRESULTS,
                'iscritical' => true,
                'value' => ['size' => $this->pageSize, 'cookie' => $cookie],
            ]];
            $result = @ldap_search($connection, $baseDn, $filter, $attributes, 0, -1, -1, LDAP_DEREF_NEVER, $controls);
            if ($result === false) {
                throw new RuntimeException('LDAP-Suche fehlgeschlagen: ' . ldap_error($connection));
            }
            ldap_parse_result($connection, $result, $errcode, $matchedDn, $errmsg, $referrals, $serverControls);
            $entries = ldap_get_entries($connection, $result) ?: ['count' => 0];
            for ($i = 0; $i < $entries['count']; $i++) {
                $results[] = $this->normalizeEntry($entries[$i]);
            }
            $cookie = $serverControls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? '';
        } while ($cookie !== null && $cookie !== '');

        return $results;
    }

    public function close(): void
    {
        if ($this->connection !== null) {
            @ldap_unbind($this->connection);
            $this->connection = null;
        }
    }

    /** @return \LDAP\Connection|resource */
    private function connect(): mixed
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        if (!function_exists('ldap_connect')) {
            throw new RuntimeException('PHP-Erweiterung ldap ist nicht installiert.');
        }
        $scheme = $this->port === 636 ? 'ldaps' : 'ldap';
        $connection = ldap_connect("{$scheme}://{$this->host}:{$this->port}");
        if ($connection === false) {
            throw new RuntimeException('LDAP-Verbindung konnte nicht aufgebaut werden.');
        }
        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, 10);

        return $this->connection = $connection;
    }

    /**
     * @param array<mixed> $entry
     * @return array<string,mixed> Attributname (klein) → Wert (erster Wert als String, binäre GUID als Rohdaten)
     */
    private function normalizeEntry(array $entry): array
    {
        $normalized = ['dn' => $entry['dn'] ?? ''];
        for ($i = 0; $i < ($entry['count'] ?? 0); $i++) {
            $attribute = strtolower((string) $entry[$i]);
            $values = $entry[$attribute] ?? [];
            unset($values['count']);
            $normalized[$attribute] = count($values) === 1 ? (string) reset($values) : array_values($values);
        }

        return $normalized;
    }
}
