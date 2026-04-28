<?php

namespace App\Services;

use Symfony\Component\HttpClient\HttpClient;

class TwilioVerifyService
{
    private const OTP_COOLDOWN_SECONDS = 30;

    public function getOtpSessionKeys(string $flow): array
    {
        return [
            $flow . '_pending',
            $flow . '_user_type',
            $flow . '_user_id',
            $flow . '_email',
            $flow . '_role',
            $flow . '_channel',
            $flow . '_destination',
            $flow . '_resend_available_at',
            $flow . '_verified',
        ];
    }

    public function buildOtpSessionData(
        string $flow,
        string $userType,
        int $userId,
        string $email,
        string $destination,
        ?string $role = null,
        string $channel = 'sms'
    ): array {
        return [
            $flow . '_pending' => true,
            $flow . '_user_type' => $userType,
            $flow . '_user_id' => $userId,
            $flow . '_email' => $email,
            $flow . '_role' => $role,
            $flow . '_channel' => $channel,
            $flow . '_destination' => $destination,
            $flow . '_resend_available_at' => $this->nextResendAvailableAt(),
            $flow . '_verified' => false,
        ];
    }

    public function sendCode(string $destination, string $channel): void
    {
        $response = $this->client()->request('POST', $this->endpoint('/Verifications'), [
            'auth_basic' => [$this->requireEnv('TWILIO_ACCOUNT_SID'), $this->requireEnv('TWILIO_AUTH_TOKEN')],
            'body' => [
                'To' => $this->normalizeDestination($destination, $channel),
                'Channel' => $this->normalizeChannel($channel),
            ],
        ]);

        $this->assertSuccess($response->getStatusCode(), $response->getContent(false));
    }

    public function verifyCode(string $destination, string $code, string $channel): bool
    {
        $response = $this->client()->request('POST', $this->endpoint('/VerificationCheck'), [
            'auth_basic' => [$this->requireEnv('TWILIO_ACCOUNT_SID'), $this->requireEnv('TWILIO_AUTH_TOKEN')],
            'body' => [
                'To' => $this->normalizeDestination($destination, $channel),
                'Code' => trim($code),
            ],
        ]);

        $content = $response->getContent(false);
        $this->assertSuccess($response->getStatusCode(), $content);

        $payload = json_decode($content, true);
        return is_array($payload) && ($payload['status'] ?? null) === 'approved';
    }

    public function buildTwoFactorSessionData(string $userType, int $userId, string $email, string $destination, ?string $role = null, string $channel = 'sms'): array
    {
        return $this->buildOtpSessionData('two_factor', $userType, $userId, $email, $destination, $role, $channel);
    }

    public function getResendRemainingSeconds(int $resendAvailableAt): int
    {
        return max(0, $resendAvailableAt - time());
    }

    public function canResend(int $resendAvailableAt): bool
    {
        return $this->getResendRemainingSeconds($resendAvailableAt) === 0;
    }

    public function nextResendAvailableAt(): int
    {
        return time() + self::OTP_COOLDOWN_SECONDS;
    }

    private function client()
    {
        return HttpClient::create();
    }

    private function endpoint(string $suffix): string
    {
        return sprintf(
            'https://verify.twilio.com/v2/Services/%s%s',
            $this->requireEnv('TWILIO_VERIFY_SERVICE_SID'),
            $suffix
        );
    }

    private function normalizeChannel(string $channel): string
    {
        $normalized = strtolower(trim($channel));

        return in_array($normalized, ['sms', 'email'], true) ? $normalized : 'email';
    }

    private function normalizeDestination(string $destination, string $channel): string
    {
        $value = trim($destination);

        if ($this->normalizeChannel($channel) === 'sms') {
            if ($value === '') {
                throw new \RuntimeException('Numéro de téléphone vide pour l’envoi SMS.');
            }

            if (str_starts_with($value, '+')) {
                return $value;
            }

            $digits = preg_replace('/\D+/', '', $value) ?? '';
            if ($digits === '') {
                throw new \RuntimeException('Numéro de téléphone invalide pour l’envoi SMS.');
            }

            $countryCode = trim((string) $this->requireEnv('TWILIO_DEFAULT_COUNTRY_CODE'));
            if ($countryCode === '') {
                throw new \RuntimeException('TWILIO_DEFAULT_COUNTRY_CODE est vide.');
            }

            $digits = ltrim($digits, '0');

            return $countryCode . $digits;
        }

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Adresse e-mail invalide pour l’envoi OTP.');
        }

        return $value;
    }

    private function assertSuccess(int $statusCode, string $content): void
    {
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('Twilio Verify error: ' . $content);
        }
    }

    private function requireEnv(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException(sprintf('La variable d\'environnement %s est manquante.', $name));
        }

        return trim($value);
    }
}