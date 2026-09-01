<?php

namespace VulcaTrack\Support;

/**
 * Minimal server-side validator with a per-field error bag.
 * The first error recorded for a field wins.
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function add(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    /**
     * Required, trimmed text within a length range. Returns the clean value or null.
     */
    public function text(string $field, $value, string $label, int $max, int $min = 1): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            $this->add($field, "{$label} is required.");
            return null;
        }
        $clean = trim($value);
        if (mb_strlen($clean) < $min) {
            $this->add($field, "{$label} is too short.");
            return null;
        }
        if (mb_strlen($clean) > $max) {
            $this->add($field, "{$label} is too long.");
            return null;
        }
        return $clean;
    }

    /** Required, valid email address. Returns the clean value or null. */
    public function email(string $field, $value, int $max = 190): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            $this->add($field, 'Email is required.');
            return null;
        }
        $clean = trim($value);
        if (mb_strlen($clean) > $max || filter_var($clean, FILTER_VALIDATE_EMAIL) === false) {
            $this->add($field, 'Enter a valid email address.');
            return null;
        }
        return $clean;
    }

    /** Required password meeting the minimum length. Returns the raw value or null. */
    public function password(string $field, $value, int $minLength): ?string
    {
        if (!is_string($value) || $value === '') {
            $this->add($field, 'Password is required.');
            return null;
        }
        if (strlen($value) < $minLength) {
            $this->add($field, "Password must be at least {$minLength} characters.");
            return null;
        }
        return $value;
    }

    /** Confirmation must equal the original value. */
    public function matches(string $field, $confirmation, $original, string $label): void
    {
        if (!is_string($confirmation) || $confirmation !== $original) {
            $this->add($field, "{$label} do not match.");
        }
    }
}
