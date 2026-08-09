<?php

declare(strict_types=1);

namespace W3a\Core\Security;

use W3a\Core\Foundation\Config;
use W3a\Core\Support\Logger;

/**
 * Сервис безопасности: nonce для CSP и заголовки безопасности.
 */
class Security
{
    private ?string $nonce = null;
    private Logger $logger;
    private Config $config;

    /**
     * Конструктор с инъекцией Logger и Config
     */
    public function __construct(Logger $logger, Config $config)
    {
        $this->logger = $logger;
        $this->config = $config;
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

        $policy = [
            "default-src 'self' " . $mergeOrigins('default_src'),
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval' " . $mergeOrigins('script_src'),
			
			"script-src-elem 'self' 'nonce-{$nonce}' 'unsafe-eval' 'unsafe-inline' " . $mergeOrigins('script_src'),
			"script-src-attr 'self' 'nonce-{$nonce}' 'unsafe-inline' " . $mergeOrigins('script_src'), 
			
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
        header("X-XSS-Protection: 1; mode=block");
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
