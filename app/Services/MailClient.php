<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;

/**
 * Client für den E-Mail-Versanddienst (Container "mail"): reicht Entnahme-/Retourennachweise
 * und Übergabeprotokolle als PDF-Anhang an einen per SMTP angebundenen Mailserver weiter.
 * Der Versand ist rein informativ (Opt-in); Fehler dürfen den eigentlichen Workflow nie stoppen.
 */
final class MailClient
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function enabled(): bool
    {
        return trim((string) $this->config->get('mail.service_url', '')) !== '';
    }

    /**
     * Sendet eine E-Mail mit optionalen Anhängen. Liefert true bei Erfolg, false bei
     * deaktiviertem/nicht erreichbarem Dienst oder einem Fehler (wird geloggt, nicht geworfen).
     *
     * @param list<array{filename:string,content:string,mime_type:string}> $attachments
     */
    public function send(string $to, string $subject, string $html, array $attachments = []): bool
    {
        if (!$this->enabled() || !function_exists('curl_init')) {
            return false;
        }
        if (trim($to) === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->logger->warning('E-Mail-Versand übersprungen: ungültige Empfängeradresse', ['to' => $to]);

            return false;
        }

        $payload = json_encode([
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'attachments' => array_map(static fn (array $a): array => [
                'filename' => $a['filename'],
                'mime_type' => $a['mime_type'],
                'content_base64' => base64_encode($a['content']),
            ], $attachments),
        ], JSON_THROW_ON_ERROR);

        $url = rtrim((string) $this->config->get('mail.service_url'), '/') . '/send';
        $timeout = max(5, (int) $this->config->get('mail.service_timeout', 15));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($status !== 200) {
            $this->logger->warning('E-Mail-Dienst meldet Fehler oder ist nicht erreichbar', [
                'url' => $url,
                'status' => $status,
                'error' => $error !== '' ? $error : (is_string($body) ? mb_substr($body, 0, 300) : ''),
            ]);

            return false;
        }

        return true;
    }

    /** Erreichbarkeitsprüfung für die Admin-Oberfläche. */
    public function healthy(): bool
    {
        if (!$this->enabled() || !function_exists('curl_init')) {
            return false;
        }
        $ch = curl_init(rtrim((string) $this->config->get('mail.service_url'), '/') . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $status === 200;
    }
}
