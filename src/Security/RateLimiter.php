<?php

declare(strict_types=1);

namespace W3a\Core\Security;

use W3a\Core\Contracts\RateLimitStorageInterface;
use W3a\Core\Contracts\UserIdProviderInterface;
use W3a\Core\Exceptions\RateLimitExceededException;

use W3a\Core\Foundation\Config;
use W3a\Core\Http\Request;

class RateLimiter
{
    // Constructor Property Promotion + readonly
    public function __construct(
        private readonly RateLimitStorageInterface $storage,
        private readonly Config $config,
        private readonly Request $request,
        private readonly IpResolver $ipResolver,
        private readonly ?UserIdProviderInterface $userIdProvider = null,
    ) {
    }

    /**
     * Проверка лимита частоты запросов.
     *
     * @throws RateLimitExceededException (HTTP 429) при превышении лимита
     */
    public function check(string $action): bool
    {
        $config = $this->config->getArray('rate_limit.rules', []);
        if (!isset($config[$action])) {
            return true; // Правила нет = пропускаем
        }

        $rule = $config[$action];
        $maxRequests = (int)($rule['max_requests'] ?? 0);
        $window = (int)($rule['window'] ?? 60);
        $enabled = (bool)($rule['enabled'] ?? true);

        if (!$enabled || $maxRequests <= 0) {
            return true;
        }

        $identifier = $this->getIdentifier();

        $currentRequests = $this->storage->incrementAndGet($identifier, $action, $window);
        $remaining = max(0, $maxRequests - $currentRequests);

        header("RateLimit-Limit: {$maxRequests}");
        header("RateLimit-Remaining: {$remaining}");
        header("RateLimit-Reset: {$window}");

        if ($currentRequests > $maxRequests) {
            throw new RateLimitExceededException();
        }

        return true;
    }

    private function getIdentifier(): string
    {
        if ($this->userIdProvider !== null && ($userId = $this->userIdProvider->getUserId())) {
            return 'user:' . $userId;
        }

        $ip = $this->ipResolver->getClientIp();
        $userAgent = $this->request->getUserAgent() ?? '';
        return 'fingerprint:' . hash('sha256', $ip . '|' . $userAgent);
    }
}
