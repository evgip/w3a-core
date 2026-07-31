<?php

declare(strict_types=1);

namespace W3a\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use InvalidArgumentException;
use W3a\Core\Database\Model;
use W3a\Core\Contracts\DatabaseInterface;

// ============================================================================
// 1. Тестовая реализация DatabaseInterface для SQLite in-memory
// (Мы не меняем ваш реальный класс Database, а создаем тестовую версию)
// ============================================================================
class TestDb implements DatabaseInterface
{
    private PDO $pdo;

    public function __construct()
    {
        // Создаем базу данных прямо в оперативной памяти
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    public function lastInsertId(): string
    {
        return (string) $this->pdo->lastInsertId();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result === false ? null : $result;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    // Заглушки для методов, которые есть в интерфейсе, но не используются в базовом Model
    public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollBack(): bool { return $this->pdo->rollBack(); }
    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed 
    { 
        return $this->query($sql, $params)->fetchColumn($column); 
    }
    public function prepare(string $sql): \PDOStatement { return $this->pdo->prepare($sql); }
    public function buildInClause(array $values, string $prefix = 'param'): array 
    { 
        // Упрощенная версия для тестов, если понадобится
        $placeholders = []; $bindings = [];
        foreach ($values as $index => $value) {
            $key = ':' . $prefix . '_' . $index;
            $placeholders[] = $key;
            $bindings[$key] = $value;
        }
        return ['clause' => implode(',', $placeholders), 'bindings' => $bindings];
    }
}

// ============================================================================
// 2. Тестовая модель
// ============================================================================
class TestArticle extends Model
{
    protected string $table = 'articles';
    protected string $primaryKey = 'id';
    protected array $fillable = ['title', 'status'];
}

// ============================================================================
// 3. Сам тест
// ============================================================================
class ModelTest extends TestCase
{
    private TestArticle $model;
    private TestDb $db;

    protected function setUp(): void
    {
        // 1. Инициализируем тестовую БД
        $this->db = new TestDb();

        // 2. Создаем таблицу и тестовые данные
        $this->db->getConnection()->exec("
            CREATE TABLE articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                status TEXT,
                deleted_at DATETIME DEFAULT NULL
            )
        ");

        $this->db->getConnection()->exec("INSERT INTO articles (title, status, deleted_at) VALUES ('Статья 1', 'active', NULL)");
        $this->db->getConnection()->exec("INSERT INTO articles (title, status, deleted_at) VALUES ('Статья 2', 'draft', NULL)");
        $this->db->getConnection()->exec("INSERT INTO articles (title, status, deleted_at) VALUES ('Статья 3', 'active', '2023-01-01 12:00:00')"); // Мягко удалена

        // 3. Создаем экземпляр модели с нашей тестовой БД
        $this->model = new TestArticle($this->db, null);
    }

    /**
     * Тест 1: Базовая выборка (get) и фильтрация (where)
     */
    public function test_where_and_get_return_correct_results(): void
    {
        $results = $this->model
            ->where('status = :status', ['status' => 'active'])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Статья 1', $results[0]['title']);
    }

    /**
     * Тест 2: Сортировка (orderBy) и лимит (limit)
     */
    public function test_order_by_and_limit_work_correctly(): void
    {
        $results = $this->model
            ->withTrashed() // Берем все, включая удаленные (всего 3 записи)
            ->orderBy('id', 'DESC')
            ->limit(2)
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals('Статья 3', $results[0]['title']); // ID 3 идет первым из-за DESC
        $this->assertEquals('Статья 2', $results[1]['title']); // ID 2 идет вторым
    }

    /**
     * Тест 3: Мягкое удаление (Soft Deletes) работает корректно
     */
    public function test_soft_deletes_are_excluded_by_default(): void
    {
        // По умолчанию удаленные записи НЕ должны возвращаться
        $resultsDefault = $this->model->where('status = :status', ['status' => 'active'])->get();
        $this->assertCount(1, $resultsDefault);

        // С флагом withTrashed удаленные записи ДОЛЖНЫ возвращаться
        $resultsWithTrashed = $this->model
            ->withTrashed()
            ->where('status = :status', ['status' => 'active'])
            ->get();
            
        $this->assertCount(2, $resultsWithTrashed);
        
        $deletedTitles = array_column($resultsWithTrashed, 'title');
        $this->assertContains('Статья 3', $deletedTitles);
    }

    /**
     * Тест 4: Защита от SQL-инъекций в orderByRaw
     */
    public function test_order_by_raw_rejects_sql_injection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ORDER BY clause');

        // Пытаемся передать вредоносный код
        $this->model->orderByRaw('id; DROP TABLE articles; --')->get();
    }

    /**
     * Тест 5: Изоляция состояния (проверка clone $this)
     */
    public function test_query_builder_state_isolation(): void
    {
        // Создаем два независимых запроса от одной базовой модели
        $query1 = $this->model->where('status = :s1', ['s1' => 'active']);
        $query2 = $this->model->where('status = :s2', ['s2' => 'draft']);

        $results1 = $query1->get();
        $results2 = $query2->get();

        $this->assertCount(1, $results1);
        $this->assertEquals('Статья 1', $results1[0]['title']);

        $this->assertCount(1, $results2);
        $this->assertEquals('Статья 2', $results2[0]['title']);
    }
}