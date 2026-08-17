<?php

declare(strict_types=1);

namespace W3a\Core\Http;

use W3a\Core\Http\Middleware\MiddlewarePipeline;
use W3a\Core\Events\EventDispatcher;

use W3a\Core\Foundation\Config;
use W3a\Core\Foundation\Container;

use W3a\Core\Http\Request;

use W3a\Core\Security\RateLimiter;
use W3a\Core\Support\Logger;
use W3a\Core\Support\PhpArrayFile;

class Router
{
    protected ?string $currentRouteName = null;
    protected array $routes = [];
    protected array $namedRoutes = [];
    protected array $routeMiddleware = [];
    protected Request $request;
    protected string $cacheFile;
    protected Container $container;
    protected Config $config;
    protected ?EventDispatcher $eventDispatcher = null;
    
    protected string $basePath;
    
    // Флаг для ленивой загрузки маршрутов
    protected bool $routesLoaded = false;

    protected array $middlewareGroups = [
        'web' => [
            \W3a\Core\Http\Middleware\CsrfMiddleware::class,
        ],
    ];

    protected array $currentGroupMiddleware = [];
    protected string $currentGroupPrefix = '';

    /**
     * Индекс маршрутов по статическому первому сегменту URI.
     * Позволяет выполнять preg_match только по кандидатам, а не по всем маршрутам.
     * Структура: [METHOD][prefix] = [['regex' => ..., 'order' => int], ...]
     */
    protected array $routeIndex = [];
    protected bool $routeIndexBuilt = false;

    /** Бакет для маршрутов с динамическим первым сегментом (например, /{username}) */
    private const DYNAMIC_BUCKET = '{dynamic}';

    public function addMiddlewareGroup(string $name, array $middlewares): void
    {
        $this->middlewareGroups[$name] = $middlewares;
    }

    public function __construct(Request $request, Container $container, Config $config, string $basePath)
    {
        $this->request = $request;
        $this->container = $container;
        $this->config = $config;
        $this->basePath = $basePath;
        $this->cacheFile = $this->basePath . '/storage/cache/routes_compiled.php';
        
        // ❌ УДАЛЕНО: $this->loadRoutes(); 
        // Маршруты теперь загружаются лениво, когда они действительно нужны.
        // Это гарантирует, что AppServiceProvider успеет зарегистрировать все группы middleware.
    }

    /**
     * Гарантирует, что маршруты загружены ровно один раз.
     */
    protected function ensureRoutesLoaded(): void
    {
        if ($this->routesLoaded) {
            return;
        }
        $this->loadRoutes();
        $this->routesLoaded = true;
    }

    public function getCurrentRouteName(): ?string
    {
        return $this->currentRouteName;
    }

    public function getNamedRoutes(): array
    {
        $this->ensureRoutesLoaded();
        return $this->namedRoutes;
    }

    protected function loadRoutes(): void
    {
        $isProduction = $this->config->getString('app.env', 'development') === 'production';

        if ($isProduction) {
            $cache = PhpArrayFile::read($this->cacheFile);
            if ($cache !== null) {
                $this->routes = $cache['routes'] ?? [];
                $this->namedRoutes = $cache['namedRoutes'] ?? [];
                $this->routeMiddleware = $cache['routeMiddleware'] ?? [];
                $this->routeIndexBuilt = false;
                return;
            }
        }

        $this->loadModulesRoutes();
    }

    protected function loadModulesRoutes(): void
    {
        $modulesPath = $this->basePath . '/app/Modules';
        
        if (!is_dir($modulesPath)) {
            return;
        }

        $modules = array_diff(scandir($modulesPath), ['.', '..']);
        foreach ($modules as $module) {
            $routesFile = $modulesPath . '/' . $module . '/routes.php';
            if (file_exists($routesFile)) {
                $router = $this;
                require $routesFile;
            }
        }
    }

    public function add(
        string $method,
        string $route,
        string $action,
        ?string $name = null,
        array $middleware = []
    ): void {
        $this->routeIndexBuilt = false;

        $fullRoute = $this->currentGroupPrefix . $route;
        $allMiddleware = array_merge($this->currentGroupMiddleware, $middleware);
        $regexRoute = preg_replace('/{([a-zA-Z0-9_]+)}/', '(?P<$1>[^/]+)', $fullRoute);
        $regexRoute = '#^' . $regexRoute . '$#s';

        $this->routes[strtoupper($method)][$regexRoute] = [
            'action' => $action,
            'original_uri' => $fullRoute,
            'name' => $name,
        ];

        $this->routeMiddleware[$regexRoute] = $allMiddleware;

        if ($name !== null) {
            $this->namedRoutes[$name] = $fullRoute;
        }
    }

