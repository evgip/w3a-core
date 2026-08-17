<?php

declare(strict_types=1);

namespace W3a\Core\Security;

use W3a\Core\Foundation\Config;
use W3a\Core\Support\Logger;
use W3a\Core\Http\Request;

/**
 * Сервис безопасности: nonce для CSP и заголовки безопасности.
 */
class Security
{
    private ?string $nonce = null;
    private Logger $logger;
    private Config $config;
    private ?Request $request;

    /**
     * Конструктор с инъекцией Logger, Config и Request.
     *
     * @param Request|null $request Нужен для маршрутозависимого CSP
     *                              (unsafe-eval только на страницах редактора).
     */
    public function __construct(Logger $logger, Config $config, ?Request $request = null)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->request = $request;
    }

    /**
     * Получить nonce для CSP
     */
    public function getNonce(): string
    {
        if ($this->nonce === null) {
            $this->nonce = bin2hex(random_bytes(16));
        }
        return $this->nonce;
    }

    /**
     * Определяет, является ли текущий запрос страницей редактора.
     * unsafe-eval разрешён только здесь (нужен для Editor.js и его плагинов).
     *
     * @return bool
     */
    private function isEditorPage(): bool
    {
        if ($this->request === null) {
            return false;
        }

        $uri = $this->request->getUri();

        return $uri === '/stories/create'
            || preg_match('#^/stories/[^/]+/edit$#', $uri) === 1;
    }

    /**
     * Отправить заголовки CSP и безопасности
     */
    public function sendCspHeader(): void
    {
        if (headers_sent()) {
            $this->logger->error("Security Layer Failure: Headers already dispatched.");
            return;
        }

        $nonce = $this->getNonce();

        // ✅ Читаем конфиг через Config (а не через dirname!)
        // Файл config/csp.php автоматически загружается системой конфигов
        $cspConfig = $this->config->getArray('csp', []);

        $mergeOrigins = function (string $key, array $defaults = []) use ($cspConfig): string {
            $configured = $cspConfig[$key] ?? [];
            $allOrigins = array_unique(array_merge($defaults, $configured));
            return implode(' ', $allOrigins);
        };

        // unsafe-eval разрешён ТОЛЬКО на страницах редактора (Editor.js).
        // unsafe-inline для скриптов запрещён полностью: инлайн-скрипты обязаны
        // использовать nonce, инлайн-обработчики (onclick и т.п.) — запрещены.
        $allowUnsafeEval = $this->isEditorPage();
        $evalKeyword = $allowUnsafeEval ? " 'unsafe-eval'" : '';

        $policy = [
            "default-src 'self' " . $mergeOrigins('default_src'),
            "script-src 'self' 'nonce-{$nonce}'" . $evalKeyword . ' ' . $mergeOrigins('script_src'),
            "script-src-elem 'self' 'nonce-{$nonce}'" . $evalKeyword . ' ' . $mergeOrigins('script_src'),
            "script-src-attr 'self' 'nonce-{$nonce}' " . $mergeOrigins('script_src'),

            "style-src-elem 'self' 'unsafe-inline' " . $mergeOrigins('style_src'),
            "style-src-attr 'self'",
            "frame-src 'self' " . $mergeOrigins('frame_src'),
            "connect-src 'self' " . $mergeOrigins('connect_src'),
            "img-src 'self' data: " . $mergeOrigins('img_src'),
            "font-src 'self' " . $mergeOrigins('font_src'),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];

        $frameAncestors = $cspConfig['frame_ancestors'] ?? ['none'];
        if (in_array('none', $frameAncestors)) {
            $policy[] = "frame-ancestors 'none'";
        } else {
            $policy[] = "frame-ancestors 'self' " . implode(' ', $frameAncestors);
        }

        header("Content-Security-Policy: " . implode("; ", $policy));
        header("X-Content-Type-Options: nosniff");
        header('X-Frame-Options: SAMEORIGIN');
        header("Referrer-Policy: strict-origin-when-cross-origin");

        $hsts = $cspConfig['hsts'] ?? [];
        if ($hsts['enabled'] ?? false) {
            $hstsHeader = "max-age=" . (int)($hsts['max_age'] ?? 31536000);
            if ($hsts['include_subdomains'] ?? true) {
                $hstsHeader .= "; includeSubDomains";
            }
            if ($hsts['preload'] ?? true) {
                $hstsHeader .= "; preload";
            }
            header("Strict-Transport-Security: " . $hstsHeader);
        }
    }
}
