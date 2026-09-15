<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Exceptions\ValidationException;
use App\Repositories\EmployeeRepository;
use App\Repositories\TicketRepository;
use App\Repositories\UserRepository;

/**
 * Vorbereitung für E-Mail-Eingang (Ticket aus E-Mail, Antwort per E-Mail als Kommentar).
 *
 * Der Mail-Container ist derzeit ausschließlich sendend; es gibt keinen IMAP-/Webhook-Abruf.
 * Diese Klasse definiert die Schnittstelle und die fachliche Verarbeitung einer bereits
 * empfangenen Nachricht (Betreff, Absender, Text), damit ein späterer Abrufmechanismus
 * (z. B. IMAP-Poller im Scheduler oder HTTP-Webhook) nur noch anbinden muss.
 */
final class TicketMailIngestionService
{
    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly EmployeeRepository $employees,
        private readonly UserRepository $users,
        private readonly TicketService $ticketService
    ) {}

    /**
     * Verarbeitet eine eingehende Nachricht.
     * Enthält der Betreff eine bekannte Ticketnummer, wird der Text als öffentlicher Kommentar angefügt,
     * sonst wird ein neues Ticket angelegt (Melder anhand der Absenderadresse).
     * Der Aufrufer muss zuvor einen Systembenutzer bzw. den zugeordneten Benutzer anmelden (CurrentUser::login).
     *
     * @param array{from:string,subject:string,body:string} $message
     * @return array{action:string,ticket_id:int}
     */
    public function ingest(array $message): array
    {
        $subject = trim((string) ($message['subject'] ?? ''));
        $body = trim((string) ($message['body'] ?? ''));
        $from = mb_strtolower(trim((string) ($message['from'] ?? '')));
        if ($body === '' && $subject === '') {
            throw ValidationException::single('body', 'Leere Nachricht.');
        }

        $number = self::extractTicketNumber($subject);
        if ($number !== null) {
            $ticket = $this->tickets->findByNumber($number);
            if ($ticket !== null) {
                $comment = $this->ticketService->addComment((int) $ticket['id'], $body !== '' ? $body : $subject, 'public', 'email');

                return ['action' => 'comment', 'ticket_id' => (int) $ticket['id'], 'comment_id' => (int) ($comment['id'] ?? 0)];
            }
        }

        $input = ['subject' => $subject !== '' ? mb_substr($subject, 0, 255) : 'E-Mail ohne Betreff', 'description' => $body !== '' ? $body : $subject];
        $employee = $from !== '' ? $this->employeeByEmail($from) : null;
        if ($employee !== null) {
            $input['requester_employee_id'] = (int) $employee['id'];
        }
        $ticket = $this->ticketService->create($input, 'email');

        return ['action' => 'created', 'ticket_id' => (int) $ticket['id']];
    }

    /** Ticketnummer aus Betreff ermitteln (z. B. „Re: [HD-2026-000012] Drucker“). */
    public static function extractTicketNumber(string $subject): ?string
    {
        if (preg_match('/\b([A-Z0-9]{1,10}-\d{4}-\d{6,})\b/i', $subject, $m) === 1) {
            return TicketNumberService::normalize($m[1]);
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function employeeByEmail(string $email): ?array
    {
        foreach ($this->employees->search(['q' => $email], 5, 0) as $employee) {
            if (mb_strtolower((string) ($employee['email'] ?? '')) === $email) {
                return $employee;
            }
        }

        return null;
    }
}
