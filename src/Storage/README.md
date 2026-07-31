# w3a-core/storage

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Мощная и безопасная абстракция файловой системы для PHP-фреймворка **w3a-core**. Предоставляет единый API для работы с файлами на любых хранилищах (локальная ФС, S3, FTP и др.) с встроенной валидацией, защитой от атак и поддержкой нескольких дисков.

## ✨ Возможности

- 🗂️ **Множественные диски** — локальное хранилище, публичные файлы, приватные файлы, аватары — всё через единый API.
- 🔒 **Безопасность по умолчанию** — проверка MIME через `finfo` (не по расширению!), защита от directory traversal, `move_uploaded_file()`.
- 📦 **Валидатор файлов** — проверка типа, расширения и размера с человекочитаемыми сообщениями об ошибках.
- 🎯 **Конфигурируемые имена таблиц и колонок** — работает с любой схемой БД без изменения кода.
- 🔌 **Расширяемость** — легко добавить свой драйвер (S3, FTP, Dropbox) через интерфейс `StorageInterface`.
- 🌐 **Публичные URL** — автоматическая генерация URL для публичных дисков.

## 📋 Требования

- PHP 8.1+
- Расширение `fileinfo` (для определения MIME-типов)
- w3a-core (любая актуальная версия)

## 🚀 Быстрый старт

### 1. Создайте конфигурацию дисков

Создайте файл `app/Config/storage.php`:

```php
<?php

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

return [
    // Диск, используемый по умолчанию
    'default' => 'local',

    'disks' => [
        // Приватный диск для внутренних файлов (логи, временные файлы)
        'local' => [
            'driver'     => 'local',
            'root'       => $basePath . '/storage/app',
            'visibility' => 'private',
        ],

        // Публичный диск для файлов, доступных по URL
        'public' => [
            'driver'     => 'local',
            'root'       => $basePath . '/public/storage',
            'visibility' => 'public',
            'url'        => '/storage',
        ],

        // Отдельный диск для аватаров пользователей
        'avatars' => [
            'driver'     => 'local',
            'root'       => $basePath . '/public/uploads/avatars',
            'visibility' => 'public',
            'url'        => '/uploads/avatars',
        ],
    ],
];
```

### 2. Создайте директории на диске

```bash
mkdir -p storage/app
mkdir -p public/storage
mkdir -p public/uploads/avatars
```

Убедитесь, что у веб-сервера есть права на запись в эти папки.

### 3. Используйте в коде

```php
use W3a\Core\Storage\StorageManager;
use W3a\Core\Storage\UploadedFile;
use W3a\Core\Storage\FileValidator;

// Получаем менеджер дисков из контейнера
$storage = $container->get(StorageManager::class);

// Сохраняем текстовый файл
$storage->disk('local')->put('reports/daily.txt', 'Отчёт за сегодня');

// Читаем файл
$content = $storage->disk('local')->get('reports/daily.txt');

// Проверяем существование
if ($storage->disk('avatars')->exists('users/123.jpg')) {
    // ...
}

// Получаем публичный URL
$url = $storage->disk('avatars')->url('users/123.jpg');
// Результат: /uploads/avatars/users/123.jpg
```

## 📤 Загрузка файлов

### Базовый пример

```php
public function uploadDocument(): void
{
    // 1. Получаем загруженный файл
    $file = UploadedFile::fromRequest('document');

    // 2. Сохраняем на диск 'local' в подпапку 'documents'
    $storage = $this->container->get(StorageManager::class);
    $path = $storage->disk('local')->putFile($file, 'documents');

    // $path будет выглядеть как: documents/a3f2b1c4d5e6f7g8h9i0j1k2l3m4n5o6.pdf
}
```

### Загрузка аватара с валидацией

