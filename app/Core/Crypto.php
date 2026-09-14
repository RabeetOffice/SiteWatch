<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * AES-256-GCM encryption for stored credentials (SMTP password, Telegram bot token).
 * Uses the APP_KEY from .env. Values are prefixed so plaintext legacy values are still readable.
 */
final class Crypto
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly string $key)
    {
    }

    public static function fromConfig(): self
    {
        return new self((string) App::config()->get('app.key', ''));
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    public function isConfigured(): bool
    {
        return $this->binaryKey() !== null;
    }

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $key = $this->binaryKey();
        if ($key === null) {
            throw new RuntimeException('APP_KEY is not configured; cannot encrypt credentials.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!str_starts_with($value, self::PREFIX)) {
            return $value; // stored as plaintext (should not happen, but never break)
        }
        $key = $this->binaryKey();
        if ($key === null) {
            return '';
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    public function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    private function binaryKey(): ?string
    {
        if ($this->key === '') {
            return null;
        }
        $decoded = base64_decode($this->key, true);
        if ($decoded === false || strlen($decoded) < 32) {
            // Allow arbitrary long secrets by hashing them to 32 bytes.
            return strlen($this->key) >= 16 ? hash('sha256', $this->key, true) : null;
        }
        return substr($decoded, 0, 32);
    }
}
