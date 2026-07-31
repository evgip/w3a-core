<?php

declare(strict_types=1);

namespace W3a\Core\Http;

class RedirectResponse extends Response
{
    public function __construct(string $url, int $statusCode = 302)
    {
        parent::__construct('', $statusCode, ['Location' => $url]);
    }

    public function send(): void
    {
        parent::send();
        exit;
    }
}