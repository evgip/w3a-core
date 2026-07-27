<?php

declare(strict_types=1);

namespace W3a\Core;

use W3a\Core\Contracts\BannedIpRepositoryInterface;
use W3a\Core\Exceptions\IpBannedException;

class Firewall
{
    public function __construct(
        private readonly BannedIpRepositoryInterface $banChecker,
        private readonly IpResolver $ipResolver
    ) {
    }

    public function check(): void
    {
        $ip = $this->ipResolver->getClientIp();
        
        $reason = $this->banChecker->getBanReason($ip);

        if ($reason !== null) {
            throw new IpBannedException("Ваш IP-адрес заблокирован. Причина: " . $reason);
        }
    }
}