<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ValidationException;

/**
 * Kleiner Validierer für Formulareingaben. Sammelt Fehler pro Feld und liefert
 * die bereinigten Werte; wirft ValidationException bei Fehlern.
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $clean = [];

    /** @param array<string,mixed> $input */
    public function __construct(private readonly array $input) {}

    public function string(string $field, string $label, bool $required = false, int $max = 255, ?int $min = null): self
    {
        $value = trim((string) ($this->input[$field] ?? ''));
        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "{$label} ist erforderlich.";
            }
            $this->clean[$field] = null;

            return $this;
        }
        if (mb_strlen($value) > $max) {
            $this->errors[$field] = "{$label} darf höchstens {$max} Zeichen lang sein.";
        } elseif ($min !== null && mb_strlen($value) < $min) {
            $this->errors[$field] = "{$label} muss mindestens {$min} Zeichen lang sein.";
        }
        $this->clean[$field] = $value;

        return $this;
    }

    public function text(string $field, string $label, bool $required = false, int $max = 5000): self
    {
        return $this->string($field, $label, $required, $max);
    }

    public function int(string $field, string $label, bool $required = false, ?int $min = null, ?int $max = null): self
    {
        $raw = trim((string) ($this->input[$field] ?? ''));
        if ($raw === '') {
            if ($required) {
                $this->errors[$field] = "{$label} ist erforderlich.";
            }
            $this->clean[$field] = null;

            return $this;
        }
        if (!preg_match('/^-?\d+$/', $raw)) {
            $this->errors[$field] = "{$label} muss eine ganze Zahl sein.";
            $this->clean[$field] = null;

            return $this;
        }
        $value = (int) $raw;
        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            $this->errors[$field] = "{$label} liegt außerhalb des gültigen Bereichs.";
        }
        $this->clean[$field] = $value;

        return $this;
    }

    /** Fremdschlüssel: positive ID oder null. */
    public function id(string $field, string $label, bool $required = false): self
    {
        return $this->int($field, $label, $required, 1);
    }

    public function decimal(string $field, string $label, bool $required = false, ?float $min = null): self
    {
        $raw = trim((string) ($this->input[$field] ?? ''));
        if ($raw === '') {
            if ($required) {
                $this->errors[$field] = "{$label} ist erforderlich.";
            }
            $this->clean[$field] = null;

            return $this;
        }
        $normalized = str_replace([' ', '.', ','], ['', '', '.'], preg_replace('/\.(?=\d{3}(\D|$))/', '', $raw) ?? $raw);
        if (!is_numeric($normalized)) {
            $this->errors[$field] = "{$label} muss eine Zahl sein.";
            $this->clean[$field] = null;

            return $this;
        }
        $value = round((float) $normalized, 2);
        if ($min !== null && $value < $min) {
            $this->errors[$field] = "{$label} darf nicht kleiner als {$min} sein.";
        }
        $this->clean[$field] = $value;

        return $this;
    }

    public function bool(string $field): self
    {
        $this->clean[$field] = filter_var($this->input[$field] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0;

        return $this;
    }

    public function email(string $field, string $label, bool $required = false): self
    {
        $this->string($field, $label, $required, 255);
        $value = $this->clean[$field];
        if ($value !== null && !isset($this->errors[$field]) && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = "{$label} ist keine gültige E-Mail-Adresse.";
        }

        return $this;
    }

    public function url(string $field, string $label, bool $required = false): self
    {
        $this->string($field, $label, $required, 255);
        $value = $this->clean[$field];
        if ($value !== null && !isset($this->errors[$field])) {
            if (!preg_match('~^https?://~i', $value)) {
                $value = 'https://' . $value;
            }
            if (filter_var($value, FILTER_VALIDATE_URL) === false) {
                $this->errors[$field] = "{$label} ist keine gültige Adresse.";
            } else {
                $this->clean[$field] = $value;
            }
        }

        return $this;
    }

    public function date(string $field, string $label, bool $required = false): self
    {
        $raw = trim((string) ($this->input[$field] ?? ''));
        if ($raw === '') {
            if ($required) {
                $this->errors[$field] = "{$label} ist erforderlich.";
            }
            $this->clean[$field] = null;

            return $this;
        }
        $date = self::parseDate($raw);
        if ($date === null) {
            $this->errors[$field] = "{$label} ist kein gültiges Datum.";
        }
        $this->clean[$field] = $date;

        return $this;
    }

    /** @param array<int|string,mixed> $allowed */
    public function in(string $field, string $label, array $allowed, bool $required = false): self
    {
        $raw = trim((string) ($this->input[$field] ?? ''));
        if ($raw === '') {
            if ($required) {
                $this->errors[$field] = "{$label} ist erforderlich.";
            }
            $this->clean[$field] = null;

            return $this;
        }
        if (!in_array($raw, array_map('strval', $allowed), true)) {
            $this->errors[$field] = "{$label} enthält einen ungültigen Wert.";
        }
        $this->clean[$field] = $raw;

        return $this;
    }

    public function pattern(string $field, string $label, string $regex, string $message, bool $required = false): self
    {
        $this->string($field, $label, $required);
        $value = $this->clean[$field];
        if ($value !== null && !isset($this->errors[$field]) && !preg_match($regex, $value)) {
            $this->errors[$field] = $message;
        }

        return $this;
    }

    public function addError(string $field, string $message): self
    {
        $this->errors[$field] = $message;

        return $this;
    }

    public function set(string $field, mixed $value): self
    {
        $this->clean[$field] = $value;

        return $this;
    }

    public function value(string $field): mixed
    {
        return $this->clean[$field] ?? null;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,mixed> Bereinigte Werte; wirft bei Fehlern. */
    public function validated(): array
    {
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }

        return $this->clean;
    }

    /** Akzeptiert ISO (YYYY-MM-DD) und deutsches Format (TT.MM.JJJJ). */
    public static function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $raw;
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $raw, $m)) {
            $year = strlen($m[3]) === 2 ? (int) ('20' . $m[3]) : (int) $m[3];
            if (checkdate((int) $m[2], (int) $m[1], $year)) {
                return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
            }
        }

        return null;
    }
}
