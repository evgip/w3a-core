<?php

declare(strict_types=1);

namespace W3a\Core\Contracts;

interface ApplicationInterface
{
    public function bootstrap(): self;
    public function run(): void;
}