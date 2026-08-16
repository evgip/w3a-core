<?php

declare(strict_types=1);

namespace W3a\Core\Auth;

use W3a\Core\Auth\Models\PasswordResetToken;

class PasswordResetService
{
    protected const TOKEN_LIFETIME = 3600; // 1 час

    protected object $userModel;
    protected PasswordResetToken $tokenModel;
    protected object $mailer;

    public function __construct(object $userModel, PasswordResetToken $tokenModel, object $mailer)
    {
        $this->userModel = $userModel;
        $this->tokenModel = $tokenModel;
        $this->mailer = $mailer;
    }

    public function sendResetLink(string $email): bool
    {
        $email = trim($email);
        $user = $this->userModel->findBy('email', $email);

        if (!$user) {
            return true; // Не раскрываем, существует ли email
        }

        $token = $this->tokenModel->createToken($email);
        $resetUrl = $this->getResetUrl($token);
        $this->sendResetEmail($user['email'], $user['username'], $resetUrl);

        return true;
    }

    public function validateToken(string $token): ?array
    {
        $tokenData = $this->tokenModel->findByToken($token);

        if (!$tokenData) return null;

        $createdAt = strtotime($tokenData['created_at']);
        if ((time() - $createdAt) > self::TOKEN_LIFETIME) {
            $this->tokenModel->deleteByToken($token);
            return null;
        }

        $user = $this->userModel->findBy('email', $tokenData['email']);

        if (!$user) {
            $this->tokenModel->deleteByToken($token);
            return null;
        }

        return $user;
    }

    public function resetPassword(string $token, string $newPassword): bool
    {
        $user = $this->validateToken($token);

        if (!$user) return false;

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $success = $this->userModel->update((int)$user['id'], ['password' => $passwordHash]);

        if ($success) {
            $this->tokenModel->deleteByToken($token);
        }

        return $success;
    }

    public function cleanupExpiredTokens(): int
    {
        return $this->tokenModel->cleanupExpired();
    }

    protected function getResetUrl(string $token): string
    {
        return '/password/reset/' . $token;
    }

    protected function sendResetEmail(string $email, string $username, string $resetUrl): void
    {
        // Переопределяется в приложении
    }
}