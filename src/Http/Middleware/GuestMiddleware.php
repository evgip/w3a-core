<?php

declare(strict_types=1);

namespace W3a\Core\Http\Middleware;

use W3a\Core\Http\Middleware\MiddlewareInterface;
use W3a\Core\Contracts\UserIdProviderInterface;
use W3a\Core\Exceptions\RedirectException;

/**
 * Middleware для гостей (неавторизованных пользователей).
 * Если пользователь авторизован, перенаправляем его на главную страницу.
 */
class GuestMiddleware implements MiddlewareInterface
{
    /**
     * Нам нужен только UserIdProvider. 
     * Зависимость Session убрана, так как она здесь не используется (принцип чистой архитектуры).
     */
    public function __construct(
        private readonly UserIdProviderInterface $userIdProvider
    ) {}

    public function handle(callable $next): mixed
    {
        $userId = $this->userIdProvider->getUserId();

        // Если пользователь авторизован (ID > 0), прерываем выполнение
        if ($userId !== null && (int)$userId > 0) {
            
            $currentUri = $_SERVER['REQUEST_URI'] ?? '/';
            
            // Делаем редирект ТОЛЬКО если пользователь НЕ находится на главной странице.
            // Это предотвращает бесконечный цикл редиректов (ERR_TOO_MANY_REDIRECTS).
            if ($currentUri !== '/' && $currentUri !== '/index.php') {
                throw new RedirectException('/');
            }
        }
        
        // Иначе продолжаем выполнение цепочки middleware
        return $next();
    }
}