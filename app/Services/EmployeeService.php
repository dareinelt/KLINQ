<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\EmployeeRepository;
use App\Support\Validator;

/** Manuelle Pflege von Mitarbeitern (AD-Mitarbeiter: nur lokale Zuordnungen änderbar). */
final class EmployeeService
{
    /** Felder, die bei AD-Mitarbeitern lokal gepflegt werden dürfen. */
    private const LOCAL_FIELDS = ['location_id', 'cost_center_id'];

    public function __construct(
        private readonly EmployeeRepository $employees,
        private readonly AuditLogService $audit
    ) {}

    /** @param array<string,mixed> $input */
    public function create(array $input): int
    {
        $data = $this->validate($input, null);
        $data['source'] = 'manual';
        $id = $this->employees->create($data);
        $this->audit->log('create', 'employee', $id, $data['display_name'], null, $data);

        return $id;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $existing, array $input): void
    {
        $data = $this->validate($input, $id);
        if ($existing['source'] === 'ad') {
            $data = array_intersect_key($data, array_flip(self::LOCAL_FIELDS));
        }
        $this->employees->update($id, $data);
        $this->audit->log('update', 'employee', $id, (string) $existing['display_name'], $existing, $data);
    }

    /** @param array<string,mixed> $existing */
    public function setActive(int $id, array $existing, bool $active): void
    {
        $this->employees->update($id, ['is_active' => $active ? 1 : 0, 'deactivated_at' => $active ? null : gmdate('Y-m-d H:i:s')]);
        $this->audit->log($active ? 'activate' : 'deactivate', 'employee', $id, (string) $existing['display_name'], ['is_active' => $existing['is_active']], ['is_active' => $active ? 1 : 0]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validate(array $input, ?int $excludeId): array
    {
        $v = (new Validator($input))
            ->string('first_name', 'Vorname', false, 120)
            ->string('last_name', 'Nachname', true, 120)
            ->string('display_name', 'Anzeigename', false, 250)
            ->pattern('username', 'Benutzername', '/^[a-zA-Z0-9._\-]{1,120}$/', 'Der Benutzername darf nur Buchstaben, Ziffern, Punkt, Bindestrich und Unterstrich enthalten.')
            ->email('email', 'E-Mail')
            ->string('personnel_number', 'Personalnummer', false, 50)
            ->string('department', 'Abteilung', false, 150)
            ->string('position', 'Position', false, 150)
            ->string('phone', 'Telefon', false, 60)
            ->id('location_id', 'Standort')
            ->id('cost_center_id', 'Kostenstelle')
            ->bool('is_active');
        $data = $v->validated();
        $data['first_name'] ??= '';
        $data['display_name'] = $data['display_name'] ?: trim($data['first_name'] . ' ' . $data['last_name']);

        if ($data['username'] !== null) {
            $other = $this->employees->findByUsername($data['username']);
            if ($other !== null && (int) $other['id'] !== $excludeId) {
                throw ValidationException::single('username', 'Dieser Benutzername ist bereits einem Mitarbeiter zugeordnet.');
            }
        }
        if ($data['personnel_number'] !== null) {
            $other = $this->employees->findByPersonnelNumber($data['personnel_number']);
            if ($other !== null && (int) $other['id'] !== $excludeId) {
                throw ValidationException::single('personnel_number', 'Diese Personalnummer ist bereits vergeben.');
            }
        }

        return $data;
    }
}
