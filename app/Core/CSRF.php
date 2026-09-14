<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Synchroniser-token CSRF protection. The token is sent either as the `_token` form field
 * or as the `X-CSRF-Token` request header (used by the Fetch API calls).
 */
final class CSRF
{
    private const KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }
        return $token;
    }

    public function validate(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        $expected = $this->session->get(self::KEY);
        return is_string($expected) && hash_equals($expected, $token);
    }

    public function validateRequest(): bool
    {
        $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if ($token === null) {
            $json = Request::json();
            $token = $json['_token'] ?? null;
        }
        return $this->validate(is_string($token) ? $token : null);
    }

    public function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e($this->token()) . '">';
    }

    public function rotate(): void
    {
        $this->session->set(self::KEY, bin2hex(random_bytes(32)));
    }
}
