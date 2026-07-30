<?php

declare(strict_types=1);

namespace W3a\Core\Http\Middleware;

use W3a\Core\Foundation\Container;
use W3a\Core\Support\Logger;

/**
 * Конвейер middleware ядра.
 * Последовательно выполняет middleware, передавая управление по цепочке.
 */
class MiddlewarePipeline
{
    private array $middlewares = [];
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function pipe(string $middlewareClass): self
    {
        if (!class_exists($middlewareClass)) {
            $this->container->get(Logger::class)->warning("Middleware class not found: {$middlewareClass}");
            return $this;
        }
        
        // ✅ Используем полный путь к интерфейсу ядра
        if (!is_subclass_of($middlewareClass, MiddlewareInterface::class)) {
            $this->container->get(Logger::class)->warning("Class {$middlewareClass} must implement MiddlewareInterface");
            return $this;
        }
        
        $this->middlewares[] = $middlewareClass;
        return $this;
    }

    public function pipeMany(array $middlewareClasses): self
    {
        foreach ($middlewareClasses as $middleware) {
            $this->pipe($middleware);
        }
        return $this;
    }

    public function process(callable $destination): mixed
    {
        $pipeline = $destination;
        
        foreach (array_reverse($this->middlewares) as $middlewareClass) {
            $middleware = $this->container->make($middlewareClass);
            
            $next = $pipeline;
            $pipeline = function () use ($middleware, $next) {
                return $middleware->handle($next);
            };
        }
        
        return $pipeline();
    }
}
