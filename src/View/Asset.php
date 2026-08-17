<?php

declare(strict_types=1);

namespace W3a\Core\View;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

use W3a\Core\Support\Logger;
use W3a\Core\Foundation\Container;
use W3a\Core\Foundation\Application;
use W3a\Core\Foundation\Config;

/**
 * Компилятор и менеджер статических ассетов (CSS/JS).
 * 
 * Отвечает за:
 * 1. Автоматическое обнаружение файлов .css и .js в модулях и активной теме.
 * 2. Проверку актуальности файлов (mtime) и автоматическую перекомпиляцию в dev-режиме.
 * 3. Объединение (bundling) и базовую минификацию файлов.
 * 4. Генерацию путей с кэш-бастингом (?v=timestamp).
 */
class Asset
{
    private static string $distCssFile;
    private static string $distAdminCssFile;
    private static string $distJsFile;
    
    /** @var Container|null Контейнер для получения сервисов (Config, Logger, Application) */
    private static ?Container $container = null;

    /** @var array<string, array<int,string>> In-memory кэш обнаруженных файлов (ext => пути) */
    private static array $discovered = [];

    /** @var string|null Замемоизированное имя активной темы */
    private static ?string $activeTheme = null;

    /** Время жизни файлового манифеста в dev-режиме (сек) — через него подхватываются новые файлы */
    private const DEV_MANIFEST_TTL = 2;

    /**
     * Установить контейнер зависимостей.
     * Вызывается один раз при инициализации приложения (в Application::bootstrap).
     */
    public static function setContainer(Container $container): void
    {
        self::$container = $container;
    }

    /**
     * 🔥 УМНЫЙ ПОИСК БАЗОВОГО ПУТИ К КОРНЮ ПРОЕКТА
     * Гарантирует работу как при локальной разработке, так и при установке через Composer.
     */
    private static function getBasePath(): string
    {
        // 1. Пытаемся получить из Application через контейнер
        if (self::$container !== null && self::$container->has(Application::class)) {
            return self::$container->get(Application::class)->getBasePath();
        }

        // 2. Fallback: поднимаемся по дереву каталогов, ищем папку, где есть 'vendor' и 'app'
        $currentDir = dirname(__DIR__);
        while ($currentDir !== dirname($currentDir)) {
            if (is_dir($currentDir . '/vendor') && is_dir($currentDir . '/app')) {
                return $currentDir;
            }
            $currentDir = dirname($currentDir);
        }

        // 3. Крайний случай (если ядро используется изолированно)
        return dirname(__DIR__, 2);
    }

    /**
     * Получить логгер из контейнера с fallback на глобальный контейнер.
     */
	private static function getLogger(): Logger
    {
        if (self::$container === null) {
            throw new \RuntimeException(
                'Container not initialized for Asset. ' .
                'Call \W3a\Core\Asset::setContainer($container) in Application::bootstrap() first.'
            );
        }
        return self::$container->get(Logger::class);
    }

    /**
     * Получить имя активной темы.
     * Сначала пытается получить из Config сервиса, затем из файла конфигурации.
     */
    private static function getActiveTheme(): string
    {
        if (self::$activeTheme !== null) {
            return self::$activeTheme;
        }

        try {
            if (self::$container !== null && self::$container->has(Config::class)) {
                self::$activeTheme = (string)self::$container->get(Config::class)->get('app.theme', 'default');
                return self::$activeTheme;
            }
        } catch (\Throwable $e) {
            // Игнорируем ошибки контейнера и переходим к fallback
        }

        // Используем getBasePath() вместо dirname(__DIR__)
        $configPath = self::getBasePath() . '/app/Config/app.php';
        if (file_exists($configPath)) {
            $config = require $configPath;
            self::$activeTheme = (string)($config['theme'] ?? 'default');
            return self::$activeTheme;
        }

        self::$activeTheme = 'default';
        return self::$activeTheme;
    }

    /**
     * Инициализация путей к целевым (скомпилированным) файлам.
     */
    private static function init(): void
    {
        // Используем getBasePath() вместо dirname(__DIR__, 2)
        $publicDir = self::getBasePath() . '/public';
        self::$distCssFile      = $publicDir . '/assets/css/app.min.css';
        self::$distAdminCssFile = $publicDir . '/assets/css/admin.min.css';
        self::$distJsFile       = $publicDir . '/assets/js/app.min.js';
    }

