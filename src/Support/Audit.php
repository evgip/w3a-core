<?php

declare(strict_types=1);

namespace W3a\Core\Support;

use W3a\Core\Contracts\AuditStorageInterface;

use W3a\Core\Http\Session;
use W3a\Core\Security\IpResolver;

/**
 * Сервис аудита для записи действий в журнал.
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

    public function log(
        string $action,
        string $description,
        string $category = 'general',
        array $payload = []
    ): void {
        if ($this->isLogging) {
            return;
        }
        $this->isLogging = true;

        try {
            $userId    = (int)$this->session->get('user_id', 0);
            $username  = $this->session->get('user_name', 'Guest');
            $role      = $this->session->get('user_role', 'guest');
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