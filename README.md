# w3a-core

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Высокопроизводительное, модульное и полностью независимое ядро PHP-фреймворка. Реализует принципы чистой архитектуры: ядро не знает о бизнес-логике приложения, а все зависимости явно передаются через конфигурацию и DI-контейнер.

## ✨ Особенности

- ⚡ **Ленивая загрузка конфигов:** Файлы читаются с диска только при первом обращении к их ключам (dot-нотация).
- 🧩 **Чистая архитектура (DIP):** Ядро не содержит жестких ссылок на классы приложения (например, `\App\...`). Провайдеры регистрируются явно из точки входа.
- 🚀 **Встроенный CLI:** Инструменты командной строки для управления приложением без HTTP-оверхеда.
- 🛡️ **Безопасность:** Встроенная защита от CSRF, XSS (CSP), Rate Limiting и Firewall.
- 📦 **Package Discovery:** Автоматическое обнаружение сервис-провайдеров из установленных Composer-пакетов.

## 📋 Требования

- PHP 8.1+
- Расширения: `pdo`, `mbstring`, `json`
- База данных: MySQL / MariaDB (или другая, поддерживаемая PDO)

## 📦 Установка

### Для продакшена (через Packagist)
```bash
composer require evgip/w3a-core
```

### Для локальной разработки (через symlink)
Добавьте в `composer.json` вашего основного проекта:
```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./w3a-core"
        }
    ],
    "require": {
        "evgip/w3a-core": "@dev"
    }
}
```
Затем выполните `composer update evgip/w3a-core`. Composer создаст символическую ссылку, и все изменения в ядре будут применяться мгновенно.

## 🚀 Быстрый старт

Точка входа (`public/index.php`) теперь выглядит максимально чисто и явно declares зависимости:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// 1. Загрузка переменных окружения
\W3a\Core\Foundation\Env::load(dirname(__DIR__) . '/.env');

// 2. Инициализация и запуск приложения с явной регистрацией провайдеров
$app = new \W3a\Core\Foundation\Application(dirname(__DIR__), [
    \W3a\Core\Foundation\CoreServiceProvider::class, // Сервисы ядра
    \App\AppServiceProvider::class,                  // Сервисы вашего приложения
]);

$app->bootstrap()->run();
```

## 📂 Структура приложения

```text
your-app/
├── app/
│   ├── Config/                 # Конфигурация (app.php, database.php, ...)
│   ├── Lang/                   # Файлы локализации (ru.php, en.php)
│   ├── Modules/                # Бизнес-модули (Users, Stories, Admin, ...)
│   └── AppServiceProvider.php  # Связывание интерфейсов ядра с реализациями модулей
├── bin/
│   └── w3a                     # CLI-интерфейс ядра
├── storage/
│   ├── cache/                  # Кэш маршрутов, представлений и провайдеров
│   └── logs/                   # Логи приложения и PHP-ошибок
├── public/
│   └── index.php               # Точка входа
└── composer.json
```

*(Внутри самого пакета `w3a-core/src` код организован по доменам: `Foundation/`, `Http/`, `Database/`, `Security/`, `View/`, `Cache/` и т.д.)*

## 🔌 Ключевые интерфейсы (Contracts)

Ядро работает через инверсию зависимостей. Перед использованием функционала, зависящего от бизнес-логики, необходимо зарегистрировать реализации в `AppServiceProvider`:

| Интерфейс | Назначение |
|-----------|-----------|
| `Contracts\RateLimitStorageInterface` | Хранилище лимитов запросов (Rate Limiter) |
| `Contracts\UserIdProviderInterface` | Получение ID текущего авторизованного пользователя |
| `Contracts\AuditStorageInterface` | Хранилище журнала аудита действий |
| `Contracts\BannedIpRepositoryInterface` | Проверка заблокированных IP-адресов (Firewall) |
| `Contracts\ErrorHandlerInterface` | Обработка и рендеринг страниц ошибок |

### Пример регистрации

```php
<?php
// app/AppServiceProvider.php
namespace App;

use W3a\Core\Foundation\Container;
use W3a\Core\Contracts\ErrorHandlerInterface;
use App\Modules\Errors\Services\ErrorHandler;

class AppServiceProvider
{
    public function register(Container $container): void
    {
        // Ленивая регистрация через замыкание
        $container->singleton(ErrorHandlerInterface::class, fn($c) => 
            new ErrorHandler($c)
        );
        
        // Регистрация групп middleware
        $router = $container->get(\W3a\Core\Http\Router::class);
        $router->addMiddlewareGroup('auth', [
            \App\Modules\Users\Middleware\AuthMiddleware::class,
        ]);
    }
}
```

## ⚙️ Конфигурация

Конфиги поддерживают **dot-нотацию** (`config('database.host')`) и загружаются **лениво** (файл `database.php` не будет прочитан, если вы обращаетесь только к `config('app.name')`).

### `app/Config/app.php`
```php
return [
    'name' => 'my-app',
    'env' => \W3a\Core\Foundation\Env::get('APP_ENV', 'development'),
    'lang' => \W3a\Core\Foundation\Env::get('APP_LANG', 'ru'),
    'log_path' => dirname(__DIR__, 2) . '/storage/logs/app.log',
];
```

## 💻 CLI-интерфейс

Ядро предоставляет консольные команды для управления приложением без инициализации HTTP-стека.

```bash
# Очистка всех кэшей (маршруты, представления, провайдеры)
php bin/w3a cache:clear

# Показать список доступных команд
php bin/w3a help
```

## 🧩 Основные компоненты

| Компонент | Неймспейс | Назначение |
|-----------|-----------|-----------|
| `Application` | `W3a\Core\Foundation` | Оркестратор: управление жизненным циклом (bootstrap) |
| `Container` | `W3a\Core\Foundation` | DI-контейнер (поддержка `singleton`, `bind`, `instance`, авто-резолв через рефлексию) |
| `ProviderRepository`| `W3a\Core\Foundation` | Сканирование и кэширование провайдеров (включая Package Discovery) |
| `ExceptionHandler` | `W3a\Core\Exceptions` | Централизованная обработка и логирование всех типов исключений |
| `Router` | `W3a\Core\Http` | Маршрутизация с поддержкой middleware-групп |
| `Config` | `W3a\Core\Foundation` | Управление конфигурацией с dot-нотацией и ленивой загрузкой |
| `Database` / `Model`| `W3a\Core\Database` | Безопасная обёртка над PDO и абстрактная модель данных |
| `FileCache` / `DatabaseCache` | `W3a\Core\Cache` | Гибкие механизмы кэширования с поддержкой TTL и тегов |

## 📄 Лицензия

Распространяется под лицензией [MIT](LICENSE).

