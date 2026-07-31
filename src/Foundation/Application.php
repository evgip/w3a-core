<?php

declare(strict_types=1);

namespace W3a\Core\Foundation;

use W3a\Core\Http\Request;
use W3a\Core\Exceptions\ExceptionHandler;
use W3a\Core\Support\Lang;
use W3a\Core\Support\Benchmark;
use W3a\Core\View\Asset;

/**
 * Главный класс приложения (Application).
 * 
 * Отвечает за:
 * - Инициализацию DI-контейнера и базовых сервисов
 * - Загрузку конфигурации и языковых файлов
 * - Регистрацию всех сервис-провайдеров (Core, App, Modules)
 * - Запуск роутинга и обработку исключений
 * 
 * НЕ содержит бизнес-логики — делегирует её провайдерам и ExceptionHandler.
 */
class Application
{
    private Container $container;
    private string $basePath;
    private array $providers;

    /**
     * Конструктор приложения.
     *
     * @param string $basePath Абсолютный путь к корню проекта (где лежат vendor/, app/, storage/)
     * @param array $providers Список сервис-провайдеров для загрузки.
     *                         Если не передан — используется только CoreServiceProvider.
     */
    public function __construct(string $basePath, array $providers = [])
    {
        $this->basePath = $basePath;
        $this->providers = $providers ?: [CoreServiceProvider::class];
    }

    /**
     * ═══════════════════════════════════════════════════════════
     *  BOOTSTRAP: Полная инициализация приложения
     * ═══════════════════════════════════════════════════════════
     * 
     * Выполняет следующие шаги:
     * 1. Запуск бенчмарка (для измерения времени выполнения)
     * 2. Создание DI-контейнера и регистрация базовых сервисов
     * 3. Инициализация глобального хелпера container()
     * 4. Передача контейнера в статический класс Asset
     * 5. Загрузка конфигурации (ленивая, через Config)
     * 6. Инициализация системы переводов (Lang)
     * 7. Создание Request и регистрация в контейнере
     * 8. Настройка обработки ошибок PHP (error_reporting, display_errors)
     * 9. Регистрация сервисов Ядра (CoreServiceProvider)
     * 10. Регистрация провайдеров Приложения и Модулей (через ProviderRepository)
     * 11. Отправка security-заголовков (CSP)
     * 12. Проверка Firewall (блокировка IP)
     */
    public function bootstrap(): self
    {
        // ═══════════════════════════════════════════
        // 1. ЗАПУСК БЕНЧМАРКА
        // ═══════════════════════════════════════════
        // Фиксируем время начала выполнения запроса.
        // Позже Benchmark::renderStats() покажет его в футере страницы.
        Benchmark::start();

        // ═══════════════════════════════════════════
        // 2. СОЗДАНИЕ DI-КОНТЕЙНЕРА
        // ═══════════════════════════════════════════
        $this->container = new Container();
        
        // Регистрируем сам Application и Container как singleton'ы,
        // чтобы любой сервис мог получить к ним доступ через DI.
        $this->container->instance(Application::class, $this);
        $this->container->instance(Container::class, $this->container);

        // ═══════════════════════════════════════════
        // 3. ИНИЦИАЛИЗАЦИЯ ГЛОБАЛЬНОГО ХЕЛПЕРА
        // ═══════════════════════════════════════════
        // Функции config(), env(), container() работают через статическую переменную.
        // Без этой строки они упадут с ошибкой "Container not initialized".
        container($this->container);

        // ═══════════════════════════════════════════
        // 4. ПЕРЕДАЧА КОНТЕЙНЕРА В ASSET
        // ═══════════════════════════════════════════
        // Asset — статический класс, не регистрируется в DI.
        // Ему нужен контейнер, чтобы получать Logger и Config.
        Asset::setContainer($this->container);

        // ═══════════════════════════════════════════
        // 5. ЗАГРУЗКА КОНФИГУРАЦИИ
        // ═══════════════════════════════════════════
        // Config использует ленивую загрузку: файлы читаются с диска
        // только при первом обращении к их ключам.
        // Теперь мы передаем два пути: конфиги приложения и конфиги ядра.
        // Настройки приложения будут иметь приоритет и перезаписывать настройки ядра.
        $coreConfigPath = dirname(__DIR__, 2) . '/config'; // Путь к config внутри w3a-core
        $appConfigPath = $this->basePath . '/app/Config';
        
        $config = new Config($appConfigPath, $coreConfigPath);
        $this->container->instance(Config::class, $config);

        // ═══════════════════════════════════════════
        // 6. ИНИЦИАЛИЗАЦИЯ ПЕРЕВОДОВ (LANG)
        // ═══════════════════════════════════════════
        // Lang читает app/Config/app.php (ключ 'lang'), чтобы понять,
        // какой файл из app/Lang/ загружать (ru.php, en.php и т.д.).
        Lang::init($config, $this->basePath);
        $this->container->instance(Lang::class, new Lang());

        // ═══════════════════════════════════════════
        // 7. СОЗДАНИЕ REQUEST
        // ═══════════════════════════════════════════
        // Request парсит $_GET, $_POST, $_SERVER и предоставляет
        // удобный API для работы с текущим HTTP-запросом.
        $request = new Request();
        $this->container->instance(Request::class, $request);

        // ═══════════════════════════════════════════
        // 8. НАСТРОЙКА ОБРАБОТКИ ОШИБОК PHP
        // ═══════════════════════════════════════════
        // В продакшене: ошибки не выводятся в браузер, пишутся в лог.
        // В разработке: ошибки выводятся напрямую (удобно для отладки).
        $this->setupErrorHandling();

        // ═══════════════════════════════════════════
        // 9. РЕГИСТРАЦИЯ СЕРВИСОВ ЯДРА (CORE)
        // ═══════════════════════════════════════════
        // CoreServiceProvider регистрирует все базовые сервисы фреймворка:
        // Database, Session, Logger, Router, Security, Cache и т.д.
        $coreProvider = new CoreServiceProvider();
        $coreProvider->register($this->container, $request);

        // ═══════════════════════════════════════════
        // 10. РЕГИСТРАЦИЯ ПРОВАЙДЕРОВ ПРИЛОЖЕНИЯ И МОДУЛЕЙ
        // ═══════════════════════════════════════════
        // ProviderRepository сканирует:
        // - app/Modules/*/ModuleServiceProvider.php (локальные модули)
        // - vendor/composer/installed.json (Composer-пакеты с Package Discovery)
        // Результаты кэшируются в storage/cache/providers.php.
        $providerRepo = new ProviderRepository($this->basePath, $this->container, $config);
        $providerRepo->load($this->providers);

        // ═══════════════════════════════════════════
        // 11. ОТПРАВКА SECURITY-ЗАГОЛОВКОВ
        // ═══════════════════════════════════════════
        // Отправляет Content-Security-Policy и другие заголовки
        // для защиты от XSS, clickjacking и других атак.
        $this->sendSecurityHeaders();

        // ═══════════════════════════════════════════
        // 12. ПРОВЕРКА FIREWALL
        // ═══════════════════════════════════════════
        // Проверяет IP пользователя по списку заблокированных.
        // Если IP в бане — выбрасывает HttpException(403).
        $this->checkFirewall();

        return $this;
    }

