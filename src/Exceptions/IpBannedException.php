<?php

declare(strict_types=1);

namespace W3a\Core\Exceptions;

/**
 * Исключение блокировки IP-адреса (HTTP 403).
 *
 * Наследует HttpException, поэтому обрабатывается централизованно
 * в ExceptionHandler::handleHttp() с корректным кодом 403.
 */
class IpBannedException extends HttpException
{
    public function __construct(
        string $message = 'Ваш IP-адрес заблокирован.',
        ?\Throwable $previous = null
    ) {
        parent::__construct(403, $message, $previous);
    }
}
