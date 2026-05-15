<?php

namespace App\Support;

class MailDeliveryInspector
{
    /**
     * @return array{deliverable: bool, message: ?string, mailer: string}
     */
    public static function inspect(?string $mailer = null, array $visited = []): array
    {
        $mailer ??= (string) config('mail.default', 'log');

        if (in_array($mailer, $visited, true)) {
            return [
                'deliverable' => false,
                'message' => "The [$mailer] mailer configuration references itself recursively.",
                'mailer' => $mailer,
            ];
        }

        $visited[] = $mailer;

        $config = config("mail.mailers.$mailer");

        if (!is_array($config)) {
            return [
                'deliverable' => false,
                'message' => "The configured [$mailer] mailer was not found in config/mail.php.",
                'mailer' => $mailer,
            ];
        }

        $transport = (string) ($config['transport'] ?? $mailer);

        return match ($transport) {
            'smtp' => self::inspectSmtp($mailer, $config),
            'sendmail' => self::inspectSendmail($mailer, $config),
            'resend' => self::inspectResend($mailer),
            'postmark' => self::inspectPostmark($mailer),
            'ses', 'ses-v2' => self::inspectSes($mailer),
            'log' => self::notDeliverable($mailer, 'MAIL_MAILER=log writes emails to the Laravel log only. Configure a real delivery provider for automatic invitations.'),
            'array' => self::notDeliverable($mailer, 'MAIL_MAILER=array stores emails in memory only. Configure a real delivery provider for automatic invitations.'),
            'failover', 'roundrobin' => self::inspectComposite($mailer, $config, $visited),
            default => self::inspectGeneric($mailer),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{deliverable: bool, message: ?string, mailer: string}
     */
    private static function inspectSmtp(string $mailer, array $config): array
    {
        $host = trim((string) ($config['host'] ?? ''));
        $port = trim((string) ($config['port'] ?? ''));
        $username = trim((string) ($config['username'] ?? ''));
        $password = trim((string) ($config['password'] ?? ''));
        $fromAddress = trim((string) config('mail.from.address'));

        if (self::isBlankOrPlaceholder($host, ['127.0.0.1'])) {
            return self::notDeliverable($mailer, 'MAIL_HOST must contain the real SMTP host.');
        }

        if (self::isBlankOrPlaceholder($port, ['2525'])) {
            return self::notDeliverable($mailer, 'MAIL_PORT must contain the real SMTP port.');
        }

        if (self::isBlankOrPlaceholder($username, ['your-real-email@gmail.com', 'your-brevo-smtp-login@example.com', 'null'])) {
            return self::notDeliverable($mailer, 'MAIL_USERNAME must contain the real sender mailbox username.');
        }

        if (self::isBlankOrPlaceholder($password, ['your-app-password', 'your-brevo-smtp-key', 'PASTE_GMAIL_APP_PASSWORD_HERE', 'null'])) {
            return self::notDeliverable($mailer, 'MAIL_PASSWORD must contain the real sender mailbox password or app password.');
        }

        if (self::isBlankOrPlaceholder($fromAddress, ['your-real-email@gmail.com', 'your-verified-sender@example.com', 'hello@example.com', 'onboarding@yourdomain.com', 'noreply@yourdomain.com'])) {
            return self::notDeliverable($mailer, 'MAIL_FROM_ADDRESS must contain the real sender mailbox address.');
        }

        return self::deliverable($mailer);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{deliverable: bool, message: ?string, mailer: string}
     */
    private static function inspectSendmail(string $mailer, array $config): array
    {
        $path = trim((string) ($config['path'] ?? ''));
        $fromAddress = trim((string) config('mail.from.address'));

        if ($path === '') {
            return self::notDeliverable($mailer, 'MAIL_SENDMAIL_PATH must be configured for the sendmail mailer.');
        }

        if (self::isBlankOrPlaceholder($fromAddress, ['your-real-email@gmail.com', 'hello@example.com', 'onboarding@yourdomain.com', 'noreply@yourdomain.com'])) {
            return self::notDeliverable($mailer, 'MAIL_FROM_ADDRESS must contain the real sender mailbox address.');
        }

        return self::deliverable($mailer);
    }

    /**
     * @return array{deliverable: bool, message: ?string, mailer: string}
     */
    private static function inspectResend(string $mailer): array
    {
        $key = trim((string) config('services.resend.key'));
        $fromAddress = trim((string) config('mail.from.address'));

        if (self::isBlankOrPlaceholder($key, ['your-resend-api-key', 'null'])) {
            return self::notDeliverable($mailer, 'RESEND_API_KEY must be configured to use the Resend mailer.');
        }

        if (self::isBlankOrPlaceholder($fromAddress, ['hello@example.com', 'onboarding@yourdomain.com', 'noreply@yourdomain.com'])) {
            return self::notDeliverable($mailer, 'MAIL_FROM_ADDRESS must contain the verified sender address for Resend.');
        }

        return self::deliverable($mailer);
    }

    /**
     * @return array{deliverable: bool, message: ?string, mailer: string}
     */
    private static function inspectPostmark(string $mailer): array
    {
        $key = trim((string) config('services.postmark.key'));
        $fromAddress = trim((string) config('mail.from.address'));

        if (self::isBlankOrPlaceholder($key, ['your-postmark-api-key', 'null'])) {
            return self::notDeliverable($mailer, 'POSTMARK_API_KEY must be configured to use the Postmark mailer.');
        }

        if (self::isBlankOrPlaceholder($fromAddress, ['hello@example.com', 'onboarding@yourdomain.com', 'noreply@yourdomain.com'])) {
            return self::notDeliverable($mailer, 'MAIL_FROM_ADDRESS must contain the verified sender address for Postmark.');
        }

        return self::deliverable($mailer);
    }

    /**
     * @return array{deliverable: bool, message: ?string, mailer: string}
     */
    private static function inspectSes(string $mailer): array
    {
        $key = trim((string) config('services.ses.key'));
        $secret = trim((string) config('services.ses.secret'));
        $region = trim((string) config('services.ses.region'));
        $fromAddress = trim((string) config('mail.from.address'));

        if (self::isBlankOrPlaceholder($key, ['null'])) {
            return self::notDeliverable($mailer, 'AWS_ACCESS_KEY_ID must be configured to use the SES mailer.');
        }

        if (self::isBlankOrPlaceholder($secret, ['null'])) {
            return self::notDeliverable($mailer, 'AWS_SECRET_ACCESS_KEY must be configured to use the SES mailer.');
        }

        if ($region === '') {
            return self::notDeliverable($mailer, 'AWS_DEFAULT_REGION must be configured to use the SES mailer.');
        }

        if (self::isBlankOrPlaceholder($fromAddress, ['hello@example.com', 'onboarding@yourdomain.com', 'noreply@yourdomain.com'])) {
            return self::notDeliverable($mailer, 'MAIL_FROM_ADDRESS must contain the verified sender address for SES.');
        }

        return self::deliverable($mailer);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $visited
     * @return array{deliverable: bool, message: ?string, mailer: string}
     */
    private static function inspectComposite(string $mailer, array $config, array $visited): array
    {
        $mailers = $config['mailers'] ?? [];

        if (!is_array($mailers) || $mailers === []) {
            return self::notDeliverable($mailer, "The [$mailer] mailer has no child mailers configured.");
        }

        $messages = [];

        foreach ($mailers as $childMailer) {
            $childResult = self::inspect((string) $childMailer, $visited);

            if ($childResult['deliverable']) {
                return self::deliverable($mailer);
            }

            if (!empty($childResult['message'])) {
                $messages[] = $childResult['message'];
            }
        }

        return self::notDeliverable(
            $mailer,
            implode(' ', array_unique($messages)) ?: "None of the child mailers configured for [$mailer] can deliver real emails."
        );
    }

    /**
     * @return array{deliverable: bool, message: ?string, mailer: string}
     */
    private static function inspectGeneric(string $mailer): array
    {
        $fromAddress = trim((string) config('mail.from.address'));

        if (self::isBlankOrPlaceholder($fromAddress, ['hello@example.com', 'onboarding@yourdomain.com', 'noreply@yourdomain.com'])) {
            return self::notDeliverable($mailer, 'MAIL_FROM_ADDRESS must contain the real sender address.');
        }

        return self::deliverable($mailer);
    }

    private static function isBlankOrPlaceholder(string $value, array $placeholders = []): bool
    {
        if ($value === '') {
            return true;
        }

        $normalized = strtolower($value);

        if ($normalized === 'null') {
            return true;
        }

        foreach ($placeholders as $placeholder) {
            if ($normalized === strtolower($placeholder)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{deliverable: bool, message: ?string, mailer: string}
     */
    private static function deliverable(string $mailer): array
    {
        return [
            'deliverable' => true,
            'message' => null,
            'mailer' => $mailer,
        ];
    }

    /**
     * @return array{deliverable: bool, message: ?string, mailer: string}
     */
    private static function notDeliverable(string $mailer, string $message): array
    {
        return [
            'deliverable' => false,
            'message' => $message,
            'mailer' => $mailer,
        ];
    }
}
