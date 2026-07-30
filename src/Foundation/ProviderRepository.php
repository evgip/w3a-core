<?php

declare(strict_types=1);

namespace W3a\Core\Foundation;

class ProviderRepository
{
    private string $basePath;
    private Container $container;
    private Config $config;

    public function __construct(string $basePath, Container $container, Config $config)
    {
        $this->basePath = $basePath;
        $this->container = $container;
        $this->config = $config;
    }

    /**
     * Загружает и регистрирует провайдеры из переданного списка.
     */
    public function load(array $providers): void
    {
        $moduleProviders = $this->getModuleProvidersData();
        $allProviders = array_merge($providers, array_column($moduleProviders, 'class'));

        $bootableProviders = [];

        foreach ($allProviders as $providerClass) {
            if (!class_exists($providerClass)) {
                continue;
            }

            $provider = new $providerClass();
            
            // Регистрация (передаем container, и request если метод его принимает)
            $reflection = new \ReflectionMethod($provider, 'register');
            $params = $reflection->getParameters();
            if (count($params) >= 2) {
                $provider->register($this->container, $this->container->get(\W3a\Core\Http\Request::class));
            } else {
                $provider->register($this->container);
            }

            if (method_exists($provider, 'boot')) {
                $bootableProviders[] = $provider;
            }
        }

        // Фаза Boot
        foreach ($bootableProviders as $provider) {
            $provider->boot();
        }
    }

    private function getModuleProvidersData(): array
    {
        $modulesPath = $this->basePath . '/app/Modules';
        $cacheFile = $this->basePath . '/storage/cache/providers.php';
        $env = $this->config->get('app.env', 'development');

        // В продакшене используем кэш
        if ($env === 'production' && file_exists($cacheFile)) {
            $cache = @include $cacheFile;
            if (is_array($cache) && isset($cache['providers'])) {
                return $cache['providers'];
            }
        }

        // В разработке проверяем актуальность кэша
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

        return $this->rebuildCache($cacheFile, $modulesPath);
    }

    private function rebuildCache(string $cacheFile, string $modulesPath): array
    {
        $providers = [];

        // 1. Локальные модули
        if (is_dir($modulesPath)) {
            foreach (array_diff(scandir($modulesPath), ['.', '..']) as $module) {
                $providerClass = "App\\Modules\\{$module}\\ModuleServiceProvider";
                if (class_exists($providerClass)) {
                    $configPath = $modulesPath . '/' . $module . '/Config';
                    $providers['local_' . $module] = [
                        'class' => $providerClass,
                        'config_path' => is_dir($configPath) ? $configPath : null,
                    ];
                    // Сразу добавляем конфиги модуля в Config
                    if (is_dir($configPath)) {
                        $this->config->addModulePath(strtolower($module), $configPath);
                    }
                }
            }
        }

        // 2. Composer Package Discovery
        $installedJsonPath = $this->basePath . '/vendor/composer/installed.json';
        if (file_exists($installedJsonPath)) {
            $installed = json_decode(file_get_contents($installedJsonPath), true);
            $packages = $installed['packages'] ?? $installed;

            foreach ($packages as $package) {
                if (isset($package['extra']['w3a-core']['providers']) && is_array($package['extra']['w3a-core']['providers'])) {
                    foreach ($package['extra']['w3a-core']['providers'] as $providerClass) {
                        if (class_exists($providerClass)) {
                            $packageName = $package['name'];
                            $vendorPath = $this->basePath . '/vendor/' . str_replace('/', DIRECTORY_SEPARATOR, $packageName);
                            $configPath = is_dir($vendorPath . '/Config') ? $vendorPath . '/Config' : null;

                            $providers['pkg_' . md5($providerClass)] = [
                                'class' => $providerClass,
                                'config_path' => $configPath,
                            ];
                            
                            if (is_dir($configPath)) {
                                $this->config->addModulePath($packageName, $configPath);
                            }
                        }
                    }
                }
            }
        }

        $this->writeCacheAtomic($cacheFile, [
            'providers' => $providers,
            'cache_time' => time(),
        ]);

        return $providers;
    }

    private function writeCacheAtomic(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $code = "<?php\nreturn " . var_export($data, true) . ";\n";
        $tmp = $file . '.tmp.' . getmypid();
        
        file_put_contents($tmp, $code, LOCK_EX);
        rename($tmp, $file);
        
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }
}