    // =========================================================================
    // ПУБЛИЧНЫЕ МЕТОДЫ ДЛЯ ШАБЛОНОВ (Генерация URL с кэш-бастингом)
    // =========================================================================

    public static function css(): string
    {
        self::init();
        if (self::isDevelopment()) {
            self::compileCssIfNeeded();
        }
        $version = file_exists(self::$distCssFile) ? filemtime(self::$distCssFile) : time();
        return "/assets/css/app.min.css?v=" . $version;
    }

    public static function adminCss(): string
    {
        self::init();
        if (self::isDevelopment()) {
            self::compileCssIfNeeded();
        }
        $version = file_exists(self::$distAdminCssFile) ? filemtime(self::$distAdminCssFile) : time();
        return "/assets/css/admin.min.css?v=" . $version;
    }

    public static function js(): string
    {
        self::init();
        if (self::isDevelopment()) {
            self::compileJsIfNeeded();
        }
        $version = file_exists(self::$distJsFile) ? filemtime(self::$distJsFile) : time();
        return "/assets/js/app.min.js?v=" . $version;
    }

    // =========================================================================
    // МЕТОДЫ ДЛЯ АДМИНКИ (Ручная пересборка)
    // =========================================================================

    public static function forceRebuild(): void
    {
        self::init();
        self::$discovered = [];
        foreach (['css', 'js'] as $extension) {
            self::clearManifest($extension);
        }
        self::buildCss();
        self::buildJs();
    }

    // =========================================================================
    // ВНУТРЕННЯЯ ЛОГИКА ОБНАРУЖЕНИЯ И СБОРКИ
    // =========================================================================

    private static function discoverFiles(string $extension): array
    {
        // In-memory кэш: в рамках одного запроса css()/adminCss()/js()
        // и build*() вызывают discoverFiles несколько раз — сканируем ФС один раз.
        if (isset(self::$discovered[$extension])) {
            return self::$discovered[$extension];
        }

        $files = null;

        // Файловый манифест экономим только в dev (в prod сканирование происходит
        // только при ручной пересборке forceRebuild(), где важна актуальность).
        if (self::isDevelopment()) {
            $files = self::loadManifest($extension);
        }

        if ($files === null) {
            $files = self::scanAssets($extension);
            if (self::isDevelopment()) {
                self::saveManifest($extension, $files);
            }
        }

        self::$discovered[$extension] = $files;
        return $files;
    }

