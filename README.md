# w3a-core

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Высокопроизводительное, модульное и строго типизированное ядро PHP-фреймворка. Реализует принципы чистой архитектуры и современные паттерны разработки (вдохновлено Laravel и Symfony), обеспечивая предсказуемость, тестируемость и отличный Developer Experience.

## ✨ Ключевые особенности

- 🎯 **Строгая типизация HTTP-ответов:** Контроллеры возвращают явные объекты `ViewResponse`, `RedirectResponse` или `JsonResponse`. Никаких скрытых `void` или побочных эффектов.
- 📨 **Единый центр сообщений (MessageBag):** Централизованное управление flash-сообщениями и сохранением данных форм (`old_input`) без прямого манипулирования сессией в контроллерах.
- 🛠 **Встроенные Коллекции (Collections):** Мощный fluent-интерфейс для работы с массивами данных (`collect()->map()->filter()->pluck()`), избавляющий от громоздких `array_*` функций.
- ✅ **Декларативная валидация:** Встроенный класс `Validator` с поддержкой правил (`required`, `email`, `unique`, `min`, `regex` и др.) и автоматической обработкой ошибок через `validateRequest()`.
- 🧩 **Чистая архитектура (DIP):** Ядро не содержит жестких ссылок на классы приложения (`\App\...`). Зависимости явно передаются через DI-контейнер и интерфейсы (Contracts).
- 🛡️ **Безопасность из коробки:** Встроенная защита от CSRF, XSS (CSP-nonce), Rate Limiting и Firewall (бан IP).
- ⚡ **Ленивая загрузка конфигов:** Файлы конфигурации читаются с диска только при первом обращении к их ключам через dot-нотацию.

## 📋 Требования

- PHP 8.1+ (используются union types, constructor property promotion, match expressions)
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

Точка входа (`public/index.php`) максимально чиста и явно объявляет зависимости:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// 1. Загрузка переменных окружения
\W3a\Core\Foundation\Env::load(dirname(__DIR__) . '/.env');

// 2. Инициализация приложения с явной регистрацией провайдеров
$app = new \W3a\Core\Foundation\Application(dirname(__DIR__), [
    \W3a\Core\Foundation\CoreServiceProvider::class, // Сервисы ядра
    \App\AppServiceProvider::class,                  // Сервисы вашего приложения
]);

$app->bootstrap()->run();
```

## 💡 Современный стиль кода (Примеры)

### 1. Контроллеры с явными ответами и MessageBag
Забудьте о `$this->session()->flash()` и скрытых редиректах в контроллерах приложения:

```php
public function store(): RedirectResponse
{
    $data = $this->request->getParams();
    
    // Автоматическая валидация с редиректом и сохранением old_input при ошибке
    $validation = $this->validateRequest([
        'email' => 'required|email|unique:users,email',
        'password' => 'required|min:6',
    ]);
    
    if ($validation !== true) {
        return $validation; // Возвращаем RedirectResponse
    }

    try {
        $this->userService->create($data);
        MessageBag::flashMessage('success', 'Пользователь успешно создан!');
        return $this->redirect('/users');
    } catch (\Throwable $e) {
        MessageBag::flashMessage('error', 'Ошибка при создании пользователя.');
        return $this->redirectBack();
    }
}
```

### 2. Использование Коллекций (Collections)
Замена громоздких `array_map` и `array_filter` на читаемый fluent-интерфейс:

```php
// Получаем уникальные ID активных историй из массива комментариев
$storyIds = collect($comments)
    ->reject(fn($c) => !empty($c['deleted_at']))
    ->pluck('story_id')
    ->unique()
    ->values()
    ->toArray();
```

## 📂 Структура приложения

```text
your-app/
├── app/
│   ├── Config/                 # Конфигурация (app.php, database.php, ...)
│   ├── Lang/                   # Файлы локализации (ru.php, en.php)
9   ├── Modules/                # Бизнес-модули (Users, Stories, Admin, ...)
│   └── AppServiceProvider.php  # Связывание интерфейсов ядра с реализациями
├── storage/
│   ├── cache/                  # Кэш маршрутов, представлений и провайдеров
│   └── logs/                   # Логи приложения и PHP-ошибок
├── public/
│   └── index.php               # Точка входа (Front Controller)
└── composer.json
```

## 🔌 Ключевые интерфейсы (Contracts)

Ядро работает через инверсию зависимостей. Перед использованием функционала, зависящего от бизнес-логики, необходимо зарегистрировать реализации в `AppServiceProvider`:

| Интерфейс | Назначение |
|-----------|-----------|
| `Contracts\RateLimitStorageInterface` | Хранилище лимитов запросов (Rate Limiter) |
| `Contracts\UserIdProviderInterface` | Получение ID текущего авторизованного пользователя |
| `Contracts\AuditStorageInterface` | Хранилище журнала аудита действий |
| `Contracts\BannedIpRepositoryInterface` | Проверка заблокированных IP-адресов (Firewall) |
| `Contracts\ErrorHandlerInterface` | Обработка и рендеринг страниц ошибок (404, 500) |

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
        // Ленивая регистрация через замыкание (singleton)
        $container->singleton(ErrorHandlerInterface::class, fn($c) => 
            new ErrorHandler($c)
        );
        
        // Регистрация групп middleware
        $router = $container->get(\W3a\Core\Http\Router::class);
        $router->addMiddlewareGroup('auth', [
            \App\Modules\Users\Middleware\AuthMiddleware::class,
            \App\Modules\Users\Middleware\BanCheckMiddleware::class,
        ]);
    }
}
```

## ⚙️ Конфигурация

Конфиги поддерживают **dot-нотацию** (`config('database.host')`) и загружаются **лениво** (файл `database.php` не будет прочитан, если вы обращаетесь только к `config('app.name')`).

```php
// app/Config/app.php
return [
    'name' => 'my-app',
    'env' => \W3a\Core\Foundation\Env::get('APP_ENV', 'development'),
    'lang' => \W3a\Core\Foundation\Env::get('APP_LANG', 'ru'),
    'log_path' => dirname(__DIR__, 2) . '/storage/logs/app.log',
];
```

## 🧩 Основные компоненты ядра

| Компонент | Неймспейс | Назначение |
|-----------|-----------|-----------|
| `Application` | `W3a\Core\Foundation` | Оркестратор: управление жизненным циклом (bootstrap) |
| `Container` | `W3a\Core\Foundation` | DI-контейнер (поддержка `singleton`, `bind`, авто-резолв через рефлексию) |
| `ExceptionHandler`| `W3a\Core\Exceptions` | Централизованная обработка ошибок (без использования исключений для управления потоком редиректов/JSON) |
| `Router` | `W3a\Core\Http` | Маршрутизация с поддержкой middleware-групп |
| `MessageBag` | `W3a\Core\Support` | Управление flash-сообщениями и данными форм (`old_input`) |
| `Collection` | `W3a\Core\Support` | Fluent-интерфейс для трансформации массивов данных |
| `Validator` | `W3a\Core\Support` | Декларативная валидация входных данных с поддержкой БД (`unique`, `exists`) |

## 📄 Лицензия

Распространяется под лицензией [MIT](LICENSE).
