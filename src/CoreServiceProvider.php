<?php

declare(strict_types=1);

namespace W3a\Core;

use W3a\Core\Events\EventDispatcher;

class CoreServiceProvider
{
    public function register(Container $container, ?Request $request = null): void
    {
        // 1. Request
        if ($request !== null) {
            $container->singleton(Request::class, function ($container) use ($request) {
                $request->setSession($container->get(Session::class));
                $request->setAudit($container->get(Audit::class));
                $request->setContainer($container);
                return $request;
            });
        }

        // 2. Database
        $container->singleton(Database::class, function ($container) {
            $config = $container->get(Config::class);
            return new Database($config->getArray('config.database', []));
        });

        // 3. Session
        $container->singleton(Session::class, fn() => new Session());

        // 4. Logger
        $container->singleton(Logger::class, function ($container) {
            $config = $container->get(Config::class);
            $app = $container->get(Application::class);
            
            // Если путь не указан в конфиге, используем basePath приложения
            $logFile = $config->get('config.app.log_path', $app->getBasePath() . '/storage/logs/app.log');
            return new Logger($logFile);
        });

        // 5. IpResolver
        $container->singleton(IpResolver::class, function ($container) {
            $config = $container->get(Config::class);
            $trustedProxies = $config->getArray('config.app.trusted_proxies', []);
            return new IpResolver($trustedProxies);
        });

        // 6. Audit
        $container->singleton(Audit::class, function ($container) {
            return new Audit(
                $container->get(\W3a\Core\Contracts\AuditStorageInterface::class),
                $container->get(Session::class),
                $container->get(IpResolver::class)
            );
        });

        // 7. Validator
        $container->bind(Validator::class, function ($container) {
            return new Validator($container->get(Database::class));
        });

        // 8. RateLimiter
        $container->singleton(RateLimiter::class, function ($container) {
            return new RateLimiter(
                $container->get(\W3a\Core\Contracts\RateLimitStorageInterface::class),
                $container->get(Config::class),
                $container->get(Request::class),
                $container->get(IpResolver::class),
                $container->get(\W3a\Core\Contracts\UserIdProviderInterface::class)
            );
        });

        // 9. Firewall
        $container->singleton(Firewall::class, function ($container) {
            return new Firewall(
                $container->get(\W3a\Core\Contracts\BannedIpRepositoryInterface::class),
                $container->get(IpResolver::class)
            );
        });

        // 10. Router
        $container->singleton(Router::class, function ($container) {
            $app = $container->get(Application::class);
            $basePath = $app->getBasePath();
            
            return new Router(
                $container->get(Request::class),
                $container,
                $container->get(Config::class),
                $basePath
            );
        });

        // 11. Security
        $container->singleton(Security::class, function ($container) {
            return new Security($container->get(Logger::class));
        });

        // 12. Container (сам себя)
        $container->instance(Container::class, $container);

        // 13. Event Dispatcher
        if (!$container->has(EventDispatcher::class)) {
            $container->singleton(EventDispatcher::class, fn() => new EventDispatcher());
        }

        // 14. View & ViewFinder
        $container->singleton(View::class, fn() => new View());
        $container->singleton(ViewFinder::class, function ($container) {
            $app = $container->get(Application::class);
            $basePath = $app->getBasePath();
            
            return new ViewFinder(
                $container->get(Config::class),
                $basePath 
            );
        });

        // 15. Cache (Использует basePath из Application)
        $container->singleton(\W3a\Core\Cache\FileCache::class, function ($container) {
            $config = $container->get(Config::class);
            $app = $container->get(Application::class); // Получаем экземпляр Application
            
            $cacheDir = $config->get('config.cache.file.path', $app->getBasePath() . '/storage/cache/data');
            return new \W3a\Core\Cache\FileCache($cacheDir);
        });

        $container->singleton(\W3a\Core\Cache\DatabaseCache::class, function ($container) {
            $config = $container->get(Config::class);
            return new \W3a\Core\Cache\DatabaseCache(
                $container->get(Database::class),
                $container->get(\W3a\Core\Cache\FileCache::class),
                $config->getBool('config.cache.database.enabled', true),
                $config->getInt('config.cache.database.ttl', 3600)
            );
        });
    }
}