```php
public function updateAvatar(): void
{
    try {
        // 1. Получаем файл из запроса
        $file = UploadedFile::fromRequest('avatar');

        // 2. Валидируем
        $validator = new FileValidator([
            'mimes'      => ['image/jpeg', 'image/png', 'image/webp'],
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_size'   => 2 * 1024 * 1024, // 2 MB
        ]);

        $validator->validateOrFail($file);

        // 3. Сохраняем на диск 'avatars'
        $storage = $this->container->get(StorageManager::class);
        $path = $storage->disk('avatars')->putFile($file);

        // 4. Удаляем старый аватар (если был)
        $userModel = $this->container->get(\App\Modules\Users\Models\User::class);
        $profile = $userModel->getProfile(Auth::id());

        if (!empty($profile['avatar'])) {
            $storage->disk('avatars')->delete($profile['avatar']);
        }

        // 5. Обновляем профиль пользователя
        $userModel->updateProfile(Auth::id(), ['avatar' => $path]);

        // 6. Получаем URL для отображения
        $avatarUrl = $storage->disk('avatars')->url($path);

        $this->session()->flash('success', 'Аватар успешно обновлён');
        $this->redirectBack();

    } catch (\W3a\Core\Storage\Exceptions\ValidationException $e) {
        $this->session()->flash('error', $e->getMessage());
        $this->redirectBack();
    } catch (\Throwable $e) {
        $this->logError($e, 'Avatar.upload');
        $this->session()->flash('error', 'Не удалось загрузить аватар');
        $this->redirectBack();
    }
}
```

## 🔍 Валидация файлов

Класс `FileValidator` проверяет файлы по реальным характеристикам, а не по доверенным данным клиента.

### Правила валидации

| Правило | Тип | Описание |
|---------|-----|----------|
| `mimes` | `array` | Разрешённые MIME-типы (проверяются через `finfo`) |
| `extensions` | `array` | Разрешённые расширения файла |
| `max_size` | `int` | Максимальный размер в байтах |
| `min_size` | `int` | Минимальный размер в байтах |

### Пример использования

```php
$validator = new FileValidator([
    'mimes'      => ['application/pdf', 'image/png'],
    'extensions' => ['pdf', 'png'],
    'max_size'   => 10 * 1024 * 1024, // 10 MB
    'min_size'   => 1024,             // 1 KB
]);

if (!$validator->validate($file)) {
    // Получаем все ошибки
    $errors = $validator->getErrors();
    
    // Или только первую
    $firstError = $validator->getFirstError();
}

// Или с выбросом исключения
$validator->validateOrFail($file);
```

## 📂 API хранилища

Все методы доступны через `$storage->disk('имя_диска')`.

### Основные методы

| Метод | Описание | Возвращает |
|-------|----------|------------|
| `put($path, $contents)` | Сохранить содержимое в файл | `bool` |
| `putFile($file, $dir, $name)` | Сохранить загруженный файл | `string` (путь) |
| `get($path)` | Получить содержимое файла | `string` |
| `exists($path)` | Проверить существование файла | `bool` |
| `delete($path)` | Удалить файл | `bool` |
| `url($path)` | Получить публичный URL | `?string` |
| `path($path)` | Получить абсолютный путь | `string` |
| `size($path)` | Получить размер файла (в байтах) | `int` |
| `lastModified($path)` | Время последней модификации | `int` (timestamp) |
| `makeDirectory($path)` | Создать директорию | `bool` |
| `deleteDirectory($path)` | Удалить директорию рекурсивно | `bool` |
| `files($dir)` | Список файлов в директории | `array` |

### Примеры

```php
$disk = $storage->disk('local');

// Запись и чтение
$disk->put('config.json', json_encode(['key' => 'value']));
$json = $disk->get('config.json');

// Информация о файле
$size = $disk->size('config.json');              // 25
$modified = $disk->lastModified('config.json');  // 1722345678

// Работа с директориями
$disk->makeDirectory('backups/2026');
$files = $disk->files('backups/2026');

// Удаление
$disk->delete('config.json');
$disk->deleteDirectory('backups/2026');
```

## 🛡️ Безопасность

### Защита от directory traversal

Все пути автоматически нормализуются. Попытки обращения за пределы корня диска блокируются:

