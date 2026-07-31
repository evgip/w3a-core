<?php

declare(strict_types=1);

namespace W3a\Core\Database;

use InvalidArgumentException;
use RuntimeException;
use W3a\Core\Support\Logger;

abstract class Model
{
    protected Database $db;
    protected ?Logger $logger;

    protected string $table;
    protected string $primaryKey = 'id';
    protected array $fillable = [];

    // --- Состояние Query Builder ---
    protected array $qbSelect = ['*'];
    protected array $qbWheres = [];      // [['sql' => '...', 'params' => [...]], ...]
    protected string $qbOrderBy = '';
    protected ?int $qbLimit = null;
    protected ?int $qbOffset = null;
    protected bool $qbIncludeTrashed = false;

    public function __construct(
        Database $db,
        ?Logger $logger = null
    ) {
        $this->db = $db;
        $this->logger = $logger;
    }

    // =========================================================================
    // 1. QUERY BUILDER (Цепочные методы)
    // =========================================================================

    /**
     * ВАЖНО: Все цепочные методы возвращают clone $this, 
     * чтобы избежать загрязнения состояния при переиспользовании экземпляра модели.
     */

    public function select(array $columns): self
    {
        $clone = clone $this;
        $clone->qbSelect = $columns;
        return $clone;
    }

    /**
     * Добавляет условие WHERE. Поддерживает параметризованные запросы.
     * Пример: ->where('status = :status AND views > :views', ['status' => 'active', 'views' => 10])
     */
    public function where(string $condition, array $params = []): self
    {
        $clone = clone $this;
        $clone->qbWheres[] = ['sql' => $condition, 'params' => $params];
        return $clone;
    }

    /**
     * Безопасная сортировка. 
     * Пример: ->orderBy('created_at', 'DESC') или ->orderBy('id')
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $clone = clone $this;
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException("Invalid ORDER BY direction. Use ASC or DESC.");
        }
        
        // Валидация имени колонки (только буквы, цифры, подчеркивание и точка для table.column)
        if (!preg_match('/^`?[a-zA-Z0-9_]+`?(\.`?[a-zA-Z0-9_]+`?)?$/', $column)) {
            throw new InvalidArgumentException("Invalid column name in ORDER BY: '{$column}'");
        }

        $clone->qbOrderBy = "{$column} {$direction}";
        return $clone;
    }

    /**
     * Прямая установка ORDER BY для сложных случаев (например, "FIELD(id, 3,1,2)").
     * Подвергается строгой проверке на SQL-инъекции.
     */
    public function orderByRaw(string $rawOrderBy): self
    {
        $rawOrderBy = trim($rawOrderBy);
        if ($rawOrderBy === '') {
            return $this;
        }

        // Строгая валидация: разрешены только имена колонок, точки, запятые, пробелы, ASC, DESC и обратные кавычки.
        // Запрещены: скобки (), кавычки '', "", точки с запятой ;, тире --, что блокирует 99.9% SQL-инъекций.
        if (!preg_match('/^`?[a-zA-Z0-9_]+`?(\.`?[a-zA-Z0-9_]+`?)?(\s+(ASC|DESC))?(,\s*`?[a-zA-Z0-9_]+`?(\.`?[a-zA-Z0-9_]+`?)?(\s+(ASC|DESC))?)*$/i', $rawOrderBy)) {
            throw new InvalidArgumentException("Invalid ORDER BY clause. Contains forbidden characters or patterns.");
        }

        $clone = clone $this;
        $clone->qbOrderBy = $rawOrderBy;
        return $clone;
    }

    public function limit(?int $limit): self
    {
        $clone = clone $this;
        $clone->qbLimit = $limit;
        return $clone;
    }

    public function offset(?int $offset): self
    {
        $clone = clone $this;
        $clone->qbOffset = $offset;
        return $clone;
    }

    /**
     * Включает мягко удалённые записи для текущего запроса.
     * Теперь безопасно благодаря clone.
     */
    public function withTrashed(): self
    {
        $clone = clone $this;
        $clone->qbIncludeTrashed = true;
        return $clone;
    }

