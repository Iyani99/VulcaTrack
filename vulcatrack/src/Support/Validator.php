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

    /**
     * Optional trimmed text (may be empty). Returns the clean value, or null
     * when blank. Only records an error when a non-empty value is too long.
     */
    public function optionalText(string $field, $value, string $label, int $max): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value)) {
            $this->add($field, "{$label} is invalid.");
            return null;
        }
        $clean = trim($value);
        if (mb_strlen($clean) > $max) {
            $this->add($field, "{$label} is too long.");
            return null;
        }
        return $clean;
    }

    /** A required latitude/longitude pair. Returns [lat, lng] as floats, or null. */
    public function coordinates(string $field, $lat, $lng): ?array
    {
        if (!\VulcaTrack\Support\Geo::isValidLatitude($lat)
            || !\VulcaTrack\Support\Geo::isValidLongitude($lng)) {
            $this->add($field, 'Share your location before submitting.');
            return null;
        }
        return [(float) $lat, (float) $lng];
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

    // --- Phase 5: inventory / POS field validation ---------------------------
    //
    // Aligns with the approved limits (Decisions 52/55/56): price >= 0,
    // quantity > 0, stock >= 0. No schema CHECK constraints are added for these.

    /**
     * A money amount (price). Returns the value in integer centavos, or null.
     * Accepts "0", "100", "100.00", "99.95"; rejects negatives, separators,
     * more than two decimal places, and amounts DECIMAL(10,2) cannot hold.
     */
    public function price(string $field, $value, string $label = 'Price'): ?int
    {
        $centavos = \VulcaTrack\Support\Money::tryToCentavos(
            is_string($value) || is_int($value) ? $value : ''
        );
        if ($centavos === null) {
            $this->add($field, "{$label} must be an amount like 0, 100 or 99.95.");
            return null;
        }
        return $centavos;
    }

    /** A required whole number greater than zero (e.g. a cart quantity). */
    public function quantity(string $field, $value, string $label = 'Quantity'): ?int
    {
        $n = $this->wholeNumber($value, 1);
        if ($n === null) {
            $this->add($field, "{$label} must be a whole number greater than zero.");
        }
        return $n;
    }

    /** A required whole number of zero or more (product stock). */
    public function stock(string $field, $value, string $label = 'Stock quantity'): ?int
    {
        $n = $this->wholeNumber($value, 0);
        if ($n === null) {
            $this->add($field, "{$label} must be zero or a positive whole number.");
        }
        return $n;
    }

    /**
     * An optional whole number of zero or more (product reorder level). Blank
     * returns null with no error; a present-but-invalid value records one.
     */
    public function optionalNonNegativeInt(string $field, $value, string $label): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $n = $this->wholeNumber($value, 0);
        if ($n === null) {
            $this->add($field, "{$label} must be zero or a positive whole number.");
        }
        return $n;
    }

    /** The unified items table's type discriminator: 'product' or 'service'. */
    public function itemType(string $field, $value): ?string
    {
        if (is_string($value) && ($value === 'product' || $value === 'service')) {
            return $value;
        }
        $this->add($field, 'Choose a valid item type (product or service).');
        return null;
    }

    /**
     * Parse an integer that is >= $min. Accepts a real int or a plain digit
     * string ("5"); rejects floats, "5.0", "5x", empty and out-of-range values.
     */
    private function wholeNumber($value, int $min): ?int
    {
        if (is_int($value)) {
            $n = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            $n = (int) trim($value);
        } else {
            return null;
        }
        return $n >= $min ? $n : null;
    }
}
