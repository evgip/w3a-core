<?php

declare(strict_types=1);

namespace W3a\Core\Auth\Models;

use W3a\Core\Database\Database;
use W3a\Core\Foundation\Config;

/**
 * Модель для управления токенами восстановления пароля.
 */
class PasswordResetToken
{
    private Database $db;
    private string $table;
    private array $cols;

    public function __construct(Database $db, Config $config)
    {
        $this->db = $db;
        $this->table = $config->get('auth.tables.password_resets', 'password_resets');
        $this->cols = $config->getArray('auth.columns.password_resets', [
            'email'      => 'email',
            'token'      => 'token',
            'created_at' => 'created_at',
        ]);
    }

    /**
     * Создает новую запись с токеном восстановления для указанного email.
     */
    public function createToken(string $email, string $token): void
    {
        $data = [
            $this->cols['email'] => $email,
            $this->cols['token'] => $token,
        ];

        $columns = '`' . implode('`, `', array_keys($data)) . '`';
        $placeholders = ':' . implode(', :', array_keys($data));
        $sql = "INSERT INTO `{$this->table}` ({$columns}) VALUES ({$placeholders})";
        
        $this->db->query($sql, $data);
    }

    /**
     * Ищет запись восстановления пароля по токену.
     */
    public function findByToken(string $token): ?array
    {
        $sql = "SELECT * FROM `{$this->table}` WHERE `{$this->cols['token']}` = ?";
        return $this->db->fetchOne($sql, [$token]);
    }

    /**
     * Удаляет токен после успешной смены пароля.
     */
    public function deleteByToken(string $token): void
    {
        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->cols['token']}` = ?";
        $this->db->execute($sql, [$token]);
    }

    /**
     * Очищает устаревшие токены восстановления (старше 1 часа).
     * Может вызываться по расписанию или при каждом запросе с низкой вероятностью.
     *
     * @return int Количество удаленных записей
     */
    public function cleanupExpired(): int
    {
        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->cols['created_at']}` < NOW() - INTERVAL 1 HOUR";
        return $this->db->execute($sql);
    }
}