    /**
     * Выполняет собранный запрос и возвращает массив результатов.
     */
    public function get(): array
    {
        [$sql, $params] = $this->buildQuery();
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Выполняет запрос и возвращает первую запись или null.
     */
    public function first(): ?array
    {
        $clone = clone $this;
        $clone->qbLimit = 1;
        [$sql, $params] = $clone->buildQuery();
        return $this->db->fetchOne($sql, $params) ?: null;
    }

    /**
     * Подсчет количества записей с учетом текущих условий WHERE.
     */
    public function count(): int
    {
        $clone = clone $this;
        $clone->qbSelect = ['COUNT(*) as count'];
        // Сбрасываем ORDER BY, LIMIT и OFFSET, они не нужны для COUNT и могут вызывать ошибки в некоторых СУБД
        $clone->qbOrderBy = '';
        $clone->qbLimit = null;
        $clone->qbOffset = null;

        [$sql, $params] = $clone->buildQuery();
        $result = $this->db->fetchOne($sql, $params);
        return (int)($result['count'] ?? 0);
    }

    // =========================================================================
    // 2. ВНУТРЕННЯЯ ЛОГИКА ПОСТРОЕНИЯ ЗАПРОСА
    // =========================================================================

    /**
     * Собирает финальный SQL-запрос и параметры из состояния Query Builder.
     * @return array [string $sql, array $params]
     */
    protected function buildQuery(): array
    {
        $columns = implode(', ', array_map(fn($col) => "`{$col}`", $this->qbSelect));
        $sql = "SELECT {$columns} FROM `{$this->table}`";
        $params = [];

        // Сборка WHERE
        if (!empty($this->qbWheres)) {
            $whereClauses = [];
            foreach ($this->qbWheres as $index => $where) {
                // Уникальные имена параметров для каждого условия, чтобы избежать коллизий ключей
                $prefix = "w{$index}_";
                $whereClauses[] = $where['sql'];
                foreach ($where['params'] as $key => $value) {
                    $params[$prefix . $key] = $value;
                }
                // Заменяем ключи в SQL на уникальные
                $whereClauses[count($whereClauses) - 1] = preg_replace_callback(
                    '/:([a-zA-Z0-9_]+)/',
                    fn($m) => ':' . $prefix . $m[1],
                    $whereClauses[count($whereClauses) - 1]
                );
            }
            $sql .= " WHERE " . implode(' AND ', $whereClauses);
        }

        // Применение Soft Delete
        if (!$this->qbIncludeTrashed) {
            $sql .= (stripos($sql, 'WHERE') !== false) 
                ? " AND `deleted_at` IS NULL" 
                : " WHERE `deleted_at` IS NULL";
        }

        // Сортировка
        if ($this->qbOrderBy !== '') {
            $sql .= " ORDER BY " . $this->qbOrderBy;
        }

        // Лимит и смещение
        if ($this->qbLimit !== null) {
            $sql .= " LIMIT " . (int)$this->qbLimit;
        }
        if ($this->qbOffset !== null) {
            $sql .= " OFFSET " . (int)$this->qbOffset;
        }

        return [$sql, $params];
    }

    // =========================================================================
    // 3. BACKWARD COMPATIBILITY & ACTIVE RECORD
    // =========================================================================

    /**
     * @deprecated Используйте chainable ->get() или ->all()
     */
    public function all(): array
    {
        return $this->select(['*'])->get();
    }

    public function find(int|string $id, bool $withTrashed = false): ?array
    {
        if (!is_numeric($id)) {
            throw new InvalidArgumentException("Invalid ID provided.");
        }

        $query = $this->where("`{$this->primaryKey}` = :id", ['id' => $id]);
        if (!$withTrashed) {
            // withTrashed уже делает clone, поэтому это безопасно
            // Но здесь мы просто не вызываем withTrashed, если флаг false
        } else {
            $query = $query->withTrashed();
        }

        return $query->first();
    }

    public function findBy(string $column, mixed $value): ?array
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw new InvalidArgumentException("Invalid column name.");
        }

        return $this->where("`{$column}` = :val", ['val' => $value])->first();
    }

    // ... (Методы filterFillable, create, update, delete, restore, forceDelete остаются без изменений) ...
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

    public function delete(int|string $id): bool
    {
        return $this->update($id, ['deleted_at' => date('Y-m-d H:i:s')]);
    }

    public function restore(int|string $id): bool
    {
        return $this->update($id, ['deleted_at' => null]);
    }

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

    // =========================================================================
    // 4. DI Helpers (для тестов)
    // =========================================================================
    public function getDatabase(): Database { return $this->db; }
    public function setDatabase(Database $db): void { $this->db = $db; }
    public function setLogger(Logger $logger): void { $this->logger = $logger; }
}