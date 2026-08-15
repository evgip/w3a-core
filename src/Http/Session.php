<?php

declare(strict_types=1);

namespace W3a\Core\Http;

use RuntimeException;

/**
 * Сервис для безопасного управления сессиями PHP с ЛЕНИВОЙ инициализацией.
 * 
 * КЛЮЧЕВЫЕ ОСОБЕННОСТИ:
 * - session_start() НЕ вызывается в конструкторе
 * - Сессия стартует ТОЛЬКО при первом обращении к $_SESSION (get/set/flash)
 * - Для анонимных GET-запросов сессия вообще не открывается — нет cookie, нет файлов
 * - Сохраняет все функции: flash-сообщения, защита от Session Fixation
 * 
 * ЭКОНОМИЯ: На публичном сайте 90%+ запросов — это GET без авторизации.
 * Эти запросы больше не создают session-файлы и не шлют PHPSESSID cookie ботам.
 */
class Session
{
    /**
     * Флаг, указывающий, была ли сессия запущена данным экземпляром.
     */
    private bool $started = false;

    /**
     * Конструктор. НЕ стартует сессию автоматически.
     * 
     * session_start() вызывается лениво — только когда реально нужны данные сессии.
     * Это позволяет избежать создания сессии для анонимных GET-запросов.
     */
    public function __construct()
    {
        // Намеренно пусто: ленивая инициализация
    }

    /**
     * Явный запуск сессии.
     * 
     * Обычно не нужно вызывать напрямую — методы get/set/flash
     * автоматически вызывают ensureStarted().
     * 
     * @throws RuntimeException Если не удалось запустить сессию
     */
    public function start(): void
    {
        $this->ensureStarted();
    }

    /**
     * Проверить, была ли сессия реально запущена.
     * 
     * Полезно для middleware, который хочет узнать состояние без принудительного старта.
     */
    public function isStarted(): bool
    {
        return $this->started || session_status() === PHP_SESSION_ACTIVE;
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
        $this->ensureStarted();
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
        $this->ensureStarted();
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
        $this->ensureStarted();
        return isset($_SESSION[$key]);
    }

    /**
     * Удалить конкретный ключ из сессии.
     *
     * @param string $key Имя ключа для удаления
     */
    public function delete(string $key): void
    {
        $this->ensureStarted();
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
        $this->ensureStarted();
        return $_SESSION ?? [];
    }

    /**
     * Очистить все данные сессии, но не уничтожать саму сессию (ID остаётся прежним).
     */
    public function clear(): void
    {
        $this->ensureStarted();
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
        if (!$this->isStarted()) {
            // Если сессия ещё не начиналась, уничтожать нечего
            $this->started = false;
            $_SESSION = [];
            return;
        }

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
        $this->ensureStarted();
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
        $this->ensureStarted();
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
        $this->ensureStarted();
        
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
        $this->ensureStarted();
        $flashes = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']); // Очищаем весь блок flash после чтения
        return $flashes;
    }

    /**
     * Получить текущий идентификатор сессии (Session ID).
     * 
     * Если сессия не была запущена, возвращает пустую строку (без принудительного старта).
     *
     * @return string Текущий Session ID или пустая строка
     */
    public function id(): string
    {
        if (!$this->isStarted()) {
            return '';
        }
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
        $this->ensureStarted();
        return session_regenerate_id($deleteOldSession);
    }

    /**
     * Получить имя текущей сессии (по умолчанию 'PHPSESSID' или заданное через session_name()).
     *
     * @return string Имя сессии
     */
    public function name(): string
    {
        // session_name() работает без активной сессии
        return session_name();
    }

    /**
     * Внутренний метод: ленивый запуск сессии.
     * 
     * Вызывается автоматически при любом обращении к данным сессии.
     * 
     * @throws RuntimeException Если не удалось запустить сессию
     */
    private function ensureStarted(): void
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
}
