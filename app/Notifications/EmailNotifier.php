<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Repositories\SettingsRepository;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * SMTP e-mail channel built on PHPMailer.
 */
final class EmailNotifier implements NotifierInterface
{
    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function name(): string
    {
        return 'email';
    }

    public function isEnabled(): bool
    {
        return $this->settings->getBool('email_enabled')
            && $this->settings->getString('smtp_host') !== ''
            && $this->recipients() !== [];
    }

    /** @return array<int, string> */
    public function recipients(): array
    {
        return self::parseRecipients($this->settings->getString('notification_recipients'));
    }

    /** @return array<int, string> */
    public static function parseRecipients(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $out[] = mb_strtolower($part);
            }
        }
        return array_values(array_unique($out));
    }

    public function send(AlertMessage $message): string
    {
        return $this->deliver($message, $this->recipients());
    }

    /**
     * Send to explicit recipients (used by the "Send Test Email" action).
     *
     * @param array<int, string> $recipients
     */
    public function deliver(AlertMessage $message, array $recipients): string
    {
        if ($recipients === []) {
            throw new RuntimeException('No valid notification recipients are configured.');
        }
        $host = $this->settings->getString('smtp_host');
        if ($host === '') {
            throw new RuntimeException('SMTP host is not configured.');
        }

        $fromEmail = $this->settings->getString('smtp_from_email');
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $username = $this->settings->getString('smtp_username');
            $fromEmail = filter_var($username, FILTER_VALIDATE_EMAIL) ? $username : 'sitewatch@' . (gethostname() ?: 'localhost');
        }
        $fromName = $this->settings->getString('smtp_from_name', 'SiteWatch') ?: 'SiteWatch';

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = max(1, $this->settings->getInt('smtp_port', 587));
            $mail->Timeout = 20;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;

            $username = $this->settings->getString('smtp_username');
            $password = $this->settings->getString('smtp_password');
            $mail->SMTPAuth = $username !== '';
            if ($mail->SMTPAuth) {
                $mail->Username = $username;
                $mail->Password = $password;
            }

            $encryption = strtolower($this->settings->getString('smtp_encryption', 'tls'));
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($fromEmail, $fromName);
            foreach ($recipients as $recipient) {
                $mail->addAddress($recipient);
            }
            $mail->isHTML(true);
            $mail->Subject = $message->subject;
            $mail->Body = $message->html;
            $mail->AltBody = $message->text;
            $mail->send();
        } catch (MailerException $e) {
            throw new RuntimeException('SMTP error: ' . self::sanitize($mail->ErrorInfo ?: $e->getMessage()));
        }
        return implode(', ', $recipients);
    }

    /**
     * Strip anything that might contain credentials from an SMTP error string.
     */
    private static function sanitize(string $error): string
    {
        $error = preg_replace('/AUTH\s+\S+\s+\S+/i', 'AUTH [redacted]', $error) ?? $error;
        return mb_substr(trim(strip_tags($error)), 0, 400);
    }
}
