<?php

declare(strict_types=1);

namespace W3a\Core\Http;

class ViewResponse extends Response
{
    public function __construct(string $content, int $statusCode = 200, array $headers = ['Content-Type' => 'text/html; charset=utf-8'])
    {
        parent::__construct($content, $statusCode, $headers);
    }
}