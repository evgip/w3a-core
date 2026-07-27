<?php

declare(strict_types=1);

namespace W3a\Core\Middleware;

use W3a\Core\Container;
use W3a\Core\Contracts\UserIdProviderInterface;
use W3a\Core\Exceptions\RedirectException;
use W3a\Core\Session;

/**
 * Абстрактный базовый класс для проверки ролей.
 * Конкретные роли определяются в приложении.
 */
abstract class RoleMiddleware implements MiddlewareInterface
{
    /**
     * Требуемая роль. Должна быть переопределена в дочернем классе.
     */
    protected string $requiredRole;

    public function __construct(
        protected readonly Container $container,
        protected readonly Session $session,
        protected readonly UserIdProviderInterface $userIdProvider
    ) {}

    public function handle(callable $next): mixed
    {
        $userId = $this->userIdProvider->getUserId();

        if ($userId === null || (int)$userId <= 0) {
            $this->session->flash('error', 'Необходима авторизация');
            throw new RedirectException('/login');
        }

        // Получаем роль пользователя (например, из сессии или через сервис)
        // В вашем случае это можно взять из сессии, так как она уже обновлена при логине
        $userRole = $this->session->get('user_role', '');

        // Логика проверки: админ всегда проходит, либо роль совпадает
        $hasAccess = ($userRole === 'admin') || ($userRole === $this->requiredRole);

        if (!$hasAccess) {
            $this->session->flash('error', 'У вас недостаточно прав для доступа к этой странице.');
            throw new RedirectException('/'); // Или на страницу 403
        }

        return $next();
    }
}