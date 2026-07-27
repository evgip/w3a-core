<?php

declare(strict_types=1);

namespace W3a\Core;

/**
 * Единый сервис для определения реального IP-адреса клиента.
 * Доверяет proxy-заголовкам только когда запрос пришёл от доверенного proxy.
 */
class IpResolver
{
    // ✅ Свойства объявлены и инициализированы прямо в конструкторе
    public function __construct(
        private readonly array $trustedProxies = [],
        private readonly bool $useCloudflareDefaults = true,
    ) {
    }

    public function getClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        if ($this->isTrustedProxy($remoteAddr)) {
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }

            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }

            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $ip = $_SERVER['HTTP_X_REAL_IP'];
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $remoteAddr;
    }

    public function isTrustedProxy(string $ip): bool
    {
        $trustedProxies = $this->trustedProxies;

        if (empty($trustedProxies) && $this->useCloudflareDefaults) {
            $trustedProxies = $this->getCloudflareIps();
        }

        if (empty($trustedProxies)) {
            return false;
        }

        foreach ($trustedProxies as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public function getCloudflareIps(): array
    {
        return [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32',
            '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
            '2a06:98c0::/29', '2c0f:f248::/32',
        ];
    }

    public function ipInCidr(string $ip, string $cidr): bool
    {
        // ✅ Современный синтаксис деструктуризации массива (вместо list())
        [$subnet, $bits] = explode('/', $cidr);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);

            if ($ipLong === false || $subnetLong === false) {
                return false;
            }

            $mask = -1 << (32 - (int)$bits);
            return ($ipLong & $mask) === ($subnetLong & $mask);
        } 
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);

            if ($ipBin === false || $subnetBin === false) {
                return false;
            }

            $fullMask = str_repeat("\xff", (int)($bits / 8));
            if ($bits % 8 !== 0) {
                $fullMask .= chr(~(0xff >> ($bits % 8)));
            }
            $fullMask = str_pad($fullMask, 16, "\x00");

            return ($ipBin & $fullMask) === ($subnetBin & $fullMask);
        }

        return false;
    }

    public function getTrustedProxies(): array
    {
        return $this->trustedProxies;
    }
}