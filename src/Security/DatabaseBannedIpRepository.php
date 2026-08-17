<?php

declare(strict_types=1);

namespace W3a\Core\Security;

use W3a\Core\Contracts\BannedIpRepositoryInterface;
use W3a\Core\Contracts\DatabaseInterface;

/**
 * Реализация BannedIpRepositoryInterface на базе таблицы banned_ips.
 */
class DatabaseBannedIpRepository implements BannedIpRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db
    ) {
    }

    public function getBanReason(string $ip): ?string
    {
        $row = $this->db->fetchOne(
            "SELECT `reason` FROM `banned_ips` WHERE `ip_address` = :ip LIMIT 1",
            ['ip' => $ip]
        );

        return $row !== null ? (string)($row['reason'] ?? '') : null;
    }
}
