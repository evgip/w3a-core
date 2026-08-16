<?php

declare(strict_types=1);

namespace W3a\Core\Auth\Models;

use W3a\Core\Database\Database;
use W3a\Core\Foundation\Config;

/**
 * Модель для управления токенами активации email при регистрации.
 */
class EmailActivation
{
    private Database $db;
    private string $table;
    private array $cols;

    public function __construct(Database $db, Config $config)
    {
        $this->db = $db;
        $this->table = $config->get('auth.tables.email_activations', 'email_activations');
        $this->cols = $config->getArray('auth.columns.email_activations', [
            'user_id'    => 'user_id',
            'selector'   => 'selector',
            'token_hash' => 'token_hash',
        ]);
    }

    /**
     * Создает новую запись с токеном активации для пользователя.
     *
     * В базе хранится только SHA-256 хэш валидатора; публичный селектор
     * используется для поиска записи. Возвращает полный токен в формате
     * "selector:validator" для передачи в письме.
     */
    public function createToken(int $userId): string
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));

        $data = [
            $this->cols['user_id']    => $userId,
            $this->cols['selector']   => $selector,
            $this->cols['token_hash'] => hash('sha256', $validator),
        ];

        $columns = '`' . implode('`, `', array_keys($data)) . '`';
        $placeholders = ':' . implode(', :', array_keys($data));
        $sql = "INSERT INTO `{$this->table}` ({$columns}) VALUES ({$placeholders})";
        
        $this->db->query($sql, $data);

        return $selector . ':' . $validator;
    }

    /**
     * Ищет запись активации по полному токену "selector:validator".
     * Сравнение валидатора идёт через hash_equals (защита от timing-атак).
     */
    public function findByToken(string $token): ?array
    {
        $parts = explode(':', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$selector, $validator] = $parts;

        $sql = "SELECT * FROM `{$this->table}` WHERE `{$this->cols['selector']}` = ?";
        $record = $this->db->fetchOne($sql, [$selector]);

        if ($record && hash_equals($record[$this->cols['token_hash']], hash('sha256', $validator))) {
            return $record;
        }

        return null;
    }

    /**
     * Удаляет токен после успешной активации или истечения срока его действия.
     */
    public function deleteByToken(string $token): void
    {
        $parts = explode(':', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            return;
        }

        $this->deleteBySelector($parts[0]);
    }

    /**
     * Удаляет запись активации по публичному селектору.
     */
    public function deleteBySelector(string $selector): void
    {
        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->cols['selector']}` = ?";
        $this->db->execute($sql, [$selector]);
    }
}
