<?php

declare(strict_types=1);

namespace W3a\Core\Contracts;

interface RateLimitStorageInterface
{
    /**
     * Атомарно увеличивает счётчик запросов и возвращает текущее количество.
     */
    public function incrementAndGet(string $identifier, string $action, int $windowSeconds): int;
}