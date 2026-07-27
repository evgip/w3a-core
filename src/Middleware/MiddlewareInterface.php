<?php

declare(strict_types=1);

namespace W3a\Core\Middleware;

interface MiddlewareInterface
{
    public function handle(callable $next): mixed;
}