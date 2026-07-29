<?php

declare(strict_types=1);

namespace W3a\Core;

use W3a\Core\Events\EventDispatcher;
use W3a\Core\Exceptions\HttpException;
use W3a\Core\Exceptions\JsonResponseException;
use W3a\Core\Exceptions\RedirectException;
use W3a\Core\Exceptions\CsrfException;
use W3a\Core\Contracts\ErrorHandlerInterface;

class Application
{
    private Container $container;
    private Request $request;
    private Config $config;
    
    /**
     * Базовый путь к корню основного проекта (например, D:\OSPanel\home\soc.local)
     */
    private string $basePath;

    /**
     * Конструктор позволяет явно задать базовый путь, или вычисляет его автоматически.
     * __DIR__ = w3a-core/src
     * dirname(__DIR__, 2) = корень проекта
     */
    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? dirname(__DIR__, 2);
    }

    /**
     * Путь к кэшу списка провайдеров
     */
    private function getProvidersCachePath(): string
    {
        return $this->basePath . '/storage/cache/providers.php';
    }

    /**
     * Получить базовый путь к корню проекта.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Путь к директории модулей основного приложения
     */
    private function getModulesPath(): string
    {
        return $this->basePath . '/app/Modules';
    }

    public function bootstrap(): self
    {
        Benchmark::start();

        // 1. Сначала инициализируем Конфиг, чтобы другие сервисы могли его использовать
        $configPath = $this->basePath . '/app/Config';
        $this->config = new Config($configPath);

        // 2. Инициализируем Lang, передавая ему конфиг и базовый путь
        Lang::init($this->config, $this->basePath);

        // 3. Создаем и настраиваем DI-контейнер
        $this->container = new Container();
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Config::class, $this->config);
		$this->container->instance(Application::class, $this);
		
		// Передаем контейнер в статический класс Asset
        \W3a\Core\Asset::setContainer($this->container);


		// Инициализируем глобальный хелпер container()
        container($this->container); 

        // 4. Настройка обработки ошибок PHP
        $this->setupErrorHandling();

        // 5. Request
        $this->request = new Request();
        $this->container->singleton(Request::class, fn() => $this->request);

        // 6. Logger (берем путь из конфига или используем дефолтный)
        $logFile = $this->config->get('app.log_path', $this->basePath . '/storage/logs/app.log');
        $logger = new Logger($logFile);
        $this->container->singleton(Logger::class, fn() => $logger);

        // 7. Event Dispatcher
        $eventDispatcher = new EventDispatcher($logger);
        $this->container->singleton(EventDispatcher::class, fn() => $eventDispatcher);

        // 8. Регистрация всех провайдеров (Core, App, Modules)
        $this->registerProviders();
        
        // 9. Проверки безопасности
        $this->sendSecurityHeaders();
        $this->checkFirewall();

        return $this;
    }

    private function sendSecurityHeaders(): void
    {
        try {
            $security = $this->container->get(Security::class);
            $security->sendCspHeader();
        } catch (\Throwable $e) {
            error_log("Security headers skipped: " . $e->getMessage());
        }
    }

    private function checkFirewall(): void
    {
        // Контейнер сам внедрит зависимости (Database, IpResolver) в Firewall
        $firewall = $this->container->make(Firewall::class);
        $firewall->check();
    }

    /**
     * Регистрация провайдеров
     */
    private function registerProviders(): void
    {
        // 1. Регистрируем чистые сервисы Ядра (Core)
        $coreProvider = new CoreServiceProvider();
        $coreProvider->register($this->container, $this->request);

        // 2. "Склеиваем" интерфейсы Core с реализациями из Modules
        $appProvider = new \App\AppServiceProvider();
        $appProvider->register($this->container);

        // 3. Получаем список провайдеров самих модулей
        $moduleProvidersData = $this->getModuleProvidersData();

        // 4. Регистрируем и собираем модульные провайдеры для boot
        $providers = [];
        foreach ($moduleProvidersData as $module => $data) {
            $providerClass = $data['class'];
            $configPath = $data['config_path'] ?? null;

            if ($configPath !== null && is_dir($configPath)) {
                $this->config->addModulePath(strtolower($module), $configPath);
            }

            $provider = new $providerClass();
            $provider->register($this->container);
            $providers[] = $provider;
        }

        // 5. Boot phase для модулей
        foreach ($providers as $provider) {
            if (method_exists($provider, 'boot')) {
                $provider->boot();
            }
        }
    }

    private function getModuleProvidersData(): array
    {
        $modulesPath = $this->getModulesPath();
        $cacheFile = $this->getProvidersCachePath();

        if (!is_dir($this->basePath . '/vendor')) {
            // Если vendor еще не установлен, возвращаем только локальные
            return $this->rebuildProvidersCache($cacheFile, $modulesPath);
        }

        $env = $this->config->get('app.env', 'development');

        // В продакшене всегда используем кэш, если он есть
        if ($env === 'production' && file_exists($cacheFile)) {
            $cache = @include $cacheFile;
            if (is_array($cache) && isset($cache['providers'])) {
                return $cache['providers'];
            }
        }

        // В разработке пересобираем кэш, если изменился composer.lock или папка app/Modules
        $lockFile = $this->basePath . '/composer.lock';
        $lockMtime = file_exists($lockFile) ? filemtime($lockFile) : 0;
        $modulesMtime = is_dir($modulesPath) ? filemtime($modulesPath) : 0;
        $checkMtime = max($lockMtime, $modulesMtime);

        if (file_exists($cacheFile)) {
            $cache = @include $cacheFile;
            if (is_array($cache) && isset($cache['cache_time']) && $cache['cache_time'] >= $checkMtime) {
                return $cache['providers'];
            }
        }

        return $this->rebuildProvidersCache($cacheFile, $modulesPath);
    }

    private function isProvidersCacheStale(string $cacheFile, string $modulesPath): bool
    {
        $cache = @include $cacheFile;
        if (!is_array($cache) || !isset($cache['cache_time'])) {
            return true;
        }

        $cacheTime = $cache['cache_time'];

        if (filemtime($modulesPath) > $cacheTime) {
            return true;
        }

        $modules = array_diff(scandir($modulesPath), ['.', '..']);
        foreach ($modules as $module) {
            $modulePath = $modulesPath . '/' . $module;
            if (!is_dir($modulePath)) {
                continue;
            }

            $providerFile = $modulePath . '/ModuleServiceProvider.php';
            if (file_exists($providerFile) && filemtime($providerFile) > $cacheTime) {
                return true;
            }

            $configPath = $modulePath . '/Config';
            if (is_dir($configPath) && filemtime($configPath) > $cacheTime) {
                return true;
            }
        }

        return false;
    }

    private function rebuildProvidersCache(string $cacheFile, string $modulesPath): array
    {
        $providers = [];

        // 1. СКАНИРОВАНИЕ LOCAL МОДУЛЕЙ (как было, для app/Modules)
        if (is_dir($modulesPath)) {
            $modules = array_diff(scandir($modulesPath), ['.', '..']);
            foreach ($modules as $module) {
                $providerClass = "App\\Modules\\{$module}\\ModuleServiceProvider";
                if (class_exists($providerClass)) {
                    $configPath = $modulesPath . '/' . $module . '/Config';
                    $providers['local_' . $module] = [ // Добавил префикс, чтобы избежать коллизий имен
                        'class' => $providerClass,
                        'config_path' => is_dir($configPath) ? $configPath : null,
                        'source' => 'local'
                    ];
                }
            }
        }

        // 2. СКАНИРОВАНИЕ COMPOSER ПАКЕТОВ (Package Discovery!)
        $installedJsonPath = $this->basePath . '/vendor/composer/installed.json';
        if (file_exists($installedJsonPath)) {
            $installed = json_decode(file_get_contents($installedJsonPath), true);
            $packages = $installed['packages'] ?? $installed; // Учет разных форматов installed.json

            foreach ($packages as $package) {
                // Ищем специальную секцию в composer.json пакета: "extra": { "w3a-core": { "providers": [...] } }
                if (isset($package['extra']['w3a-core']['providers']) && is_array($package['extra']['w3a-core']['providers'])) {
                    foreach ($package['extra']['w3a-core']['providers'] as $providerClass) {
                        if (class_exists($providerClass)) {
                            // Пытаемся угадать путь к конфигам пакета (стандарт: vendor/имя_пакета/Config)
                            $packageName = $package['name']; // например, "evgip/w3a-auth"
                            $vendorPath = $this->basePath . '/vendor/' . str_replace('/', '/', $packageName);
                            $configPath = is_dir($vendorPath . '/Config') ? $vendorPath . '/Config' : null;

                            // Используем имя пакета + класс как уникальный ключ
                            $cacheKey = 'pkg_' . md5($providerClass);
                            $providers[$cacheKey] = [
                                'class' => $providerClass,
                                'config_path' => $configPath,
                                'source' => 'vendor',
                                'package' => $packageName
                            ];
                        }
                    }
                }
            }
        }

        $cacheData = [
            'providers' => $providers,
            'cache_time' => time(),
            'generated_at' => date('Y-m-d H:i:s'),
        ];

        $this->writeCacheAtomic($cacheFile, $cacheData);

        return $providers;
    }

    private function writeCacheAtomic(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create cache directory: {$dir}");
            }
        }

        $code = "<?php\n";
        $code .= "// Auto-generated at {$data['generated_at']}\n";
        $code .= "// DO NOT EDIT - regenerated automatically\n\n";
        $code .= "return " . var_export($data, true) . ";\n";

        $tmp = $file . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $code, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write cache file: {$file}");
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to rename cache file: {$tmp} -> {$file}");
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }

    public function rebuildProvidersCacheManual(): array
    {
        $modulesPath = $this->getModulesPath();
        $cacheFile = $this->getProvidersCachePath();

        if (!is_dir($modulesPath)) {
            return [
                'success' => false,
                'providers_count' => 0,
                'cache_file' => $cacheFile,
                'providers' => [],
                'error' => 'Modules directory not found',
            ];
        }

        $providers = $this->rebuildProvidersCache($cacheFile, $modulesPath);

        return [
            'success' => true,
            'providers_count' => count($providers),
            'cache_file' => $cacheFile,
            'providers' => array_keys($providers),
        ];
    }

    public function run(): void
    {
        try {
            // Получаем Router из контейнера!
            // Это гарантирует, что мы используем тот же экземпляр, 
            // в котором AppServiceProvider уже зарегистрировал все группы middleware.
            $router = $this->container->get(Router::class);
            
            $router->dispatch();
        } catch (RedirectException $e) {
            $this->handleRedirect($e);
        } catch (JsonResponseException $e) {
            $this->handleJsonResponse($e);
        } catch (CsrfException $e) {
            $this->handleCsrfException($e);
        } catch (HttpException $e) {
            $this->handleHttpException($e);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    private function handleRedirect(RedirectException $e): void
    {
        http_response_code($e->statusCode);
        header('Location: ' . $e->url);
        exit;
    }

    private function handleJsonResponse(JsonResponseException $e): void
    {
        http_response_code($e->getStatusCode());
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($e->getData(), JSON_UNESCAPED_UNICODE);
    }

    private function handleCsrfException(CsrfException $e): void
    {
        http_response_code(419);
        $context = $e->getContext();
        $isAjax = $context['is_ajax'] ?? false;
        
        $this->logError('warning', 'CSRF validation failed', [
            'url' => $context['url'] ?? $this->request->getUri(),
            'method' => $context['method'] ?? $this->request->getMethod(),
            'ip' => $context['ip'] ?? $this->request->getIp(),
            'is_ajax' => $isAjax,
        ]);
        
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => 'CSRF token validation failed',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        $this->renderErrorPage('csrf', $e->getMessage());
    }

    private function handleHttpException(HttpException $e): void
    {
        http_response_code($e->getStatusCode());
        $logLevel = $e->getStatusCode() >= 500 ? 'error' : 'warning';
        
        $this->logError($logLevel, $e->getMessage(), [
            'status' => $e->getStatusCode(),
            'url' => $this->request->getUri(),
            'method' => $this->request->getMethod(),
            'ip' => $this->request->getIp(),
        ]);

        $method = match ($e->getStatusCode()) {
            400 => 'badRequest',
            403 => 'forbidden',
            404 => 'notFound',
            419 => 'csrf',
            default => 'show',
        };
        
        $this->renderErrorPage($method, $e->getMessage(), $e->getStatusCode());
    }

    private function setupErrorHandling(): void
    {
        $env = $this->config->get('app.env', 'development');
        $isProduction = ($env === 'production');

        error_reporting(E_ALL);

        if ($isProduction) {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            ini_set('log_errors', '1');
            
            $logPath = $this->config->get('app.php_error_log_path', $this->basePath . '/storage/logs/php_errors.log');
            ini_set('error_log', $logPath);
        } else {
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
        }
    }

    private function handleException(\Throwable $e): void
    {
        $errorMessage = $e->getMessage() . " в файле " . $e->getFile() . " на строке " . $e->getLine();

        $this->logError('error', $errorMessage, [
            'trace' => $e->getTraceAsString(),
            'url' => $this->request->getUri(),
            'method' => $this->request->getMethod(),
            'ip' => $this->request->getIp(),
        ]);

        $isDevelopment = $this->config->get('app.env', 'development') === 'development';
        http_response_code(500);

        if ($isDevelopment) {
            $this->showDevelopmentError($e);
        } else {
            $this->renderErrorPage('serverError', "Извините, на сервере произошла внутренняя ошибка. Инженеры уже уведомлены.");
        }
    }

    private function showDevelopmentError(\Throwable $e): void
    {
        echo '<div class="alert is-success">';
        echo '<h2>💥 Ошибка разработки (Development Mode):</h2>';
        echo '<strong>Сообщение:</strong> ' . htmlspecialchars($e->getMessage()) . '<br><br>';
        echo '<strong>Файл:</strong> ' . htmlspecialchars($e->getFile()) . ' (строка ' . $e->getLine() . ')<br><br>';
        echo '<strong>Стек вызовов записан в storage/logs/app.log</strong>';
        echo '</div>';
    }

    /**
     * Рендер страницы ошибки через ErrorHandlerInterface
     */
    private function renderErrorPage(string $method, string $message, int $code = 500): void
    {
        try {
            $handler = $this->container->get(ErrorHandlerInterface::class);
            
            $codeMap = [
                'badRequest' => 400,
                'forbidden' => 403,
                'notFound' => 404,
                'csrf' => 419,
                'serverError' => 500,
                'show' => $code
            ];
            
            $httpCode = $codeMap[$method] ?? $code;
            $handler->render($httpCode, $message);
            
        } catch (\Throwable $e) {
            http_response_code($code);
            echo "<h1>Error</h1><p>" . htmlspecialchars($message) . "</p>";
            $this->logError('critical', "ErrorHandler failed", ['original_error' => $e->getMessage()]);
        }
    }

    /**
     * Безопасное логирование ошибок
     */
    private function logError(string $level, string $message, array $context = []): void
    {
        try {
            $logger = $this->container->get(Logger::class);
            $logger->$level($message, $context);
        } catch (\Throwable $logError) {
            error_log("[{$level}] {$message} " . json_encode($context));
        }
    }
}