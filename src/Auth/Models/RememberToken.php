<?php

declare(strict_types=1);

namespace W3a\Core\Auth\Models;

use W3a\Core\Database\Database;
use W3a\Core\Foundation\Config;

/**
 * Модель для работы с токенами "Запомнить меня".
 * 
 * Реализует безопасную архитектуру с разделением на публичный селектор (selector)
 * и хешированный валидатор (hashed_validator) для защиты от утечек базы данных.
 */
class RememberToken
{
    private Database $db;
    private string $table;
    private array $cols;

    public function __construct(Database $db, Config $config)
    {
        $this->db = $db;
        $this->table = $config->get('auth.tables.remember_tokens', 'remember_tokens');
        $this->cols = $config->getArray('auth.columns.remember_tokens', [
            'user_id'          => 'user_id',
            'selector'         => 'selector',
            'hashed_validator' => 'hashed_validator',
            'user_agent'       => 'user_agent',
            'ip_address'       => 'ip_address',
            'expires_at'       => 'expires_at',
        ]);
    }

    /**
     * Создает новую пару селектор-валидатор и сохраняет её в базе данных.
     *
     * @param int $userId ID пользователя
     * @param int $days Срок действия токена в днях
     * @param string $userAgent User-Agent клиента
     * @param string $ip IP-адрес клиента
     * @return array Массив с ключами 'selector', 'validator' и полным 'token' (selector:validator)
     */
    public function createToken(int $userId, int $days, string $userAgent, string $ip): array
    {
        $selector = bin2hex(random_bytes(6));
        $validator = bin2hex(random_bytes(32));
        $hashedValidator = password_hash($validator, PASSWORD_DEFAULT);
        $expiresAt = date('Y-m-d H:i:s', time() + ($days * 86400));

        $data = [
            $this->cols['user_id']          => $userId,
            $this->cols['selector']         => $selector,
            $this->cols['hashed_validator'] => $hashedValidator,
            $this->cols['user_agent']       => $userAgent,
            $this->cols['ip_address']       => $ip,
            $this->cols['expires_at']       => $expiresAt,
        ];

        $columns = '`' . implode('`, `', array_keys($data)) . '`';
        $placeholders = ':' . implode(', :', array_keys($data));
        $sql = "INSERT INTO `{$this->table}` ({$columns}) VALUES ({$placeholders})";
        
        $this->db->query($sql, $data);

        return [
            'selector'  => $selector,
            'validator' => $validator,
            'token'     => $selector . ':' . $validator,
        ];
    }

    /**
     * Проверяет валидность токена по селектору и открытому валидатору.
     *
     * @param string $selector Публичный селектор из cookie
     * @param string $validator Открытый валидатор из cookie
     * @return array|null Данные записи токена, если он валиден и не истёк
     */
    public function validateToken(string $selector, string $validator): ?array
    {
        $sql = sprintf(
            "SELECT * FROM `{$this->table}` WHERE `{$this->cols['selector']}` = ? AND `{$this->cols['expires_at']}` > NOW()",
            $this->table,
            $this->cols['selector'],
            $this->cols['expires_at']
        );

        $record = $this->db->fetchOne($sql, [$selector]);

        if ($record && password_verify($validator, $record[$this->cols['hashed_validator']])) {
            return $record;
        }

        return null;
    }

    /**
     * Удаляет токен из базы данных по его селектору.
     */
    public function deleteBySelector(string $selector): void
    {
        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->cols['selector']}` = ?";
        $this->db->execute($sql, [$selector]);
    }

    /**
     * Удаляет все токены пользователя (используется при выходе из системы или смене пароля).
     */
    public function deleteByUserId(int $userId): void
    {
        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->cols['user_id']}` = ?";
        $this->db->execute($sql, [$userId]);
    }
}