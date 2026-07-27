<?php

declare(strict_types=1);

namespace W3a\Core\Middleware;

use W3a\Core\Contracts\UserIdProviderInterface;
use W3a\Core\Exceptions\RedirectException;
use W3a\Core\Session;

/**
 * Middleware для гостей (неавторизованных пользователей).
 * Если пользователь авторизован, перенаправляем его на главную.
 */
class GuestMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly UserIdProviderInterface $userIdProvider
    ) {}

    public function handle(callable $next): mixed
    {
        $userId = $this->userIdProvider->getUserId();

        // Если пользователь авторизован (ID > 0), прерываем выполнение
        if ($userId !== null && (int)$userId > 0) {
            // Можно сохранить intended_url, если нужно, но для простоты редиректим на главную
            throw new RedirectException('/');
        }
        
        // Иначе продолжаем выполнение цепочки
        return $next();
    }
}