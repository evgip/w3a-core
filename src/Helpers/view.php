<?php

declare(strict_types=1);

/**
 * Безопасное HTML-экранирование строки (защита от XSS).
 * Null-safe: если передан null, вернет пустую строку.
 *
 * @param string|null $value Исходная строка для экранирования.
 * @return string Безопасная для вывода в HTML строка.
 * 
 * @example
 * <p><?= e($user->name) ?></p>
 */
if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Получение локализованной строки перевода по ключу.
 *
 * @param string $key Ключ перевода (например, 'auth.login_failed').
 * @param array $replace Ассоциативный массив для замены плейсхолдеров в строке.
 * @return string Переведенная строка.
 * 
 * @example
 * __('welcome.message', ['name' => 'Иван']); // "Добро пожаловать, Иван!"
 */
if (!function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        return \W3a\Core\Support\Lang::get($key, $replace);
    }
}

/**
 * Подключение partial-шаблона с поддержкой каскадного поиска (Fallback Chain).
 * Ищет файл в следующем порядке:
 * 1. Переопределение в активной теме для конкретного модуля.
 * 2. Глобальное переопределение в активной теме.
 * 3. Оригинальный файл внутри модуля.
 *
 * @param string $path Путь в формате 'Модуль::файл' (например, 'Users::_avatar').
 * @param array $vars Переменные, которые будут извлечены (extract) и доступны внутри шаблона.
 * @throws \InvalidArgumentException Если формат пути неверный.
 * @throws \RuntimeException Если шаблон не найден ни в одном из возможных мест.
 * 
 * @example
 * partial('Comments::_item', ['comment' => $comment, 'depth' => 1]);
 */
if (!function_exists('partial')) {
    function partial(string $path, array $vars = []): void
    {
        $parts = explode('::', $path);
        if (count($a = $parts) !== 2) {
            throw new \InvalidArgumentException("Неверный формат пути partial. Используйте 'Модуль::файл', например: 'Votes::_voters'");
        }
        [$module, $file] = $parts;

        $theme = config('app.theme', 'default');
        $basePath = container(\W3a\Core\Foundation\Application::class)->getBasePath();
        
        $appModulesPath = $basePath . '/app/Modules';
        $themesPath = $basePath . '/themes';

        $candidates = [
            "{$themesPath}/{$theme}/Modules/{$module}/Views/{$file}.php",
            "{$themesPath}/{$theme}/Views/{$file}.php",
            "{$appModulesPath}/{$module}/Views/{$file}.php",
        ];

        $filePath = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $filePath = $candidate;
                break;
            }
        }

        if ($filePath === null) {
            throw new \RuntimeException("Partial не найден: '{$path}'. Проверьте пути в теме '{$theme}' или в модуле '{$module}'.");
        }

        // Используем замыкание, чтобы переменные из $vars не "загрязняли" глобальную область видимости
        (function () use ($filePath, $vars) {
            extract($vars, EXTR_SKIP);
            include $filePath;
        })();
    }
}

/**
 * Генерация скрытого HTML-поля с CSRF-токеном для защиты форм.
 *
 * @return string HTML-код тега <input type="hidden" ...>
 * 
 * @example
 * <form method="POST">
 *     <?= csrf_field() ?>
 *     <!-- остальные поля -->
 * </form>
 */
if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        try {
            return container(\W3a\Core\Http\Request::class)->csrfField();
        } catch (\Throwable $e) {
            error_log("csrf_field() failed: " . $e->getMessage());
            return '<input type="hidden" name="_token" value="fallback_token">';
        }
    }
}

/**
 * Получение криптографически стойкого nonce для Content Security Policy (CSP).
 * Позволяет безопасно выполнять inline-скрипты и стили, если сервер отправляет соответствующий CSP-заголовок.
 *
 * @return string Случайная строка (nonce) для атрибута HTML-тега.
 * 
 * @example
 * <script nonce="<?= csp_nonce() ?>">console.log('Safe script');</script>
 */
if (!function_exists('csp_nonce')) {
    function csp_nonce(): string
    {
        static $nonce = null;

        if ($nonce === null) {
            try {
                $security = container(\W3a\Core\Security\Security::class);
                $nonce = $security->getNonce();
            } catch (\Throwable $e) {
                // Fallback, если Security не инициализирован или произошла ошибка
                $nonce = bin2hex(random_bytes(16));
            }
        }

        return $nonce;
    }
}