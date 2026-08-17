<?php

declare(strict_types=1);

namespace W3a\Core\Security;

use W3a\Core\Contracts\RateLimitStorageInterface;
use W3a\Core\Contracts\DatabaseInterface;

/**
 * Реализация RateLimitStorageInterface на базе MySQL-таблицы rate_limits.
 *
 * Использует атомарный INSERT ... ON DUPLICATE KEY UPDATE с уникальным ключом
 * (identifier, endpoint_action, window_start), поэтому безопасен при конкурентных
 * запросах. Таблица создаётся миграцией database/migrations/rate_limits.sql.
 */
class DatabaseRateLimitStorage implements RateLimitStorageInterface
{
    public function __construct(
        private readonly DatabaseInterface $db
    ) {
    }

    public function incrementAndGet(string $identifier, string $action, int $windowSeconds): int
    {
        if ($windowSeconds <= 0) {
            $windowSeconds = 60;
        }

        $windowStart = date('Y-m-d H:i:s', (int)floor(time() / $windowSeconds) * $windowSeconds);

        $this->db->execute(
            "INSERT INTO `rate_limits` (`identifier`, `endpoint_action`, `window_start`, `request_count`)
             VALUES (:identifier, :action, :window_start, 1)
             ON DUPLICATE KEY UPDATE `request_count` = `request_count` + 1",
            [
                'identifier'   => $identifier,
                'action'       => $action,
                'window_start' => $windowStart,
            ]
        );

        return (int) $this->db->fetchColumn(
            "SELECT `request_count` FROM `rate_limits`
             WHERE `identifier` = :identifier AND `endpoint_action` = :action AND `window_start` = :window_start",
            [
                'identifier'   => $identifier,
                'action'       => $action,
                'window_start' => $windowStart,
            ]
        );
    }
}
