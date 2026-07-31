<?php

declare(strict_types=1);

namespace W3a\Core\Exceptions;

use W3a\Core\Foundation\Container;
use W3a\Core\Contracts\ErrorHandlerInterface;
use W3a\Core\Support\Logger;
use W3a\Core\Http\Request;
use W3a\Core\Foundation\Config;

class ExceptionHandler
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(\Throwable $e): void
    {
        if ($e instanceof CsrfException) {
            $this->handleCsrf($e);
            exit;
        }

        if ($e instanceof HttpException) {
            $this->handleHttp($e);
            exit;
        }

        // Все остальные исключения (включая фатальные ошибки PHP) идут сюда
        $this->handleFatal($e);
    }

    private function handleCsrf(CsrfException $e): void
    {
        http_response_code(419);
        $context = $e->getContext();
        $isAjax = $context['is_ajax'] ?? false;
        
        $this->log('warning', 'CSRF validation failed', $context);
        
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'CSRF token validation failed', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        $this->renderError('csrf', $e->getMessage(), 419);
    }

    private function handleHttp(HttpException $e): void
    {
        http_response_code($e->getStatusCode());
        $level = $e->getStatusCode() >= 500 ? 'error' : 'warning';
        
        $this->log($level, $e->getMessage(), [
            'status' => $e->getStatusCode(),
            'url' => $this->getRequest()->getUri(),
            'ip' => $this->getRequest()->getIp(),
        ]);

        $this->renderError('show', $e->getMessage(), $e->getStatusCode());
    }

    private function handleFatal(\Throwable $e): void
    {
        $this->log('error', $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), [
            'trace' => $e->getTraceAsString(),
            'url' => $this->getRequest()->getUri(),
        ]);

        $isDev = $this->getConfig()->get('app.env', 'development') === 'development';
        http_response_code(500);

        if ($isDev) {
            echo '<div class="alert is-success"><h2>💥 Ошибка разработки:</h2>';
            echo '<strong>' . htmlspecialchars($e->getMessage()) . '</strong><br>';
            echo 'Файл: ' . htmlspecialchars($e->getFile()) . ' (строка ' . $e->getLine() . ')<br>';
            echo '<small>Стек вызовов записан в storage/logs/app.log</small></div>';
        } else {
            $this->renderError('serverError', "Извините, на сервере произошла внутренняя ошибка.", 500);
        }
    }

    private function renderError(string $method, string $message, int $code): void
    {
        try {
            $handler = $this->container->get(ErrorHandlerInterface::class);
            $handler->render($code, $message);
        } catch (\Throwable $e) {
            http_response_code($code);
            echo "<h1>Error {$code}</h1><p>" . htmlspecialchars($message) . "</p>";
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        try {
            $this->container->get(Logger::class)->$level($message, $context);
        } catch (\Throwable $e) {
            error_log("[{$level}] {$message} " . json_encode($context));
        }
    }

    private function getRequest(): Request
    {
        return $this->container->get(Request::class);
    }

    private function getConfig(): Config
    {
        return $this->container->get(Config::class);
    }
}
