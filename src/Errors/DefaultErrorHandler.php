<?php

declare(strict_types=1);

namespace W3a\Core\Errors;

use W3a\Core\Contracts\ErrorHandlerInterface;

/**
 * Запасной обработчик ошибок ядра: рендерит минимальную HTML-страницу.
 *
 * Приложение может зарегистрировать собственную реализацию
 * ErrorHandlerInterface (со своим layout и шаблонами ошибок) — тогда
 * этот класс не используется. Нужен, чтобы при недонастроенном приложении
 * не показывать пустую страницу с голым <h1>Error N</h1>.
 */
class DefaultErrorHandler implements ErrorHandlerInterface
{
    public function render(int $code, string $message, array $context = []): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/html; charset=utf-8');
        }

        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        echo '<!DOCTYPE html>' . PHP_EOL
            . '<html lang="ru">' . PHP_EOL
            . '<head><meta charset="utf-8"><title>Ошибка ' . $code . '</title></head>' . PHP_EOL
            . '<body style="font-family:sans-serif;padding:40px;color:#333">' . PHP_EOL
            . '<h1>Ошибка ' . $code . '</h1>' . PHP_EOL
            . '<p>' . $safeMessage . '</p>' . PHP_EOL
            . '</body>' . PHP_EOL
            . '</html>';
    }
}
