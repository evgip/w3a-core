<?php

declare(strict_types=1);

namespace W3a\Core\Contracts;

/**
 * Интерфейс для проверки заблокированных IP-адресов.
 * Позволяет Firewall работать, не зная о структуре базы данных.
 */
interface BannedIpRepositoryInterface
{
    /**
     * Проверяет, заблокирован ли IP, и возвращает причину.
     *
     * @param string $ip IP-адрес для проверки
     * @return string|null Причина блокировки или null, если IP не заблокирован
     */
    public function getBanReason(string $ip): ?string;
}