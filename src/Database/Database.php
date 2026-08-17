<?php

declare(strict_types=1);

namespace W3a\Core\Database;

use W3a\Core\Contracts\DatabaseInterface;
use PDO;
use PDOException;
use RuntimeException;

use W3a\Core\Support\Logger;

class Database implements DatabaseInterface
{
    private ?PDO $connection = null;
    private array $config;
    private ?Logger $logger;

    /** @var array<string, \PDOStatement> Кэш подготовленных выражений (по SQL) */
    private array $statementCache = [];

    /** Максимальный размер кэша statement'ов (защита от неограниченного роста) */
    private const STATEMENT_CACHE_LIMIT = 100;

    // Счётчики запросов — теперь per-instance:
    // в PHP-FPM каждый запрос создаёт новый Database (счётчик с нуля),
    // а статический API ниже делегирует к последнему созданному экземпляру.
    private int $queryCount = 0;
    private array $queryLog = [];
    private bool $enableQueryLog = false;

    /** @var self|null Последний созданный экземпляр (для статического API Benchmark) */
    private static ?self $lastInstance = null;

    public function __construct(array $config, ?Logger $logger = null)
    {
        if (empty($config)) {
            throw new \InvalidArgumentException('Database config cannot be empty');
        }
        
        $this->config = $config;
        $this->logger = $logger;
        self::$lastInstance = $this;
    }

    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            $this->connection = $this->createConnection();

            // PDOStatement привязан к конкретному соединению:
            // при (пере)создании соединения кэш statement'ов сбрасываем.
            $this->statementCache = [];
        }
        return $this->connection;
    }

    public function pdo(): PDO
    {
        return $this->getConnection();
    }

    private function createConnection(): PDO
    {
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=%s",
            $this->config['host'] ?? 'localhost',
            $this->config['port'] ?? '3306',
            $this->config['dbname'] ?? '',
            $this->config['charset'] ?? 'utf8mb4'
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // sql_mode задаётся при инициализации соединения (один раз), а не отдельным exec()
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
        ];

        try {
            $pdo = new PDO(
                $dsn,
                $this->config['username'] ?? 'root',
                $this->config['password'] ?? '',
                $options
            );

            return $pdo;
        } catch (PDOException $e) {
            if ($this->logger) {
                $this->logger->error("Сбой подключения к БД: " . $e->getMessage());
            }
            throw new RuntimeException("Database connection failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Низкоуровневый запрос: prepare + execute, возвращает живой PDOStatement.
     *
     * НЕ использует кэш statement'ов, потому что возвращаемый курсор может
     * потребляться вызывающим кодом асинхронно (в циклах). Повторное использование
     * одного PDOStatement для двух параллельных fetch сломалось бы.
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $this->recordQuery($sql, $params);

        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->recordQuery($sql, $params);

        $stmt = $this->prepareCached($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function lastInsertId(): string
    {
        return $this->getConnection()->lastInsertId();
    }

    public function beginTransaction(): bool
    {
        return $this->getConnection()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->getConnection()->commit();
    }

    public function rollBack(): bool
    {
        return $this->getConnection()->rollBack();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $this->recordQuery($sql, $params);

        $stmt = $this->prepareCached($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result === false ? null : $result;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->recordQuery($sql, $params);

        $stmt = $this->prepareCached($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        $this->recordQuery($sql, $params);

        $stmt = $this->prepareCached($sql);
        $stmt->execute($params);
        $result = $stmt->fetchColumn($column);
        return $result === false ? null : $result;
    }

    public function prepare(string $sql): \PDOStatement
    {
        return $this->getConnection()->prepare($sql);
    }

    /**
     * Подготовка выражения с кэшированием.
     *
     * Используется ТОЛЬКО в методах, которые полностью потребляют результат
     * синхронно (fetchOne/fetchAll/fetchColumn/execute) — поэтому один и тот же
     * statement безопасно переиспользовать для повторных выполнений.
     */
    private function prepareCached(string $sql): \PDOStatement
    {
        if (isset($this->statementCache[$sql])) {
            return $this->statementCache[$sql];
        }

        $stmt = $this->getConnection()->prepare($sql);

        // FIFO-вытеснение при превышении лимита, чтобы кэш не рос бесконечно
        if (count($this->statementCache) >= self::STATEMENT_CACHE_LIMIT) {
            array_shift($this->statementCache);
        }
        $this->statementCache[$sql] = $stmt;

        return $stmt;
    }

    /**
     * Учёт запроса: инкремент счётчика и (опционально) запись в лог.
     */
    private function recordQuery(string $sql, array $params = []): void
    {
        $this->queryCount++;

        if ($this->enableQueryLog) {
            $this->queryLog[] = [
                'sql' => $sql,
                'params' => $params,
                'time' => microtime(true),
            ];
        }
    }

    // =========================================================================
    // Методы для работы со счётчиком запросов (статический API для Benchmark)
    // =========================================================================

    /**
     * Получить количество выполненных SQL запросов
     */
    public static function getQueryCount(): int
    {
        return self::$lastInstance?->queryCount ?? 0;
    }

    /**
     * Сбросить счётчик запросов
     */
    public static function resetQueryCount(): void
    {
        if (self::$lastInstance !== null) {
            self::$lastInstance->queryCount = 0;
            self::$lastInstance->queryLog = [];
        }
    }

    /**
     * Включить логирование всех SQL запросов (для отладки)
     */
    public static function enableQueryLog(bool $enable = true): void
    {
        if (self::$lastInstance !== null) {
            self::$lastInstance->enableQueryLog = $enable;
        }
    }

    /**
     * Получить лог всех SQL запросов (если логирование включено)
     */
    public static function getQueryLog(): array
    {
        return self::$lastInstance?->queryLog ?? [];
    }

    /**
     * Получить детальную информацию о запросах
     */
    public static function getQueryStats(): array
    {
        $instance = self::$lastInstance;

        return [
            'count' => $instance?->queryCount ?? 0,
            'log' => $instance?->queryLog ?? [],
            'log_enabled' => $instance?->enableQueryLog ?? false,
        ];
    }

    /**
     * Генерирует плейсхолдеры и биндинги для конструкции IN (...)
     * Избавляет от необходимости писать циклы foreach в моделях.
     * 
     * @param array $values Массив значений
     * @param string $prefix Префикс для именованных плейсхолдеров
     * @return array{clause: string, bindings: array}
     */
    public function buildInClause(array $values, string $prefix = 'param'): array
    {
        $placeholders = [];
        $bindings = [];
        foreach ($values as $index => $value) {
            $key = ':' . $prefix . '_' . $index;
            $placeholders[] = $key;
            $bindings[$key] = $value;
        }
        return [
            'clause' => implode(',', $placeholders),
            'bindings' => $bindings,
        ];
    }
}
