<?php

declare(strict_types=1);

namespace W3a\Core\Support;

use W3a\Core\Foundation\Config;

class Lang
{
    protected static string $currentLang = 'ru';
    protected static array $translations = [];
    protected static ?string $appBasePath = null;

    /** @var bool В prod кэш переводов валиден постоянно (до деплоя) */
    protected static bool $isProduction = false;

    /** Время жизни кэша переводов в dev-режиме (сек) */
    private const DEV_LANG_CACHE_TTL = 2;

    public static function init(?Config $config = null, ?string $basePath = null): void
    {
        self::$appBasePath = $basePath ?? dirname(__DIR__, 2);

        $defaultLang = 'ru';
        if ($config !== null) {
            $defaultLang = $config->get('app.lang', 'ru');
            self::$isProduction = $config->get('app.env', 'development') === 'production';
        }

        // Читаем язык из сессии ТОЛЬКО если она уже активна —
        // не стартуем сессию для анонимных запросов (ленивая сессия).
        $sessionLang = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionLang = $_SESSION['lang'] ?? null;
        }
        self::$currentLang = $sessionLang ?? $defaultLang;

        // Сбрасываем массив перед загрузкой, чтобы не было дубликатов
        self::$translations = [];

        // 1. ✅ Загружаем ТОЛЬКО файл текущего языка из app/Lang/ (например, ru.php)
        $langFile = self::$appBasePath . "/app/Lang/" . self::$currentLang . ".php";
        if (file_exists($langFile)) {
            $data = require $langFile;
            if (is_array($data)) {
                // Сливаем напрямую в корень, чтобы __('forum') сразу находил 'Форум'
                self::$translations = array_merge(self::$translations, $data);
                
                // Также сохраняем под ключом языка на случай вызова __('ru.forum')
                self::$translations[self::$currentLang] = $data;
            }
        }

        // 2. ✅ Загружаем файлы модулей ТОЛЬКО для текущего языка
        self::loadAllModuleLangs();
    }

    protected static function loadAllModuleLangs(): void
    {
        if (self::$appBasePath === null) {
            return;
        }

        // Пытаемся загрузить переводы модулей из кэша (один require вместо scandir + N require)
        $cached = self::loadModuleLangsCache();
        if ($cached !== null) {
            self::$translations = array_merge(self::$translations, $cached);
            return;
        }

        $moduleTranslations = [];
        $modulesPath = self::$appBasePath . '/app/Modules';
        if (!is_dir($modulesPath)) {
            self::saveModuleLangsCache([]);
            return;
        }

        $modules = array_diff(scandir($modulesPath), ['.', '..']);
        foreach ($modules as $module) {
            $moduleLangFile = self::$appBasePath . "/app/Modules/{$module}/Lang/" . self::$currentLang . ".php";

            if (file_exists($moduleLangFile)) {
                $data = require $moduleLangFile;
                if (is_array($data)) {
                    $moduleTranslations = array_merge($moduleTranslations, $data);
                }
            }
        }

        self::$translations = array_merge(self::$translations, $moduleTranslations);
        self::saveModuleLangsCache($moduleTranslations);
    }

    /**
     * Путь к файлу кэша переводов модулей для текущего языка.
     */
    protected static function langModulesCacheFile(): string
    {
        return self::$appBasePath . '/storage/cache/lang_modules_' . self::$currentLang . '.php';
    }

    /**
     * Загрузка переводов модулей из кэша (null, если нет или устарел).
     */
    protected static function loadModuleLangsCache(): ?array
    {
        $cacheFile = self::langModulesCacheFile();
        if (!is_file($cacheFile)) {
            return null;
        }

        $data = \W3a\Core\Support\PhpArrayFile::read($cacheFile);
        if (!is_array($data) || !isset($data['translations'], $data['cache_time'])) {
            return null;
        }

        if ((int)$data['cache_time'] < self::getLangCacheCheckMtime()) {
            return null;
        }

        // В dev — короткий TTL, чтобы новые ключи перевода подхватывались без ручной очистки.
        if (!self::$isProduction && (time() - (int)$data['cache_time']) > self::DEV_LANG_CACHE_TTL) {
            return null;
        }

        return $data['translations'];
    }

    /**
     * Атомарное сохранение кэша переводов модулей.
     */
    protected static function saveModuleLangsCache(array $translations): void
    {
        $cacheFile = self::langModulesCacheFile();
        $dir = dirname($cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $data = [
            'translations' => $translations,
            'cache_time' => time(),
        ];

        \W3a\Core\Support\PhpArrayFile::write($cacheFile, $data);
    }

    /**
     * Максимальный mtime источников переводов — сигнатура инвалидации кэша.
     */
    protected static function getLangCacheCheckMtime(): int
    {
        $mtime = 0;

        $modulesPath = self::$appBasePath . '/app/Modules';
        if (is_dir($modulesPath)) {
            $mtime = max($mtime, (int)filemtime($modulesPath));
        }

        $langPath = self::$appBasePath . '/app/Lang';
        if (is_dir($langPath)) {
            $mtime = max($mtime, (int)filemtime($langPath));
        }

        $langFile = self::$appBasePath . '/app/Lang/' . self::$currentLang . '.php';
        if (is_file($langFile)) {
            $mtime = max($mtime, (int)filemtime($langFile));
        }

        return $mtime;
    }

    public static function change(string $lang): void
    {
        self::$currentLang = $lang;

        try {
            $session = container(\W3a\Core\Http\Session::class);
            $session->set('lang', $lang);
        } catch (\Throwable $e) {
            // Контейнер/сессия недоступны (например, CLI) — язык остаётся в памяти
        }

        // Перезагружаем переводы при смене языка
        self::init(); 
    }

    public static function loadModuleLang(string $moduleName): void
    {
        // Этот метод теперь можно вызывать вручную, если модуль загружается динамически
        if (self::$appBasePath === null) {
            return;
        }

        $moduleLangFile = self::$appBasePath . "/app/Modules/{$moduleName}/Lang/" . self::$currentLang . ".php";
        if (file_exists($moduleLangFile)) {
            $data = require $moduleLangFile;
            if (is_array($data)) {
                self::$translations = array_merge(self::$translations, $data);
            }
        }
    }

    public static function get(string $key, array $replace = []): string
    {
        $keys = explode('.', $key);
        $array = self::$translations;

        foreach ($keys as $k) {
            if (isset($array[$k])) {
                $array = $array[$k];
            } else {
                return $key; // Возвращаем ключ, если перевод не найден
            }
        }

        $text = is_string($array) ? $array : $key;

        if (!empty($replace)) {
            foreach ($replace as $placeholder => $value) {
                $text = str_replace(':' . $placeholder, (string)$value, $text);
            }
        }

        return $text;
    }

    public static function format(string $key, array $args = []): string
    {
        $template = self::get($key);
        if (empty($args)) {
            return $template;
        }
        return sprintf($template, ...$args);
    }
}
