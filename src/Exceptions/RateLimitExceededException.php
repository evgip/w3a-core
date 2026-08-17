<?php

declare(strict_types=1);

namespace W3a\Core\Exceptions;

/**
 * Исключение превышения лимита частоты запросов (HTTP 429).
 *
 * Наследует HttpException, поэтому обрабатывается централизованно
 * в ExceptionHandler::handleHttp() с корректным кодом 429.
 */
class RateLimitExceededException extends HttpException
{
    public function __construct(
        string $message = 'Превышен лимит частоты запросов.',
        ?\Throwable $previous = null
    ) {
        parent::__construct(429, $message, $previous);
    }
}
