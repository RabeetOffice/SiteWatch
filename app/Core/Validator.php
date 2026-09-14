<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small, explicit input validator used by API endpoints and forms.
 *
 *   $v = new Validator($input);
 *   $v->required('name', 'Website name')->max('name', 150);
 *   $v->email('email');
 *   if ($v->fails()) { Response::error('Validation failed', $v->errors(), 422); }
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function value(string $field): mixed
    {
        $value = $this->data[$field] ?? null;
        return is_string($value) ? trim($value) : $value;
    }

    public function required(string $field, ?string $label = null): self
    {
        $value = $this->value($field);
        if ($value === null || $value === '' || $value === []) {
            $this->addError($field, ($label ?? $this->label($field)) . ' is required.');
        }
        return $this;
    }

    public function max(string $field, int $length, ?string $label = null): self
    {
        $value = $this->value($field);
        if (is_string($value) && mb_strlen($value) > $length) {
            $this->addError($field, ($label ?? $this->label($field)) . " must not exceed {$length} characters.");
        }
        return $this;
    }

    public function min(string $field, int $length, ?string $label = null): self
    {
        $value = $this->value($field);
        if (is_string($value) && $value !== '' && mb_strlen($value) < $length) {
            $this->addError($field, ($label ?? $this->label($field)) . " must be at least {$length} characters.");
        }
        return $this;
    }

    public function email(string $field, ?string $label = null): self
    {
        $value = $this->value($field);
        if (is_string($value) && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, ($label ?? $this->label($field)) . ' must be a valid email address.');
        }
        return $this;
    }

    /** @param array<int, int|string> $allowed */
    public function in(string $field, array $allowed, ?string $label = null): self
    {
        $value = $this->value($field);
        if ($value !== null && $value !== '' && !in_array($value, $allowed, false)) {
            $this->addError($field, ($label ?? $this->label($field)) . ' has an invalid value.');
        }
        return $this;
    }

    public function integer(string $field, ?int $min = null, ?int $max = null, ?string $label = null): self
    {
        $value = $this->value($field);
        if ($value === null || $value === '') {
            return $this;
        }
        if (!is_numeric($value) || (string) (int) $value !== (string) $value && !is_int($value)) {
            $this->addError($field, ($label ?? $this->label($field)) . ' must be a whole number.');
            return $this;
        }
        $int = (int) $value;
        if ($min !== null && $int < $min) {
            $this->addError($field, ($label ?? $this->label($field)) . " must be at least {$min}.");
        } elseif ($max !== null && $int > $max) {
            $this->addError($field, ($label ?? $this->label($field)) . " must not exceed {$max}.");
        }
        return $this;
    }

    public function numeric(string $field, ?float $min = null, ?float $max = null, ?string $label = null): self
    {
        $value = $this->value($field);
        if ($value === null || $value === '') {
            return $this;
        }
        if (!is_numeric($value)) {
            $this->addError($field, ($label ?? $this->label($field)) . ' must be a number.');
            return $this;
        }
        $num = (float) $value;
        if ($min !== null && $num < $min) {
            $this->addError($field, ($label ?? $this->label($field)) . " must be at least {$min}.");
        } elseif ($max !== null && $num > $max) {
            $this->addError($field, ($label ?? $this->label($field)) . " must not exceed {$max}.");
        }
        return $this;
    }

    public function url(string $field, ?string $label = null): self
    {
        $value = $this->value($field);
        if (is_string($value) && $value !== '' && UrlNormalizer::normalize($value) === null) {
            $this->addError($field, ($label ?? $this->label($field)) . ' must be a valid http(s) URL.');
        }
        return $this;
    }

    public function timezone(string $field, ?string $label = null): self
    {
        $value = $this->value($field);
        if (is_string($value) && $value !== '' && !in_array($value, \DateTimeZone::listIdentifiers(), true)) {
            $this->addError($field, ($label ?? $this->label($field)) . ' is not a valid timezone.');
        }
        return $this;
    }

    public function regex(string $field, string $pattern, string $message): self
    {
        $value = $this->value($field);
        if (is_string($value) && $value !== '' && !preg_match($pattern, $value)) {
            $this->addError($field, $message);
        }
        return $this;
    }

    public function custom(string $field, callable $check, string $message): self
    {
        if (!$check($this->value($field), $this->data)) {
            $this->addError($field, $message);
        }
        return $this;
    }

    public function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors === [] ? null : (string) reset($this->errors);
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $field));
    }
}
