<?php

declare(strict_types=1);

namespace W3a\Core\Contracts;

/**
 * Интерфейс для получения идентификатора текущего пользователя.
 * Позволяет RateLimiter работать, не зная о конкретном модуле Auth.
 */
interface UserIdProviderInterface
{
    /**
     * Возвращает ID текущего пользователя или null, если гость.
     * 
     * @return int|string|null
     */
    public function getUserId(): int|string|null;
}