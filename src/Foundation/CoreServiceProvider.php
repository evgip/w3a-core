<?php

declare(strict_types=1);

namespace W3a\Core\Foundation;

use W3a\Core\Events\EventDispatcher;

use W3a\Core\Http\Request;
use W3a\Core\Http\Router;
use W3a\Core\Http\Session;
use W3a\Core\Database\Database;

use W3a\Core\Support\Audit;
use W3a\Core\Support\Logger;
use W3a\Core\Support\Validator;

use W3a\Core\Security\Security;
use W3a\Core\Security\IpResolver;
use W3a\Core\Security\RateLimiter;
use W3a\Core\Security\Firewall;

use W3a\Core\View\View;
use W3a\Core\View\ViewFinder;

use W3a\Core\Cache\FileCache;
use W3a\Core\Cache\DatabaseCache;

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
            // ИСПРАВЛЕНО: теперь читает файл database.php (ключ 'database' указывает на имя файла)
            return new Database($config->getArray('database', []));
        });

        // 3. Session
        $container->singleton(Session::class, fn() => new Session());

        // 4. Logger
        $container->singleton(Logger::class, function ($container) {
            $config = $container->get(Config::class);
            $app = $container->get(Application::class);
            
            // ИСПРАВЛЕНО: читает файл app.php, ключ log_path
            $logFile = $config->get('app.log_path', $app->getBasePath() . '/storage/logs/app.log');
            return new Logger($logFile);
        });

        // 5. IpResolver
        $container->singleton(IpResolver::class, function ($container) {
            $config = $container->get(Config::class);
            // ИСПРАВЛЕНО: читает файл app.php, ключ trusted_proxies
            $trustedProxies = $config->getArray('app.trusted_proxies', []);
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
            $container->singleton(EventDispatcher::class, function ($container) {
                return new EventDispatcher($container->get(Logger::class));
            });
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

        // 15. Cache
        $container->singleton(FileCache::class, function ($container) {
            $config = $container->get(Config::class);
            $app = $container->get(Application::class);
            
            // ИСПРАВЛЕНО: читает файл cache.php, ключ file.path
            $cacheDir = $config->get('cache.file.path', $app->getBasePath() . '/storage/cache/data');
            return new FileCache($cacheDir);
        });

        $container->singleton(DatabaseCache::class, function ($container) {
            $config = $container->get(Config::class);
            return new DatabaseCache(
                $container->get(Database::class),
                $container->get(FileCache::class),
                // ИСПРАВЛЕНО: читает файл cache.php, ключи database.enabled и database.ttl
                $config->getBool('cache.database.enabled', true),
                $config->getInt('cache.database.ttl', 3600)
            );
        });
    }
}