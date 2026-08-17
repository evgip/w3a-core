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

if (!function_exists('sanitize_html')) {
    /**
     * Быстрая очистка HTML с использованием пресета.
     * 
     * @param string $html Грязный HTML
     * @param string $preset Пресет: 'article', 'comment', 'minimal', 'text_only'
     */
    function sanitize_html(string $html, string $preset = 'article'): string
    {
        return \W3a\Core\Support\HtmlSanitizer::sanitize($html, $preset);
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
 * Ищет файл через ViewFinder (с кэшированием пути) в следующем порядке:
 * 1. Переопределение в активной теме для конкретного модуля.
 * 2. Глобальное переопределение в активной теме.
 * 3. Оригинальный файл внутри модуля.
 * 4. Fallback в модуль Common.
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
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException("Неверный формат пути partial. Используйте 'Модуль::файл', например: 'Votes::_voters'");
        }
        [$module, $file] = $parts;

        // Резолв пути идёт через ViewFinder — единая точка поиска шаблонов:
        // 1. Переопределение в активной теме для модуля
        // 2. Глобальное переопределение в активной теме
        // 3. Оригинальный файл модуля
        // 4. Fallback в модуль Common
        // Плюс кэширование пути (in-memory + файловый в production).
        try {
            $viewFinder = container(\W3a\Core\View\ViewFinder::class);
            $filePath = $viewFinder->find($file, $module);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("Partial не найден: '{$path}'. " . $e->getMessage());
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



/**
 * Сформировать краткое описание (excerpt) из текста.
 * 
 * - Убирает HTML-теги
 * - Нормализует пробелы и переносы строк
 * - Обрезает до указанной длины на границе слова
 * - Добавляет многоточие если обрезано
 *
 * @param string $text Исходный текст
 * @param int $maxLength Максимальная длина (по умолчанию 200 символов)
 * @param string $suffix Суффикс при обрезке (по умолчанию '...')
 * @return string
 */
 if (!function_exists('text_excerpt')) {
	function text_excerpt(string $text, int $maxLength = 200, string $suffix = '...'): string
	{
		// 1. Убираем HTML-теги
		$text = strip_tags($text);
		
		// 2. Декодируем HTML-сущности
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		
		// 3. Нормализуем пробелы (заменяем переносы строк, табы, множественные пробелы на один пробел)
		$text = preg_replace('/\s+/', ' ', $text);
		
		// 4. Trim
		$text = trim($text);
		
		// 5. Если текст короче лимита — возвращаем как есть
		if (mb_strlen($text) <= $maxLength) {
			return $text;
		}
		
		// 6. Обрезаем до ближайшего пробела перед лимитом
		$truncated = mb_substr($text, 0, $maxLength);
		$lastSpace = mb_strrpos($truncated, ' ');
		
		if ($lastSpace !== false && $lastSpace > $maxLength * 0.7) {
			$truncated = mb_substr($truncated, 0, $lastSpace);
		}
		
		// 7. Убираем trailing punctuation (чтобы не было "...,")
		$truncated = rtrim($truncated, '.,;:!?');
		
		return $truncated . $suffix;
	}
}