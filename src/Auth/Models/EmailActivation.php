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
            'token'      => 'token',
        ]);
    }

    /**
     * Создает новую запись с токеном активации для пользователя.
     */
    public function createToken(int $userId, string $token): void
    {
        $data = [
            $this->cols['user_id'] => $userId,
            $this->cols['token']   => $token,
        ];

        $columns = '`' . implode('`, `', array_keys($data)) . '`';
        $placeholders = ':' . implode(', :', array_keys($data));
        $sql = "INSERT INTO `{$this->table}` ({$columns}) VALUES ({$placeholders})";
        
        $this->db->query($sql, $data);
    }

    /**
     * Ищет запись активации по токену.
     */
    public function findByToken(string $token): ?array
    {
        $sql = "SELECT * FROM `{$this->table}` WHERE `{$this->cols['token']}` = ?";
        return $this->db->fetchOne($sql, [$token]);
    }

    /**
     * Удаляет токен после успешной активации или истечения срока его действия.
     */
    public function deleteByToken(string $token): void
    {
        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->cols['token']}` = ?";
        $this->db->execute($sql, [$token]);
    }
}
