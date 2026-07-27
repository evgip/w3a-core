<?php

declare(strict_types=1);

namespace W3a\Core\Contracts;

/**
 * Интерфейс для хранения записей аудита.
 * Позволяет Audit работать, не зная о конкретной таблице или хранилище.
 */
interface AuditStorageInterface
{
    /**
     * Записать действие в журнал аудита.
     *
     * @param int $userId ID пользователя (0 для гостя)
     * @param string $username Имя пользователя
     * @param string $role Роль пользователя
     * @param string $ipAddress IP-адрес
     * @param string $action Название действия
     * @param string $description Описание действия
     * @param string $category Категория действия
     * @param string|null $payload JSON-строка с дополнительными данными
     */
    public function log(
        int $userId,
        string $username,
        string $role,
        string $ipAddress,
        string $action,
        string $description,
        string $category,
        ?string $payload
    ): void;

    /**
     * Получить записи по категории.
     */
    public function getByCategory(string $category, int $limit = 50, int $offset = 0): array;

    /**
     * Получить все записи.
     */
    public function getAll(int $limit = 100, int $offset = 0): array;

    /**
     * Подсчитать записи по категории.
     */
    public function countByCategory(string $category): int;

    /**
     * Подсчитать все записи.
     */
    public function countAll(): int;

    /**
     * Удалить старые записи.
     */
    public function cleanup(int $days = 90): int;
}