    /**
     * Реальное сканирование файловой системы (рекурсивный обход).
     */
    private static function scanAssets(string $extension): array
    {
        $discovered = [];

        // Используем getBasePath()
        $modulesPath = self::getBasePath() . '/app/Modules';
        $theme = self::getActiveTheme();
        $themeAssetsPath = self::getBasePath() . "/themes/{$theme}/assets";

        if (is_dir($modulesPath)) {
            $discovered = array_merge($discovered, self::scanDirectory($modulesPath, $extension));
        }

        if (is_dir($themeAssetsPath)) {
            $discovered = array_merge($discovered, self::scanDirectory($themeAssetsPath, $extension));
        }

        usort($discovered, function ($a, $b) {
            $isCommonA = strpos($a, 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Common') !== false;
            $isCommonB = strpos($b, 'app' . DIRECTORY_SEPARATOR . 'Modules' . DIRECTORY_SEPARATOR . 'Common') !== false;

            if ($isCommonA && !$isCommonB) return -1;
            if (!$isCommonA && $isCommonB) return 1;

            return strcmp($a, $b);
        });

        return $discovered;
    }

    /**
     * Путь к файлу манифеста для расширения.
     */
    private static function manifestPath(string $extension): string
    {
        return self::getBasePath() . '/storage/cache/assets_' . $extension . '.php';
    }

    /**
     * Загрузка списка файлов из манифеста (null, если нет или устарел).
     */
    private static function loadManifest(string $extension): ?array
    {
        $file = self::manifestPath($extension);

        if (!is_file($file)) {
            return null;
        }

        $data = \W3a\Core\Support\PhpArrayFile::read($file);
        if (!is_array($data) || !isset($data['files'], $data['roots'])) {
            return null;
        }

        // Инвалидация по mtime корневых директорий: добавление/удаление модуля или темы.
        foreach ($data['roots'] as $root => $mtime) {
            if ((is_dir((string)$root) ? filemtime((string)$root) : 0) !== (int)$mtime) {
                return null;
            }
        }

        // В dev — короткий TTL, чтобы новые вложенные файлы подхватывались без ручной очистки.
        if (isset($data['checked_at']) && (time() - (int)$data['checked_at']) > self::DEV_MANIFEST_TTL) {
            return null;
        }

        return $data['files'];
    }

    /**
     * Атомарное сохранение манифеста.
     */
    private static function saveManifest(string $extension, array $files): void
    {
        $file = self::manifestPath($extension);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $roots = [];
        $modulesPath = self::getBasePath() . '/app/Modules';
        if (is_dir($modulesPath)) {
            $roots[$modulesPath] = filemtime($modulesPath);
        }
        $themePath = self::getBasePath() . '/themes/' . self::getActiveTheme();
        if (is_dir($themePath)) {
            $roots[$themePath] = filemtime($themePath);
        }

        $data = [
            'files' => $files,
            'roots' => $roots,
            'checked_at' => time(),
        ];

        \W3a\Core\Support\PhpArrayFile::write($file, $data);
    }

    /**
     * Удаление манифеста.
     */
    private static function clearManifest(string $extension): void
    {
        $file = self::manifestPath($extension);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private static function scanDirectory(string $directory, string $extension): array
    {
        $dirIterator = new RecursiveDirectoryIterator($directory);
        $iterator = new RecursiveIteratorIterator($dirIterator);
        $regex = new RegexIterator($iterator, '/^.+\.' . $extension . '$/i', RegexIterator::GET_MATCH);

        $files = [];
        foreach ($regex as $file) {
            $files[] = $file[0];
        }
        return $files;
    }

    private static function compileCssIfNeeded(): void
    {
        $cssFiles = self::discoverFiles('css');
        $needRebuild = false;
        $theme = strtolower(self::getActiveTheme());

        $mtimeApp = file_exists(self::$distCssFile) ? filemtime(self::$distCssFile) : 0;
        $mtimeAdmin = file_exists(self::$distAdminCssFile) ? filemtime(self::$distAdminCssFile) : 0;

        foreach ($cssFiles as $path) {
            if (file_exists($path)) {
                $isAdminFile = self::isAdminAsset($path, $theme);

                $targetMtime = $isAdminFile ? $mtimeAdmin : $mtimeApp;

                if (filemtime($path) > $targetMtime) {
                    $needRebuild = true;
                    break;
                }
            }
        }

        if ($needRebuild || $mtimeApp === 0 || $mtimeAdmin === 0) {
            self::buildCss();
        }
    }

    private static function buildCss(): void
    {
        $files = self::discoverFiles('css');

        $appCss = "/* Public CSS Bundle: " . date('Y-m-d H:i:s') . " */" . PHP_EOL;
        $adminCss = "/* Admin CSS Bundle: " . date('Y-m-d H:i:s') . " */" . PHP_EOL;

        $appCount = 0;
        $adminCount = 0;
        $rootDir = self::getBasePath();
        $theme = strtolower(self::getActiveTheme());

        foreach ($files as $path) {
            if (file_exists($path)) {
                $shortPath = str_replace($rootDir, '', $path);
                $content = "/* Source: {$shortPath} */" . PHP_EOL . file_get_contents($path) . PHP_EOL . PHP_EOL;

                // 🔥 ИСПОЛЬЗУЕМ НОВЫЙ НАДЕЖНЫЙ МЕТОД ПРОВЕРКИ 🔥
                if (self::isAdminAsset($path, $theme)) {
                    $adminCss .= $content;
                    $adminCount++;
                } else {
                    $appCss .= $content;
                    $appCount++;
                }
            }
        }

        $minify = function (string $css): string {
            $css = preg_replace('!/\*[^*]*\*+([^/*][^*]*\*+)*/!', '', $css);
            $css = str_replace(["\r\n", "\r", "\n", "\t"], '', $css);
            $css = preg_replace('/ {2,}/', ' ', $css);
            return str_replace([' {', '{ ', '; '], ['{', '{', ';'], $css);
        };

        $dir = dirname(self::$distCssFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(self::$distCssFile, $minify($appCss), LOCK_EX);
        file_put_contents(self::$distAdminCssFile, $minify($adminCss), LOCK_EX);

        $logger = self::getLogger();
        $logger->info("Asset Compiler: Сборка CSS завершена. app.min.css (файлов: {$appCount}), admin.min.css (файлов: {$adminCount}). Активная тема: " . self::getActiveTheme());
    }

    private static function compileJsIfNeeded(): void
    {
        $distMtime = file_exists(self::$distJsFile) ? filemtime(self::$distJsFile) : 0;
        $needRebuild = false;
        $files = self::discoverFiles('js');

        foreach ($files as $path) {
            if (file_exists($path) && filemtime($path) > $distMtime) {
                $needRebuild = true;
                break;
            }
        }
        
        if ($needRebuild || $distMtime === 0) {
            self::buildJs();
        }
    }

    private static function buildJs(): void
    {
        $compiled = "/* JavaScript Bundle: " . date('Y-m-d H:i:s') . " */" . PHP_EOL;
        $files = self::discoverFiles('js');

        // Используем getBasePath()
        $priorityFile = self::getBasePath() . '/app/Modules/Common/Views/js/core_utils.js';
        
        $orderedFiles = [];
        $otherFiles = [];
        
        foreach ($files as $path) {
            if (realpath($path) === realpath($priorityFile)) {
                array_unshift($orderedFiles, $path);
            } else {
                $otherFiles[] = $path;
            }
        }
        
        $files = array_merge($orderedFiles, $otherFiles);
        
        // Используем getBasePath()
        $rootDir = self::getBasePath();

        foreach ($files as $path) {
            if (file_exists($path)) {
                $shortPath = str_replace($rootDir, '', $path);
                $compiled .= ";" . PHP_EOL . "/* Source: {$shortPath} */" . PHP_EOL . file_get_contents($path) . PHP_EOL;
            }
        }

        $compiled = preg_replace('!/\*[^*]*\*+([^/*][^*]*\*+)*/!', '', $compiled);
        $compiled = preg_replace('/^[ \t]*\/\/.*$/m', '', $compiled);
        $compiled = str_replace("\t", " ", $compiled);
        $compiled = preg_replace('/ +/', ' ', $compiled);

        $dir = dirname(self::$distJsFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents(self::$distJsFile, $compiled, LOCK_EX);

        $logger = self::getLogger();
        $logger->info("Asset Compiler: JS сборка обновлена. Всего файлов: " . count($files) . ". Активная тема: " . self::getActiveTheme());
    }

    private static function isDevelopment(): bool
    {
        try {
            if (self::$container !== null && self::$container->has(Config::class)) {
                return self::$container->get(Config::class)->get('app.env', 'development') === 'development';
            }
        } catch (\Throwable $e) {
            // Fallback
        }
        
        // Используем getBasePath()
        $configPath = self::getBasePath() . '/app/Config/app.php';
        if (file_exists($configPath)) {
            $config = require $configPath;
            return ($config['env'] ?? 'development') === 'development';
        }
        
        return true;
    }
	
    /**
     * Проверяет, относится ли файл ассетов к панели администратора.
     * Использует нормализацию пути для надежности на Windows/Linux и независимости от регистра.
     */
    private static function isAdminAsset(string $path, ?string $theme = null): bool
    {
        // Приводим путь к нижнему регистру и заменяем обратные слеши на прямые
        $normalizedPath = strtolower(str_replace('\\', '/', $path));
        $theme = strtolower($theme ?? self::getActiveTheme());

        return (strpos($normalizedPath, 'app/modules/admin') !== false) ||
               (strpos($normalizedPath, "themes/{$theme}/admin") !== false);
    }
}