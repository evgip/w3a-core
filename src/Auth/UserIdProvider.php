<?php

declare(strict_types=1);

namespace W3a\Core\Auth;

use W3a\Core\Contracts\UserIdProviderInterface;

class UserIdProvider implements UserIdProviderInterface
{
    public function getUserId(): int|string|null
    {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }
        return null;
    }
}