<?php

declare(strict_types=1);

namespace App\Repositories;

/** Regeln der Regelengine und Protokoll versendeter Benachrichtigungen. */
final class TicketRuleRepository extends BaseRepository
{
    public const TRIGGERS = ['created', 'updated', 'status_changed', 'comment_added', 'sla_warning', 'sla_breached'];

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->fetchAll('SELECT * FROM ticket_rules ORDER BY trigger_event, sort_order, name');
    }

    /** Aktive Regeln eines Auslösers in Ausführungsreihenfolge. @return array<int,array<string,mixed>> */
    public function activeFor(string $trigger): array
    {
        return $this->fetchAll('SELECT * FROM ticket_rules WHERE is_active = 1 AND trigger_event = :t ORDER BY sort_order, id', ['t' => $trigger]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_rules WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_rules WHERE name = :n', ['n' => $name]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('ticket_rules', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('ticket_rules', $id, $data);
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM ticket_rules WHERE id = :id', ['id' => $id]);
    }

    // ------------------------------------------------------------------ Benachrichtigungsprotokoll

    /** Prüft, ob ein Ereignis an einen Empfänger bereits (erfolgreich oder bewusst übersprungen) verarbeitet wurde. */
    public function notificationExists(int $ticketId, string $eventKey, string $recipient): bool
    {
        return $this->fetchValue('SELECT 1 FROM ticket_notifications WHERE ticket_id = :t AND event_key = :e AND recipient = :r', ['t' => $ticketId, 'e' => $eventKey, 'r' => $recipient]) !== null;
    }

    public function logNotification(int $ticketId, string $eventKey, string $recipient, string $subject, string $status, ?string $error = null): void
    {
        $this->execute(
            'INSERT INTO ticket_notifications (ticket_id, event_key, recipient, subject, status, error) VALUES (:t, :e, :r, :s, :st, :err)
             ON DUPLICATE KEY UPDATE status = VALUES(status), error = VALUES(error), subject = VALUES(subject)',
            ['t' => $ticketId, 'e' => mb_substr($eventKey, 0, 120), 'r' => mb_substr($recipient, 0, 255), 's' => mb_substr($subject, 0, 255), 'st' => $status, 'err' => $error !== null ? mb_substr($error, 0, 500) : null]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function notificationsForTicket(int $ticketId): array
    {
        return $this->fetchAll('SELECT * FROM ticket_notifications WHERE ticket_id = :t ORDER BY created_at DESC, id DESC', ['t' => $ticketId]);
    }
}