    /**
     * ═══════════════════════════════════════════════════════════
     *  RUN: Запуск роутинга и обработка исключений
     * ═══════════════════════════════════════════════════════════
     * 
     * Вся обработка ошибок делегирована классу ExceptionHandler.
     * Это позволяет держать метод run() коротким и читаемым.
     */
    public function run(): void
    {
        try {
            // Получаем Router из контейнера и запускаем диспетчеризацию.
            // Router сам найдёт нужный контроллер, применит middleware и выполнит action.
            $this->container->get(\W3a\Core\Http\Router::class)->dispatch();
        } catch (\Throwable $e) {
            // Все исключения (Redirect, Json, Csrf, Http, Fatal)
            // обрабатываются в одном месте — ExceptionHandler.
            $this->container->get(ExceptionHandler::class)->handle($e);
        }
    }

    /**
     * Получить DI-контейнер приложения.
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Получить базовый путь к корню проекта.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    // ═══════════════════════════════════════════════════════════
    //  ВНУТРЕННИЕ МЕТОДЫ
    // ═══════════════════════════════════════════════════════════

    /**
     * Настройка обработки ошибок PHP в зависимости от окружения.
     * 
     * В продакшене:
     * - Ошибки НЕ выводятся в браузер (display_errors = 0)
     * - Ошибки пишутся в storage/logs/php_errors.log
     * 
     * В разработке:
     * - Ошибки выводятся напрямую (display_errors = 1)
     * - Удобно для отладки, но небезопасно на продакшене
     */
    private function setupErrorHandling(): void
    {
        $env = $this->container->get(Config::class)->get('app.env', 'development');
        $isProduction = ($env === 'production');

        error_reporting(E_ALL);

        if ($isProduction) {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            ini_set('log_errors', '1');
            
            $logPath = $this->container->get(Config::class)
                ->get('app.php_error_log_path', $this->basePath . '/storage/logs/php_errors.log');
            ini_set('error_log', $logPath);
        } else {
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
        }
    }

    /**
     * Отправка security-заголовков (CSP и др.).
     * 
     * Если Security не зарегистрирован или упал — логируем ошибку
     * и продолжаем работу (сайт не должен падать из-за CSP).
     */
    private function sendSecurityHeaders(): void
    {
        try {
            $security = $this->container->get(\W3a\Core\Security\Security::class);
            $security->sendCspHeader();
        } catch (\Throwable $e) {
            error_log("Security headers skipped: " . $e->getMessage());
        }
    }

    /**
     * Проверка IP-адреса через Firewall.
     * 
     * Если IP в бане — Firewall выбросит HttpException(403),
     * который будет обработан в ExceptionHandler.
     */
    private function checkFirewall(): void
    {
        try {
            $firewall = $this->container->make(\W3a\Core\Security\Firewall::class);
            $firewall->check();
        } catch (\Throwable $e) {
            error_log("Firewall check skipped: " . $e->getMessage());
        }
    }
}