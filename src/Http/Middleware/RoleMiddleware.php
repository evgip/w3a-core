<?php

declare(strict_types=1);

namespace W3a\Core\Http\Middleware;

use W3a\Core\Foundation\Container;
use W3a\Core\Contracts\UserIdProviderInterface;
use W3a\Core\Http\RedirectResponse;
use W3a\Core\Http\Session;

/**
 * Абстрактный базовый класс для проверки ролей с иерархией.
 * Конкретные роли и иерархия определяются в дочерних классах.
 */
abstract class RoleMiddleware implements MiddlewareInterface
{
    protected string $requiredRole;

    /**
     * Иерархия ролей: порядок в массиве = возрастание уровня доступа.
     * Переопределяется в дочерних классах для конкретных проектов.
     */
    protected array $hierarchy = ['guest', 'user', 'moderator', 'admin'];

    public function __construct(
        protected readonly Container $container,
        protected readonly Session $session,
        protected readonly UserIdProviderInterface $userIdProvider
    ) {}

    protected function roleLevel(string $role): int
    {
        $pos = array_search($role, $this->hierarchy, true);
        return $pos === false ? -1 : $pos;
    }

    protected function hasAccess(string $userRole): bool
    {
        return $this->roleLevel($userRole) >= $this->roleLevel($this->requiredRole);
    }

    public function handle(callable $next): mixed
    {
        $userId = $this->userIdProvider->getUserId();

        if ($userId === null || (int)$userId <= 0) {
            $this->session->flash('error', 'Необходима авторизация');
            return new RedirectResponse('/login');
        }

        $userRole = $this->session->get('user_role', '');

        if (!$this->hasAccess($userRole)) {
            $this->session->flash('error', 'У вас недостаточно прав для доступа к этой странице.');
            return new RedirectResponse('/');
        }

        return $next();
    }
}