<?php

declare(strict_types=1);

namespace W3a\Core\Support;

use W3a\Core\Contracts\AuditStorageInterface;
use W3a\Core\Http\Session;
use W3a\Core\Security\IpResolver;

/**
 * Сервис аудита для записи действий в журнал.
 * 
 * ЛЕНИВАЯ РАБОТА С СЕССИЕЙ:
 * - Для анонимных действий (GET-запросы) сессия НЕ стартует
 * - Для авторизованных действий читает из сессии только если она уже стартовала
 * - Можно передавать user_id, username, role явно для принудительной идентификации
 */
class Audit
{
    private bool $isLogging = false;

    public function __construct(
        private readonly AuditStorageInterface $storage,
        private readonly Session $session,
        private readonly IpResolver $ipResolver
    ) {
    }

    /**
     * Записать действие в журнал аудита.
     *
     * @param string $action Идентификатор действия (например, 'auth.login')
     * @param string $description Человекочитаемое описание
     * @param string $category Категория действия (auth, admin, stats, etc.)
     * @param array $payload Дополнительные данные (будут JSON-сериализованы)
     * @param int|null $userId ID пользователя (если null — берётся из сессии, если она стартовала)
     * @param string|null $username Имя пользователя (если null — берётся из сессии)
     * @param string|null $role Роль пользователя (если null — берётся из сессии)
     */
    public function log(
        string $action,
        string $description,
        string $category = 'general',
        array $payload = [],
        ?int $userId = null,
        ?string $username = null,
        ?string $role = null
    ): void {
        if ($this->isLogging) {
            return;
        }
        $this->isLogging = true;

        try {
            // Получаем данные пользователя:
            // 1. Если переданы явно — используем их
            // 2. Если не переданы — читаем из сессии, НО ТОЛЬКО если она уже стартовала
            // 3. Если сессия не стартовала — используем значения по умолчанию для гостя
            if ($userId === null || $username === null || $role === null) {
                if ($this->session->isStarted()) {
                    // Сессия уже активна — можно безопасно читать
                    $userId = $userId ?? (int)$this->session->get('user_id', 0);
                    $username = $username ?? $this->session->get('user_name', 'Guest');
                    $role = $role ?? $this->session->get('user_role', 'guest');
                } else {
                    // Сессия не стартовала — это анонимный пользователь
                    // НЕ вызываем session->get(), чтобы не запускать сессию
                    $userId = $userId ?? 0;
                    $username = $username ?? 'Guest';
                    $role = $role ?? 'guest';
                }
            }

            $ipAddress = $this->ipResolver->getClientIp();

            $this->storage->log(
                $userId,
                $username,
                $role,
                $ipAddress,
                $action,
                $description,
                $category,
                !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null
            );
        } finally {
            $this->isLogging = false;
        }
    }

    public function getByCategory(string $category, int $limit = 50, int $offset = 0): array
    {
        return $this->storage->getByCategory($category, $limit, $offset);
    }

    public function getAll(int $limit = 100, int $offset = 0, ?string $category = null): array
    {
        if ($category !== null && $category !== '') {
            return $this->getByCategory($category, $limit, $offset);
        }

        return $this->storage->getAll($limit, $offset);
    }

    public function countByCategory(string $category): int
    {
        return $this->storage->countByCategory($category);
    }

    public function countAll(): int
    {
        return $this->storage->countAll();
    }

    public function cleanup(int $days = 90): int
    {
        return $this->storage->cleanup($days);
    }
}