```php
// Все эти пути будут безопасно обработаны:
$disk->path('../../etc/passwd');     // → /root/
$disk->path('uploads/../../../etc'); // → /root/
$disk->path('foo/./bar/../baz');     // → /root/foo/baz
```

### Проверка MIME-типов

Класс `UploadedFile` использует `finfo` для определения реального MIME-типа по содержимому файла, а не по расширению:

```php
$file = UploadedFile::fromRequest('avatar');

// Клиент может отправить файл malware.jpg с содержимым PHP-скрипта
// Но getMimeType() вернёт реальный тип:
$mimeType = $file->getMimeType(); // 'application/x-httpd-php'

// А guessExtension() определит правильное расширение:
$extension = $file->guessExtension(); // 'php'
```

### Защита HTTP-загрузки

`UploadedFile` использует `is_uploaded_file()` и `move_uploaded_file()` — стандартные механизмы PHP для защиты от подмены путей.

## 🔌 Расширение: свой драйвер

Чтобы добавить новый драйвер (например, S3), реализуйте интерфейс `StorageInterface`:

```php
<?php

declare(strict_types=1);

namespace W3a\Core\Storage;

use W3a\Core\Storage\Contracts\StorageInterface;

class S3Storage implements StorageInterface
{
    public function __construct(array $config)
    {
        // Инициализация клиента S3
    }

    public function put(string $path, string $contents): bool
    {
        // Реализация загрузки в S3
    }

    public function putFile(UploadedFile $file, string $directory = '', ?string $name = null): string
    {
        // Реализация загрузки файла в S3
    }

    // ... остальные методы интерфейса
}
```

Затем зарегистрируйте драйвер в `StorageManager` (или через сервис-провайдер):

```php
// В CoreServiceProvider или AppServiceProvider
$manager = $container->get(StorageManager::class);
$manager->extend('s3', function (array $config) {
    return new S3Storage($config);
});
```

И добавьте диск в конфиг:

```php
'disks' => [
    's3' => [
        'driver' => 's3',
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'bucket' => env('AWS_BUCKET'),
        'url'    => env('AWS_URL'),
    ],
],
```

## 📊 Класс `UploadedFile`

Обёртка над загруженным файлом из `$_FILES`. Предоставляет безопасный API:

| Метод | Описание |
|-------|----------|
| `fromRequest($field)` | Создать из поля формы |
| `getOriginalName()` | Оригинальное имя файла (от клиента) |
| `getCleanName()` | Санитизированное имя (без спецсимволов) |
| `getExtension()` | Расширение из имени файла |
| `guessExtension()` | Расширение по реальному MIME-типу |
| `getMimeType()` | Реальный MIME-тип (через `finfo`) |
| `getSize()` | Размер в байтах |
| `getSizeFormatted()` | Размер в человекочитаемом формате |
| `isImage()` | Является ли файл изображением |
| `moveTo($path)` | Переместить файл в указанное место |

## 📂 Структура проекта

```
w3a-core/src/Storage/
├── Contracts/
│   └── StorageInterface.php      # Контракт для любого диска
├── LocalStorage.php              # Реализация для локальной ФС
├── StorageManager.php            # Менеджер дисков (фабрика)
├── UploadedFile.php              # Обёртка над $_FILES
├── FileValidator.php             # Валидация MIME, размера
└── Exceptions/
    ├── FileNotFoundException.php
    ├── UploadException.php
    └── ValidationException.php
```

## 🎯 Лучшие практики

1. **Всегда валидируйте файлы** перед сохранением — даже если на клиенте есть проверка.
2. **Используйте разные диски** для разных типов файлов: `avatars`, `documents`, `backups`.
3. **Храните относительные пути** в БД, а не абсолютные — это позволит легко переносить проект.
4. **Удаляйте старые файлы** при обновлении (например, при смене аватара).
5. **Используйте `guessExtension()`** вместо `getExtension()` для генерации имён файлов.

## 📄 Лицензия

Распространяется под лицензией [MIT](LICENSE).