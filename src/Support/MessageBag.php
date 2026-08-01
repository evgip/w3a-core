<?php

declare(strict_types=1);

namespace W3a\Core\Support;

/**
 * Единый контейнер для сообщений и данных формы.
 * Управляет жизненным циклом: хранит данные, позволяет читать их много раз,
 * но очищает их при следующем запросе.
 */
class MessageBag
{
    private array $errors = [];
    private array $oldInput = [];
    private array $flashes = [];

    /**
     * Загружает данные из сессии при создании объекта
     */
    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $this->errors = $_SESSION['_validation_errors'] ?? [];
        $this->oldInput = $_SESSION['_old_input'] ?? [];
        
        // 🔥 ИСПРАВЛЕНО: Читаем из 'flash', так как именно туда пишет Session::flash()
        $this->flashes = $_SESSION['flash'] ?? [];

        // Очищаем сессию, так как данные уже загружены в память объекта
        // Это гарантирует, что при следующем обновлении страницы сообщения исчезнут
        unset($_SESSION['_validation_errors'], $_SESSION['_old_input'], $_SESSION['flash']);
    }

    public function hasError(string $key): bool
    {
        return isset($this->errors[$key]) && !empty($this->errors[$key]);
    }

    public function firstError(string $key): string
    {
        return !empty($this->errors[$key]) ? (string)$this->errors[$key][0] : '';
    }

    public function allErrors(): array
    {
        return $this->errors;
    }

    public function getOld(string $key, mixed $default = ''): string
    {
        return $this->oldInput[$key] ?? (string)$default;
    }

    public function getFlash(string $key): ?string
    {
        return $this->flashes[$key] ?? null;
    }

    /**
     * Статический метод для сохранения данных перед редиректом
     */
    public static function flashErrors(array $errors, array $oldInput = []): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['_validation_errors'] = $errors;
        $_SESSION['_old_input'] = $oldInput;
    }

    /**
     * 🔥 ИСПРАВЛЕНО: Пишем в 'flash', чтобы совпадало с конструктором и Session::flash()
     */
    public static function flashMessage(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['flash'][$type] = $message;
    }
}