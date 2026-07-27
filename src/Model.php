<?php

declare(strict_types=1);

namespace W3a\Core;

use InvalidArgumentException;
use RuntimeException;

abstract class Model
{
    // Эти свойства не могут быть readonly, так как есть сеттеры для тестирования
    protected Database $db;
    protected ?Logger $logger;

    protected string $table;
    protected string $primaryKey = 'id';
    protected array $fillable = [];
    protected bool $includeTrashed = false;

    /**
     * Конструктор модели с инъекцией зависимостей.
     * Используем Constructor Property Promotion для чистоты.
     */
    public function __construct(
        Database $db,
        ?Logger $logger = null
    ) {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Цепочный метод для включения мягко удалённых записей в следующий запрос
     */
    public function withTrashed(): self
    {
        $this->includeTrashed = true;
        return $this;
    }

    /**
     * Вспомогательный метод для добавления SQL-фильтра мягкого удаления.
     * 
     * ВАЖНО: Этот метод предназначен для простых запросов базовой модели. 
     * Для сложных запросов с JOIN используйте Репозиторий (Repository Pattern).
     */
    protected function applySoftDeleteConstraint(string $sql): string
    {
        if ($this->includeTrashed) {
            $this->includeTrashed = false; // Сбрасываем флаг после применения
            return $sql;
        }

        return stripos($sql, 'WHERE') !== false 
            ? $sql . " AND `deleted_at` IS NULL" 
            : $sql . " WHERE `deleted_at` IS NULL";
    }

    /**
     * Получить все активные записи из таблицы
     */
    public function all(): array
    {
        $sql = $this->applySoftDeleteConstraint("SELECT * FROM `{$this->table}`");
        return $this->db->fetchAll($sql);
    }

    /**
     * Найти запись по первичному ключу (ID).
     */
    public function find(int|string $id, bool $withTrashed = false): ?array
    {
        if (!is_numeric($id)) {
            throw new InvalidArgumentException("Invalid ID provided.");
        }

        $sql = "SELECT * FROM `{$this->table}` WHERE `{$this->primaryKey}` = :id";
        
        if (!$withTrashed) {
            $sql = $this->applySoftDeleteConstraint($sql);
        }

        return $this->db->fetchOne($sql . " LIMIT 1", ['id' => $id]);
    }

    /**
     * Найти активную запись по конкретному значению колонки
     */
    public function findBy(string $column, mixed $value): ?array
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw new InvalidArgumentException("Invalid column name.");
        }

        $sql = "SELECT * FROM `{$this->table}` WHERE `{$column}` = :value";
        $sql = $this->applySoftDeleteConstraint($sql);

        return $this->db->fetchOne($sql . " LIMIT 1", ['value' => $value]);
    }

    /**
     * Фильтрует входящие данные, оставляя только разрешённые поля (Mass Assignment Protection).
     */
    protected function filterFillable(array $data): array
    {
        if (empty($this->fillable)) {
            throw new RuntimeException("Модель '" . static::class . "' должна определять свойство \$fillable.");
        }

        $allowedKeys = array_flip($this->fillable);
        $filteredData = array_intersect_key($data, $allowedKeys);
        $rejectedKeys = array_diff_key($data, $allowedKeys);

        if (!empty($rejectedKeys) && $this->logger !== null) {
            $this->logger->warning("Mass Assignment Attempt", [
                'model' => static::class,
                'rejected_fields' => array_keys($rejectedKeys),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        }

        return $filteredData;
    }

    /**
     * Создать новую запись в базе данных
     */
    public function create(array $data): int
    {
        $data = $this->filterFillable($data);

        if (empty($data)) {
            throw new InvalidArgumentException("Нет разрешённых полей для создания записи.");
        }

        $columns = '`' . implode('`, `', array_keys($data)) . '`';
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO `{$this->table}` ({$columns}) VALUES ({$placeholders})";
        $this->db->query($sql, $data);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Обновить существующую запись в базе данных
     */
    public function update(int|string $id, array $data): bool
    {
        if (!is_numeric($id)) {
            throw new InvalidArgumentException("Invalid ID provided.");
        }

        $data = $this->filterFillable($data);

        if (empty($data)) {
            throw new InvalidArgumentException("Нет разрешённых полей для обновления записи.");
        }

        $fields = [];
        foreach (array_keys($data) as $key) {
            $fields[] = "`{$key}` = :{$key}";
        }

        $data['_id'] = $id;
        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $fields) . " WHERE `{$this->primaryKey}` = :_id";

        return $this->db->execute($sql, $data) > 0;
    }

    /**
     * SOFT DELETE: Помечает запись как удалённую
     */
    public function delete(int|string $id): bool
    {
        if (!is_numeric($id)) {
            throw new InvalidArgumentException("Invalid ID provided.");
        }
        return $this->update($id, ['deleted_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * RESTORE: Отменяет мягкое удаление
     */
    public function restore(int|string $id): bool
    {
        return $this->update($id, ['deleted_at' => null]);
    }

    /**
     * FORCE DELETE: Полное структурное уничтожение записи
     */
    public function forceDelete(int|string $id): bool
    {
        if (!is_numeric($id)) {
            throw new InvalidArgumentException("Invalid ID provided.");
        }

        if ($this->logger !== null) {
            $this->logger->warning('Model force delete', [
                'table' => $this->table,
                'record_id' => $id,
                'model' => static::class,
            ]);
        }

        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = :id";
        return $this->db->execute($sql, ['id' => $id]) > 0;
    }

    /**
     * Получить количество записей с опциональным условием
     */
    public function count(string $where = '', array $params = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM `{$this->table}`";

        if (!empty($where)) {
            $sql .= " WHERE " . $where;
        }

        $sql = $this->applySoftDeleteConstraint($sql);
        $result = $this->db->fetchOne($sql, $params);

        return (int)($result['count'] ?? 0);
    }

    /**
     * Найти записи с опциональными условиями, сортировкой и лимитом
     */
    public function where(
        string $where,
        array $params = [],
        string $orderBy = '',
        ?int $limit = null,
        ?int $offset = null
    ): array {
        $sql = "SELECT * FROM `{$this->table}` WHERE " . $where;
        $sql = $this->applySoftDeleteConstraint($sql);

        if (!empty($orderBy)) {
            // ⚠️ ВАЖНО: $orderBy должен формироваться только доверенным кодом, а не пользовательским вводом!
            $sql .= " ORDER BY " . $orderBy;
        }

        if ($limit !== null) {
            $sql .= " LIMIT " . (int)$limit;
        }

        if ($offset !== null) {
            $sql .= " OFFSET " . (int)$offset;
        }

        return $this->db->fetchAll($sql, $params);
    }

    // === Методы для внедрения зависимостей (в основном для Unit-тестов) ===

    public function getDatabase(): Database
    {
        return $this->db;
    }

    public function setDatabase(Database $db): void
    {
        $this->db = $db;
    }

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }
}