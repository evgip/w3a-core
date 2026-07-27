# w3a-core

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Высокопроизводительное, модульное и полностью независимое ядро PHP-фреймворка. Поддерживает DI-контейнер, сервис-провайдеры, middleware-конвейер, роутинг с кэшированием и ленивую загрузку конфигурации.

## ✨ Особенности

- ⚡ **Ленивая загрузка конфигов:** Файлы читаются с диска только при первом обращении к их ключам.
- 🧩 **Чистая архитектура:** Ядро не знает о бизнес-логике приложения. Связь через интерфейсы (`Contracts`).
- 🚀 **Встроенный CLI:** Инструменты командной строки для управления приложением без HTTP-оверхеда.
- 🛡️ **Безопасность:** Встроенная защита от CSRF, XSS (CSP), Rate Limiting и Firewall.

## 📋 Требования

- PHP 8.1+
- Расширения: `pdo`, `mbstring`, `json`
- База данных: MySQL / MariaDB

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

```php
// public/index.php
require_once __DIR__ . '/../vendor/autoload.php';

// 1. Загрузка переменных окружения
\W3a\Core\Env::load(__DIR__ . '/../.env');

// 2. Инициализация и запуск приложения
$app = new \W3a\Core\Application(__DIR__ . '/..');
$app->bootstrap()->run();
```

## 📂 Структура приложения

```text
your-app/
├── app/
│   ├── Config/                 # Конфигурация (app.php, database.php, ...)
│   ├── Lang/                   # Файлы локализации (ru.php, en.php)
│   ├── Modules/                # Бизнес-модули (Users, Stories, Admin, ...)
│   └── AppServiceProvider.php  # Связывание интерфейсов ядра с реализациями
├── bin/
│   └── w3a                     # CLI-интерфейс ядра
├── storage/
│   ├── cache/                  # Кэш маршрутов, представлений и провайдеров
│   └── logs/                   # Логи приложения и PHP-ошибок
├── public/
│   └── index.php               # Точка входа
└── composer.json
```

## 🔌 Ключевые интерфейсы (Contracts)

Ядро работает через инверсию зависимостей. Перед использованием необходимо зарегистрировать реализации в `AppServiceProvider`:

| Интерфейс | Назначение |
|-----------|-----------|
| `Contracts\RateLimitStorageInterface` | Хранилище лимитов запросов (Rate Limiter) |
| `Contracts\UserIdProviderInterface` | Получение ID текущего авторизованного пользователя |
| `Contracts\AuditStorageInterface` | Хранилище журнала аудита действий |
| `Contracts\BannedIpRepositoryInterface` | Проверка заблокированных IP-адресов (Firewall) |
| `Contracts\UniqueCheckerInterface` | Валидация уникальности значений в БД |
| `Contracts\ErrorHandlerInterface` | Обработка и рендеринг страниц ошибок |

### Пример регистрации

```php
// app/AppServiceProvider.php
namespace App;

use W3a\Core\Container;
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
        
        // ... другие регистрации
    }
}
```

## ⚙️ Конфигурация

Конфиги поддерживают **dot-нотацию** (`config('database.host')`) и загружаются **лениво** (файл `database.php` не будет прочитан, если вы обращаетесь только к `config('app.name')`).

### `app/Config/app.php`
```php
return [
    'name' => 'my-app',
    'env' => \W3a\Core\Env::get('APP_ENV', 'development'),
    'lang' => \W3a\Core\Env::get('APP_LANG', 'ru'),
    'log_path' => __DIR__ . '/../../storage/logs/app.log',
];
```

### `app/Config/rate_limit.php`
```php
return [
    'enabled' => true,
    'gc_probability' => 5,
    'rules' => [
        'global.get' => ['max_requests' => 100, 'window' => 60],
        'auth.submit' => ['max_requests' => 5, 'window' => 60],
    ],
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

| Компонент | Назначение |
|-----------|-----------|
| `Application` | Точка входа, управление жизненным циклом (bootstrap) |
| `Container` | DI-контейнер (поддержка `singleton`, `bind`, `instance`) |
| `Router` | Маршрутизация с поддержкой middleware-групп и компиляцией в кэш |
| `Config` | Управление конфигурацией с dot-нотацией и ленивой загрузкой |
| `Database` | Безопасная обёртка над PDO с подготовленными выражениями |
| `Model` | Абстрактная модель с поддержкой Soft Deletes и Mass Assignment |
| `View` / `ViewFinder` | Рендеринг шаблонов с поддержкой тем (Fallback Chain) |
| `Validator` | Валидация входных данных (required, min, max, regex, unique) |

## 📄 Лицензия

Распространяется под лицензией [MIT](LICENSE).
