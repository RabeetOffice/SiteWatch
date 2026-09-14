<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * Inspects a site's TLS certificate with native OpenSSL streams.
 * Connects to the SSRF-validated IP with SNI set to the hostname, verifies the chain and hostname,
 * then (if verification fails) reconnects without verification purely to read the certificate
 * details so expiry information can still be reported.
 */
final class SSLChecker
{
    public function __construct(
        private readonly SsrfGuard $guard,
        private readonly ?string $caFile = null,
        private readonly int $timeoutSeconds = 10
    ) {
    }

    /**
     * @return array{valid: bool, expires_at: ?string, days_remaining: ?int, issuer: ?string, subject: ?string, error: ?string, checked_at: string, valid_from: ?string}
     */
    public function check(string $url): array
    {
        $result = [
            'valid'          => false,
            'expires_at'     => null,
            'valid_from'     => null,
            'days_remaining' => null,
            'issuer'         => null,
            'subject'        => null,
            'error'          => null,
            'checked_at'     => gmdate('Y-m-d H:i:s'),
        ];

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            $result['error'] = 'Not an HTTPS URL.';
            return $result;
        }

        $resolution = $this->guard->resolve($url);
        if (!$resolution['ok']) {
            $result['error'] = $resolution['error'] ?? 'Could not resolve host.';
            return $result;
        }
        $host = $resolution['host'];
        $port = $resolution['port'];
        $ip = $resolution['ips'][0];
        $target = 'ssl://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $port;

        // 1. Strict verification -------------------------------------------------------
        $verifyError = null;
        $cert = $this->fetchCertificate($target, $host, true, $verifyError);
        if ($cert !== null) {
            $result['valid'] = true;
        } else {
            $result['error'] = $verifyError ?? 'TLS handshake failed.';
            // 2. Retrieve certificate details without verification (diagnostics only).
            $insecureError = null;
            $cert = $this->fetchCertificate($target, $host, false, $insecureError);
            if ($cert === null) {
                $result['error'] = $insecureError ?? $result['error'];
                return $result;
            }
        }

        $parsed = @openssl_x509_parse($cert);
        if (!is_array($parsed)) {
            $result['error'] = $result['error'] ?? 'Unable to parse certificate.';
            $result['valid'] = false;
            return $result;
        }

        $validTo = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
        $validFrom = isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : null;
        if ($validTo !== null) {
            $result['expires_at'] = gmdate('Y-m-d H:i:s', $validTo);
            $result['days_remaining'] = (int) floor(($validTo - time()) / 86400);
        }
        if ($validFrom !== null) {
            $result['valid_from'] = gmdate('Y-m-d H:i:s', $validFrom);
        }
        $issuer = $parsed['issuer'] ?? [];
        $result['issuer'] = $this->firstString($issuer['O'] ?? null) ?? $this->firstString($issuer['CN'] ?? null);
        $subject = $parsed['subject'] ?? [];
        $result['subject'] = $this->firstString($subject['CN'] ?? null);

        if ($validTo !== null && $validTo < time()) {
            $result['valid'] = false;
            $result['error'] = 'Certificate expired on ' . gmdate('j M Y', $validTo) . '.';
        } elseif (!$result['valid']) {
            $result['error'] = $this->friendlyError((string) $result['error'], $host, $parsed);
        }

        return $result;
    }

    /**
     * @return \OpenSSLCertificate|null
     */
    private function fetchCertificate(string $target, string $host, bool $verify, ?string &$error)
    {
        $options = [
            'ssl' => [
                'peer_name'         => $host,
                'SNI_enabled'       => true,
                'capture_peer_cert' => true,
                'verify_peer'       => $verify,
                'verify_peer_name'  => $verify,
                'allow_self_signed' => !$verify,
                'verify_depth'      => 10,
            ],
        ];
        if ($verify && $this->caFile !== null && is_file($this->caFile)) {
            $options['ssl']['cafile'] = $this->caFile;
        }
        $context = stream_context_create($options);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($target, $errno, $errstr, max(3, $this->timeoutSeconds), STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            $last = error_get_last();
            $message = $errstr !== '' ? $errstr : ($last['message'] ?? 'Connection failed');
            $error = $this->cleanErrorMessage($message);
            return null;
        }
        $params = stream_context_get_params($stream);
        fclose($stream);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($cert === null) {
            $error = 'No certificate presented by the server.';
            return null;
        }
        return $cert;
    }

    private function cleanErrorMessage(string $message): string
    {
        $message = preg_replace('/^stream_socket_client\(\):\s*/', '', $message) ?? $message;
        $message = preg_replace('/\s*\(Unknown error\)$/', '', $message) ?? $message;
        if (stripos($message, 'certificate verify failed') !== false) {
            return 'Certificate verification failed (untrusted, self-signed or incomplete chain).';
        }
        if (stripos($message, 'did not match expected') !== false || stripos($message, 'Peer certificate CN') !== false) {
            return 'Certificate hostname mismatch.';
        }
        if (stripos($message, 'timed out') !== false) {
            return 'TLS connection timed out.';
        }
        return trim($message);
    }

    /** @param array<string, mixed> $parsed */
    private function friendlyError(string $error, string $host, array $parsed): string
    {
        if ($error === '' || $error === 'TLS handshake failed.') {
            $cn = $this->firstString($parsed['subject']['CN'] ?? null);
            if ($cn !== null && !$this->hostMatches($host, $cn)) {
                return "Certificate hostname mismatch (issued for {$cn}).";
            }
        }
        return $error;
    }

    private function hostMatches(string $host, string $cn): bool
    {
        $cn = strtolower($cn);
        if ($cn === $host) {
            return true;
        }
        if (str_starts_with($cn, '*.')) {
            $suffix = substr($cn, 1);
            return str_ends_with($host, $suffix) && substr_count($host, '.') === substr_count($cn, '.');
        }
        return false;
    }

    private function firstString(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 190) : null;
    }
}
