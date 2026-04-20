<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class GoogleTokenSessionService
{
    private const KEY_LINKED = 'google_linked';
    private const KEY_EMAIL = 'google_email';
    private const KEY_ACCESS_TOKEN = 'google_access_token';
    private const KEY_REFRESH_TOKEN = 'google_refresh_token';
    private const KEY_EXPIRES_AT = 'google_expires_at';

    public function __construct(private readonly RequestStack $requestStack){}

    public function isLinked(): bool
    {
        $session = $this->getSession();

        return $session->get(self::KEY_LINKED) === true
            && is_string($session->get(self::KEY_ACCESS_TOKEN))
            && $session->get(self::KEY_ACCESS_TOKEN) !== '';
    }

    public function getAccessToken(): ?string
    {
        $session = $this->getSession();
        $token = $session->get(self::KEY_ACCESS_TOKEN);

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function requireAccessToken(): string
    {
        $token = $this->getAccessToken();
        if ($token === null) {
            throw new \RuntimeException('Google account is not linked in the current session.');
        }

        return $token;
    }

    public function getGoogleEmail(): ?string
    {
        $session = $this->getSession();
        $email = $session->get(self::KEY_EMAIL);

        return is_string($email) && $email !== '' ? $email : null;
    }

    public function getExpiresAt(): ?int
    {
        $session = $this->getSession();
        $expiresAt = $session->get(self::KEY_EXPIRES_AT);

        return is_int($expiresAt) ? $expiresAt : null;
    }

    public function getRefreshToken(): ?string
    {
        $session = $this->getSession();
        $refreshToken = $session->get(self::KEY_REFRESH_TOKEN);

        return is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null;
    }

    private function getSession(): SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            throw new \RuntimeException('Session is not available for Google token access.');
        }

        return $request->getSession();
    }
}
