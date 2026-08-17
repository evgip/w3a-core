<?php

declare(strict_types=1);

namespace W3a\Core\Support;

use W3a\Core\Http\Session;

/**
 * Единый контейнер для сообщений и данных формы.
 * Управляет жизненным циклом: хранит данные, позволяет читать их много раз,
 * но очищает их при следующем запросе.
 *
 * Вся работа с сессией идёт через сервис Session (ленивая сессия):
 * анонимные запросы без session-cookie не создают session-файл.
 */
class MessageBag
{
    private array $errors = [];
    private array $oldInput = [];
    private array $flashes = [];

    /**
     * Загружает данные из сессии при создании объекта.
     * Прочитанные данные немедленно удаляются из сессии, чтобы
     * при следующем обновлении страницы сообщения исчезли.
     */
    public function __construct()
    {
        $session = self::getSession();

        if ($session !== null) {
            $this->errors = (array)$session->get('_validation_errors', []);
            $this->oldInput = (array)$session->get('_old_input', []);
            $this->flashes = (array)$session->get('flash', []);

            $session->delete('_validation_errors');
            $session->delete('_old_input');
            $session->delete('flash');
        }
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
        $session = self::getSession();
        if ($session !== null) {
            $session->set('_validation_errors', $errors);
            $session->set('_old_input', $oldInput);
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['_validation_errors'] = $errors;
        $_SESSION['_old_input'] = $oldInput;
    }

    /**
     * Сохранить flash-сообщение через Session (пишется в 'flash').
     */
    public static function flashMessage(string $type, string $message): void
    {
        $session = self::getSession();
        if ($session !== null) {
            $session->flash($type, $message);
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['flash'][$type] = $message;
    }

    /**
     * Получить сервис Session из контейнера (null, если контейнер недоступен).
     */
    private static function getSession(): ?Session
    {
        try {
            return container(\W3a\Core\Http\Session::class);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
