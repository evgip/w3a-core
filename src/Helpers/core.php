<?php

declare(strict_types=1);

use W3a\Core\Container;

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
 * $logger = container(\W3a\Core\Logger::class);
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
        $config = container(\W3a\Core\Config::class);
        
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
        return \W3a\Core\Env::get($key, $default);
    }
}