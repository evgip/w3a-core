<?php

declare(strict_types=1);

namespace W3a\Core\Http\Middleware;

interface MiddlewareInterface
{
    public function handle(callable $next): mixed;
}