    public function group(array $options, callable $callback): void
    {
        $previousMiddleware = $this->currentGroupMiddleware;
        $previousPrefix = $this->currentGroupPrefix;

        $middleware = $options['middleware'] ?? [];
        if (is_string($middleware)) {
            $middleware = [$middleware];
        }
        $prefix = $options['prefix'] ?? '';

        $this->currentGroupMiddleware = array_merge($previousMiddleware, $middleware);
        $this->currentGroupPrefix = $previousPrefix . $prefix;

        $callback($this);

        $this->currentGroupMiddleware = $previousMiddleware;
        $this->currentGroupPrefix = $previousPrefix;
    }

    public function compileCache(): void
    {
        $this->ensureRoutesLoaded();
        
        $this->routes = [];
        $this->namedRoutes = [];
        $this->routeMiddleware = [];
        $this->loadModulesRoutes();

        $cacheData = [
            'routes' => $this->routes,
            'namedRoutes' => $this->namedRoutes,
            'routeMiddleware' => $this->routeMiddleware,
        ];

        PhpArrayFile::write($this->cacheFile, $cacheData);
    }

    public function clearCache(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    public function route(string $name, array $params = []): string
    {
        $this->ensureRoutesLoaded();
        
        if (!isset($this->namedRoutes[$name])) {
            $logger = $this->container->get(Logger::class);
            $logger->error("Попытка генерации несуществующего именованного маршрута: '{$name}'");
            return '#route-not-found';
        }

        $pattern = $this->namedRoutes[$name];
        foreach ($params as $key => $value) {
            $pattern = str_replace('{' . $key . '}', urlencode((string)$value), $pattern);
        }

        return '/' . ltrim($pattern, '/');
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function dispatch(): void
    {
        // ✅ КРИТИЧЕСКИ ВАЖНО: Загружаем маршруты ПЕРЕД обработкой запроса
        $this->ensureRoutesLoaded();
        $this->ensureRouteIndexBuilt();
        
        $uri = $this->request->getUri();
        $method = $this->request->getMethod();

        $this->applyRateLimiting($uri, $method);

        if (!isset($this->routes[$method])) {
            $this->triggerError(404, "Method $method not allowed");
            return;
        }

        // Отбираем кандидатов по статическому первому сегменту URI.
        // Только по ним выполняется preg_match (вместо перебора всех маршрутов).
        $candidates = [];
        foreach ($this->candidateBuckets($uri) as $bucket) {
            foreach ($this->routeIndex[$method][$bucket] ?? [] as $entry) {
                $candidates[$entry['order']] = $entry['regex'];
            }
        }
        ksort($candidates); // сохраняем порядок регистрации маршрутов

        foreach ($candidates as $routeRegex) {
            if (preg_match($routeRegex, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $routeData = $this->routes[$method][$routeRegex];
                $this->currentRouteName = $routeData['name'] ?? null;
                $middleware = $this->routeMiddleware[$routeRegex] ?? [];
                $this->executeWithMiddleware($routeData['action'], $params, $middleware);
                return;
            }
        }

        $this->triggerError(404, "Route not found");
    }

    /**
     * Строит индекс маршрутов по статическому первому сегменту.
     * Вызывается лениво перед первым dispatch().
     */
    protected function ensureRouteIndexBuilt(): void
    {
        if ($this->routeIndexBuilt) {
            return;
        }

        $this->routeIndex = [];
        foreach ($this->routes as $method => $routes) {
            $order = 0;
            foreach ($routes as $regex => $routeData) {
                $prefix = $this->extractStaticPrefix((string)($routeData['original_uri'] ?? ''));
                $this->routeIndex[$method][$prefix][] = [
                    'regex' => $regex,
                    'order' => $order++,
                ];
            }
        }

        $this->routeIndexBuilt = true;
    }

    /**
     * Извлекает статический первый сегмент маршрута.
     * Если сегмент динамический ({param}) — возвращает DYNAMIC_BUCKET.
     * Корневой маршрут '/' — пустая строка.
     */
    private function extractStaticPrefix(string $uri): string
    {
        $uri = ltrim($uri, '/');
        $firstSeg = explode('/', $uri, 2)[0];

        if ($firstSeg === '') {
            return '';
        }

        return str_contains($firstSeg, '{') ? self::DYNAMIC_BUCKET : $firstSeg;
    }

    /**
     * Возвращает список бакетов индекса, которые нужно проверить для данного URI.
     */
    private function candidateBuckets(string $uri): array
    {
        $firstSeg = explode('/', ltrim($uri, '/'), 2)[0];

        $buckets = [$firstSeg];
        if ($firstSeg !== self::DYNAMIC_BUCKET) {
            $buckets[] = self::DYNAMIC_BUCKET;
        }

        return $buckets;
    }

    protected function applyRateLimiting(string $uri, string $method): void
    {
        $rateLimiter = $this->container->get(RateLimiter::class);
        $rateLimitConfig = $this->config->getArray('rate_limit.rules', []);

        // RateLimiter::check() бросает RateLimitExceededException (HTTP 429),
        // которая обрабатывается централизованно в ExceptionHandler.
        if ($method === 'POST') {
            $authRoutes = $rateLimitConfig['auth.submit']['routes'] ?? ['/login', '/register'];
            if (in_array($uri, $authRoutes)) {
                $rateLimiter->check('auth.submit');
                return;
            }

            $rateLimiter->check('global.post');
        } else {
            $rateLimiter->check('global.get');
        }
    }

    protected function executeWithMiddleware(string $action, array $params, array $middleware): void
    {
        if (empty($middleware)) {
            // Перехватываем результат и передаем в обработчик
            $this->handleResponse($this->executeAction($action, $params));
            return;
        }

        $finalMiddlewareClasses = [];
        foreach ($middleware as $item) {
            if (isset($this->middlewareGroups[$item])) {
                $finalMiddlewareClasses = array_merge($finalMiddlewareClasses, $this->middlewareGroups[$item]);
            } else {
                $finalMiddlewareClasses[] = $item;
            }
        }

        $pipeline = new MiddlewarePipeline($this->container);
        
        foreach ($finalMiddlewareClasses as $middlewareClass) {
            if (class_exists($middlewareClass)) {
                $pipeline->pipe($middlewareClass);
            } else {
                $logger = $this->container->get(Logger::class);
                $logger->error("КРИТИЧЕСКАЯ ОШИБКА: Middleware class not found: {$middlewareClass}");
            }
        }

        // Destination теперь ВОЗВРАЩАЕТ результат executeAction
        $destination = function () use ($action, $params) {
            return $this->executeAction($action, $params);
        };

        // Получаем ответ из пайплайна и обрабатываем его
        $response = $pipeline->process($destination);
        $this->handleResponse($response);
    }

    protected function executeAction(string $action, array $params): mixed 
    {
        if (strpos($action, '@') === false) {
            $this->triggerError(500, "Invalid action format: '$action'");
            return null;
        }

        [$controllerClass, $method] = explode('@', $action);

        if (!class_exists($controllerClass)) {
            $this->triggerError(500, "Controller class not found: $controllerClass");
            return null;
        }

        $controllerInstance = $this->container->make($controllerClass);

        if (!method_exists($controllerInstance, $method)) {
            $this->triggerError(500, "Method $method not found in $controllerClass");
            return null;
        }

        // ВОЗВРАЩАЕМ результат вызова метода контроллера
        return call_user_func_array([$controllerInstance, $method], $params);
    }

    // Централизованная обработка ответа
    protected function handleResponse(mixed $response): void
    {
        if ($response instanceof \W3a\Core\Http\Response) {
            $response->send();
            return; // Прерываем выполнение, заголовки отправлены
        }
    }

    protected function triggerError(int $code, string $message): void
    {
        http_response_code($code);

        if ($code === 404) {
            $logger = $this->container->get(Logger::class);
            $logger->error("Ошибка 404: " . $message, ['url' => $this->request->getUri()]);
        }

        try {
            $handler = $this->container->get(\W3a\Core\Contracts\ErrorHandlerInterface::class);
            $handler->render($code, $message, ['url' => $this->request->getUri()]);
        } catch (\Throwable $e) {
            echo "<h1>Error $code</h1><p>" . htmlspecialchars($message) . "</p>";
        }
    }
}