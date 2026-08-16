<?php

declare(strict_types=1);

namespace W3a\Core\Http;

use RuntimeException;

/**
 * Сервис для безопасного управления сессиями PHP.
 * 
 * Предоставляет объектно-ориентированный интерфейс для работы с $_SESSION,
 * включая поддержку flash-сообщений (данные, доступные только для следующего запроса)
 * и защиту от атак фиксирования сессии (Session Fixation).
 */
class Session
{
    /**
     * Флаг, указывающий, была ли сессия запущена данным экземпляром.
     */
    private bool $started = false;

    /**
     * Конструктор. Автоматически запускает сессию при создании экземпляра.
     */
    public function __construct()
    {
        $this->start();
    }

    /**
     * Запуск сессии с защитой от повторного запуска.
     * 
     * Проверяет текущий статус сессии и запускает её только если она ещё не активна.
     * 
     * @throws RuntimeException Если не удалось запустить сессию
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        // Если сессия уже активна (запущена глобально или другим экземпляром)
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        // Если сессия не запущена, пытаемся её запустить
        if (session_status() === PHP_SESSION_NONE) {
            if (!session_start()) {
                throw new RuntimeException('Не удалось запустить сессию (Failed to start session)');
            }
            $this->started = true;
        }
    }

    /**
     * Получить значение из сессии по ключу.
     *
     * @param string $key Имя ключа сессии
     * @param mixed $default Значение по умолчанию, если ключ не найден
     * @return mixed Значение из сессии или $default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Установить значение в сессию.
     *
     * @param string $key Имя ключа сессии
     * @param mixed $value Значение для сохранения
     */
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Проверить, существует ли ключ в сессии и не является ли он null.
     *
     * @param string $key Имя ключа сессии
     * @return bool true, если ключ существует, иначе false
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Удалить конкретный ключ из сессии.
     *
     * @param string $key Имя ключа для удаления
     */
    public function delete(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Алиас для метода delete() (более привычное имя для некоторых разработчиков).
     *
     * @param string $key Имя ключа для удаления
     */
    public function remove(string $key): void
    {
        $this->delete($key);
    }

    /**
     * Получить все данные текущей сессии в виде массива.
     *
     * @return array Массив всех данных сессии
     */
    public function all(): array
    {
        return $_SESSION ?? [];
    }

    /**
     * Очистить все данные сессии, но не уничтожать саму сессию (ID остаётся прежним).
     */
    public function clear(): void
    {
        $_SESSION = [];
    }

    /**
     * Полностью уничтожить сессию.
     * 
     * Удаляет cookie сессии на стороне клиента, уничтожает данные на сервере 
     * и очищает глобальный массив $_SESSION.
     */
    public function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Удаляем cookie сессии на стороне клиента
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params["path"],
                    $params["domain"],
                    $params["secure"],
                    $params["httponly"]
                );
            }

            // Уничтожаем данные сессии на сервере
            session_destroy();
        }

        // Очищаем глобальный массив и сбрасываем флаг
        $_SESSION = [];
        $this->started = false;
    }

    /**
     * Установить flash-сообщение.
     * 
     * Flash-сообщения хранятся в сессии и автоматически удаляются 
     * при первом же обращении к ним через getFlash() или allFlashes().
     * Идеально подходят для сообщений об успехе или ошибке после редиректа.
     *
     * @param string $key Имя ключа (например, 'success', 'error')
     * @param mixed $message Текст сообщения или данные
     */
    public function flash(string $key, mixed $message): void
    {
        $_SESSION['flash'][$key] = $message;
    }

    /**
     * Проверить, существует ли flash-сообщение с указанным ключом.
     *
     * @param string $key Имя ключа flash-сообщения
     * @return bool true, если сообщение существует
     */
    public function hasFlash(string $key): bool
    {
        return isset($_SESSION['flash'][$key]);
    }

    /**
     * Получить flash-сообщение и немедленно удалить его из сессии.
     *
     * @param string $key Имя ключа flash-сообщения
     * @return mixed Текст сообщения или null, если ключ не найден
     */
    public function getFlash(string $key): mixed
    {
        if (isset($_SESSION['flash'][$key])) {
            $message = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]); // Удаляем после прочтения
            return $message;
        }
        return null;
    }

    /**
     * Получить все flash-сообщения и очистить их из сессии.
     *
     * @return array Массив всех flash-сообщений
     */
    public function allFlashes(): array
    {
        $flashes = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']); // Очищаем весь блок flash после чтения
        return $flashes;
    }

    /**
     * Получить текущий идентификатор сессии (Session ID).
     *
     * @return string Текущий Session ID
     */
    public function id(): string
    {
        return session_id();
    }

    /**
     * Сгенерировать новый идентификатор сессии.
     * 
     * Критически важно вызывать этот метод при изменении уровня привилегий 
     * пользователя (например, при входе в систему или выходе), 
     * чтобы предотвратить атаки фиксирования сессии (Session Fixation).
     *
     * @param bool $deleteOldSession Удалять ли файл старой сессии на сервере (по умолчанию true для безопасности)
     * @return bool true при успешном выполнении, false при ошибке
     */
    public function regenerate(bool $deleteOldSession = true): bool
    {
        return session_regenerate_id($deleteOldSession);
    }

    /**
     * Получить имя текущей сессии (по умолчанию 'PHPSESSID' или заданное через session_name()).
     *
     * @return string Имя сессии
     */
    public function name(): string
    {
        return session_name();
    }
}
