<?php

declare(strict_types=1);

namespace W3a\Core\Auth\Exceptions;

use Exception;

class AccountNotActiveException extends Exception
{
    public function __construct(string $message = 'Аккаунт не активирован.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}