<?php

declare(strict_types=1);

use W3a\Core\Foundation\Container;
use W3a\Core\Foundation\Config;
use W3a\Core\Foundation\Env;

/**
 * Получение сервиса из DI-контейнера или его инициализация.
 *
 * @param string|Container|null $abstract Имя класса/интерфейса сервиса ИЛИ экземпляр Container для инициализации.
 * @return mixed Экземпляр запрошенного сервиса или сам контейнер.
 * @throws \RuntimeException Если контейнер не был инициализирован до вызова.
 *
 * @example
 * // Инициализация (делается в Application::bootstrap):
 * container($appContainer);
 *
 * // Получение сервиса:
 * $logger = container(\W3a\Core\Support\Logger::class);
 */
if (!function_exists('container')) {
    function container(string|Container|null $abstract = null): mixed
    {
        static $containerInstance = null;

        if ($abstract instanceof Container) {
            $containerInstance = $abstract;
            return $containerInstance;
        }

        if ($containerInstance === null) {
            throw new \RuntimeException('Application container not initialized. Call container($container) in Application::bootstrap() first.');
        }

        return is_string($abstract) ? $containerInstance->get($abstract) : $containerInstance;
    }
}

/**
 * Получение значения из конфигурации приложения.
 *
 * @param string|null $key Ключ конфигурации (поддерживается точечная нотация, например 'app.name').
 * @param mixed $default Значение по умолчанию, если ключ не найден.
 * @return mixed Значение конфигурации или $default.
 *
 * @example
 * $appName = config('app.name', 'My Website');
 * $allConfig = config(); // Вернет весь объект Config
 */
if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        $config = container(Config::class);

        if ($key === null) {
            return $config;
        }

        return $config->get($key, $default);
    }
}

/**
 * Получение значения переменной окружения (.env).
 *
 * @param string $key Имя переменной окружения.
 * @param mixed $default Значение по умолчанию, если переменная не задана.
 * @return mixed Значение переменной окружения или $default.
 *
 * @example
 * $debug = env('APP_DEBUG', false);
 */
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

// =========================================================================
// ВАЛИДАЦИЯ И FLASH-СООБЩЕНИЯ
// =========================================================================

/**
 * Получить старое значение поля из сессии (после ошибки валидации).
 * Чтобы форма не сбрасывалась при ошибке.
 */
if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): string
    {
        // Читаем старое значение поля через сервис Session (ленивая сессия:
        // для анонимного запроса без cookie новая сессия не создаётся).
        try {
            $session = container(\W3a\Core\Http\Session::class);
            $oldInput = $session->get('_old_input', []);
            return (string)($oldInput[$key] ?? $default);
        } catch (\Throwable $e) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                return (string)($_SESSION['_old_input'][$key] ?? $default);
            }
            return (string)$default;
        }
    }
}

// =========================================================================
// COLLECTION HELPER
// =========================================================================

if (!function_exists('collect')) {
    /**
     * Создать новую коллекцию из переданных данных.
     *
     * @template TKey of array-key
     * @template TValue
     * @param iterable<TKey, TValue>|null $value
     * @return \W3a\Core\Support\Collection<TKey, TValue>
     */
    function collect(iterable $value = null): \W3a\Core\Support\Collection
    {
        return new \W3a\Core\Support\Collection($value ?? []);
    }
}

// =========================================================================
// HTTP-ОШИБКИ И ДАТЫ
// =========================================================================

if (!function_exists('abort')) {
    /**
     * Прервать выполнение с HTTP-ошибкой.
     *
     * Выбрасывает исключение ядра (NotFoundException для 404, HttpException
     * для остальных кодов), которое централизованно обрабатывается
     * ExceptionHandler'ом.
     *
     * @param int    $code    HTTP-код (404, 403, 500, ...)
     * @param string $message Текст ошибки
     * @throws \W3a\Core\Exceptions\NotFoundException
     * @throws \W3a\Core\Exceptions\HttpException
     *
     * @example
     * abort(404, 'Страница не найдена');
     */
    function abort(int $code, string $message = ''): never
    {
        throw match ($code) {
            404 => new \W3a\Core\Exceptions\NotFoundException($message ?: 'Ресурс не найден'),
            default => new \W3a\Core\Exceptions\HttpException($code, $message),
        };
    }
}

if (!function_exists('dt')) {
    /**
     * Форматирование даты из БД (или timestamp) в человекочитаемый вид.
     *
     * @param string|int|null $datetime Дата/время (строка из БД или Unix timestamp)
     * @param string          $format   Формат PHP date()
     * @return string Отформатированная дата ('' если вход пуст)
     *
     * @example
     * dt($item['created_at']);           // "17.08.2026 10:25"
     * dt($item['created_at'], 'd.m.Y');  // "17.08.2026"
     */
    function dt(string|int|null $datetime, string $format = 'd.m.Y H:i'): string
    {
        if ($datetime === null || $datetime === '') {
            return '';
        }

        if (is_int($datetime) || ctype_digit((string)$datetime)) {
            $ts = (int)$datetime;
        } else {
            $ts = strtotime($datetime);
        }

        return $ts !== false && $ts !== null ? date($format, $ts) : (string)$datetime;
    }
}
