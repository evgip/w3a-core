<?php

declare(strict_types=1);

namespace W3a\Core\Support;

use W3a\Core\Foundation\Config;

class Lang
{
    protected static string $currentLang = 'ru';
    protected static array $translations = [];
    protected static ?string $appBasePath = null;

    public static function init(?Config $config = null, ?string $basePath = null): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        self::$appBasePath = $basePath ?? dirname(__DIR__, 2);

        $defaultLang = 'ru';
        if ($config !== null) {
            $defaultLang = $config->get('app.lang', 'ru');
        }

        $sessionLang = $_SESSION['lang'] ?? null;
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

        $modulesPath = self::$appBasePath . '/app/Modules';
        if (!is_dir($modulesPath)) {
            return;
        }

        $modules = array_diff(scandir($modulesPath), ['.', '..']);
        foreach ($modules as $module) {
            $moduleLangFile = self::$appBasePath . "/app/Modules/{$module}/Lang/" . self::$currentLang . ".php";
            
            if (file_exists($moduleLangFile)) {
                $data = require $moduleLangFile;
                if (is_array($data)) {
                    self::$translations = array_merge(self::$translations, $data);
                }
            }
        }
    }

    public static function change(string $lang): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['lang'] = $lang;
        self::$currentLang = $lang;
        
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
