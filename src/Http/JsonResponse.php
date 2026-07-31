<?php

declare(strict_types=1);

namespace W3a\Core\Http;

class JsonResponse extends Response
{
    public function __construct(array $data, int $statusCode = 200)
    {
        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        parent::__construct(
            $content !== false ? $content : '{"error":"JSON encode error"}', 
            $statusCode, 
            ['Content-Type' => 'application/json']
        );
    }

    public function send(): void
    {
        parent::send();
        exit;
    }
}