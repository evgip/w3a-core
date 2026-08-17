<?php

declare(strict_types=1);

namespace W3a\Core\Auth;

/**
 * Статический класс для проверки состояния авторизации.
 * 
 * Предоставляет удобные методы для проверки текущего пользователя:
 * - Auth::check() — авторизован ли пользователь
 * - Auth::id() — ID текущего пользователя
 * - Auth::isAdmin() — является ли администратором
 * 
 * Работает через сессию PHP. Не создает сервисы через new.
 */
class Auth
{
    private static bool $isLoopProtect = false;

    /**
     * Инициализация сессии (защита от рекурсии).
     */
    public static function initSession(): void
    {
        if (self::$isLoopProtect) {
            return;
        }
        self::$isLoopProtect = true;

        try {
            // Ленивая сессия: стартуем только если у клиента уже есть session-cookie.
            // Анонимные запросы без cookie не создают session-файл и не получают PHPSESSID.
            if (session_status() === PHP_SESSION_NONE && isset($_COOKIE[session_name()])) {
                session_start();
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['last_activity_time'] = time();
            }
        } finally {
            self::$isLoopProtect = false;
        }
    }

    /**
     * Проверка авторизации.
     */
    public static function check(): bool
    {
        self::initSession();
        return isset($_SESSION['user_id']);
    }

    /**
     * Получить ID текущего авторизованного пользователя.
     */
    public static function id(): ?int
    {
        self::initSession();
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    /**
     * Получить имя текущего авторизованного пользователя.
     */
    public static function name(): ?string
    {
        self::initSession();
        return $_SESSION['user_name'] ?? null;
    }

    /**
     * Получить роль текущего авторизованного пользователя.
     */
    public static function role(): ?string
    {
        self::initSession();
        return $_SESSION['user_role'] ?? null;
    }

    /**
     * Проверка, забанен ли текущий пользователь.
     */
    public static function isBanned(): bool
    {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        return (bool)($_SESSION['is_banned'] ?? false);
    }

    /**
     * Проверка: текущий пользователь — администратор.
     */
    public static function isAdmin(): bool
    {
        self::initSession();
        return self::check() && ($_SESSION['user_role'] ?? '') === 'admin';
    }

    /**
     * Проверка: текущий пользователь — модератор (или админ).
     */
    public static function isModerator(): bool
    {
        self::initSession();
        if (!self::check()) {
            return false;
        }
        return in_array($_SESSION['user_role'] ?? '', ['moderator', 'admin'], true);
    }

    /**
     * Проверка: текущий пользователь — член команды модерации (staff).
     */
    public static function isStaff(): bool
    {
        return self::isModerator();
    }
}