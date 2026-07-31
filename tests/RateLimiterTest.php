<?php

declare(strict_types=1);

namespace W3a\Tests;

use PHPUnit\Framework\TestCase;
use W3a\Core\Security\RateLimiter;
use W3a\Core\Contracts\RateLimitStorageInterface;
use W3a\Core\Contracts\UserIdProviderInterface;
use W3a\Core\Exceptions\RateLimitExceededException;
use W3a\Core\Foundation\Config;
use W3a\Core\Http\Request;
use W3a\Core\Security\IpResolver;

class RateLimiterTest extends TestCase
{
    private RateLimitStorageInterface $storageMock;
    private Config $configMock;
    private Request $requestMock;
    private IpResolver $ipResolverMock;
    private ?UserIdProviderInterface $userIdProviderMock;
    private RateLimiter $rateLimiter;

    protected function setUp(): void
    {
        // 1. Создаем моки зависимостей
        $this->storageMock = $this->createMock(RateLimitStorageInterface::class);
        $this->configMock = $this->createMock(Config::class);
        $this->requestMock = $this->createMock(Request::class);
        $this->ipResolverMock = $this->createMock(IpResolver::class);
        $this->userIdProviderMock = $this->createMock(UserIdProviderInterface::class);

        // 2. Создаем экземпляр RateLimiter по умолчанию (без UserIdProvider)
        $this->rateLimiter = new RateLimiter(
            $this->storageMock,
            $this->configMock,
            $this->requestMock,
            $this->ipResolverMock,
            null // По умолчанию пользователя нет
        );
    }

    /**
     * Тест 1: Если правила для действия нет, запрос разрешается без обращения к хранилищу
     */
    public function test_allows_request_when_no_rule_exists(): void
    {
        $this->configMock->method('getArray')
            ->with('rate_limit.rules', [])
            ->willReturn(['other_action' => ['max_requests' => 10]]);

        // Хранилище НЕ должно быть вызвано
        $this->storageMock->expects($this->never())
            ->method('incrementAndGet');

        $result = $this->rateLimiter->check('unknown_action');
        $this->assertTrue($result);
    }

    /**
     * Тест 2: Если правило отключено или лимит <= 0, запрос разрешается
     */
    public function test_allows_request_when_rule_is_disabled_or_zero(): void
    {
        $this->configMock->method('getArray')
            ->willReturn([
                'disabled_action' => ['max_requests' => 10, 'window' => 60, 'enabled' => false],
                'zero_action' => ['max_requests' => 0, 'window' => 60, 'enabled' => true],
            ]);

        $this->storageMock->expects($this->never())->method('incrementAndGet');

        $this->assertTrue($this->rateLimiter->check('disabled_action'));
        $this->assertTrue($this->rateLimiter->check('zero_action'));
    }

    /**
     * Тест 3: Успешный запрос в пределах лимита
     */
    public function test_allows_request_under_limit(): void
    {
        $this->configMock->method('getArray')
            ->willReturn([
                'login' => ['max_requests' => 5, 'window' => 60, 'enabled' => true]
            ]);

        // Имитируем, что это 3-й запрос (меньше 5)
        $this->storageMock->method('incrementAndGet')
            ->with($this->isType('string'), 'login', 60)
            ->willReturn(3);

        $result = $this->rateLimiter->check('login');
        $this->assertTrue($result);
    }

    /**
     * Тест 4: Превышение лимита выбрасывает исключение
     */
    public function test_throws_exception_when_limit_exceeded(): void
    {
        $this->configMock->method('getArray')
            ->willReturn([
                'login' => ['max_requests' => 5, 'window' => 60, 'enabled' => true]
            ]);

        // Имитируем, что это 6-й запрос (больше 5)
        $this->storageMock->method('incrementAndGet')
            ->willReturn(6);

        $this->expectException(RateLimitExceededException::class);
        $this->expectExceptionMessage('Превышен лимит частоты запросов.');

        $this->rateLimiter->check('login');
    }

    /**
     * Тест 5: Идентификатор формируется по User ID, если провайдер доступен
     */
    public function test_uses_user_id_for_identifier_when_available(): void
    {
        // Создаем новый экземпляр с UserIdProvider
        $this->userIdProviderMock->method('getUserId')->willReturn('user_123');
        
        $limiterWithUser = new RateLimiter(
            $this->storageMock,
            $this->configMock,
            $this->requestMock,
            $this->ipResolverMock,
            $this->userIdProviderMock
        );

        $this->configMock->method('getArray')
            ->willReturn(['action' => ['max_requests' => 10, 'window' => 60, 'enabled' => true]]);

        // Проверяем, что хранилищу передан именно 'user:user_123'
        $this->storageMock->expects($this->once())
            ->method('incrementAndGet')
            ->with($this->equalTo('user:user_123'), 'action', 60)
            ->willReturn(1);

        $limiterWithUser->check('action');
    }

    /**
     * Тест 6: Идентификатор формируется по отпечатку (IP + UserAgent), если User ID нет
     */
    public function test_uses_fingerprint_for_identifier_when_no_user_id(): void
    {
        $this->ipResolverMock->method('getClientIp')->willReturn('192.168.1.100');
        $this->requestMock->method('getUserAgent')->willReturn('Mozilla/5.0');

        $this->configMock->method('getArray')
            ->willReturn(['action' => ['max_requests' => 10, 'window' => 60, 'enabled' => true]]);

        // Рассчитываем ожидаемый хэш заранее
        $expectedHash = hash('sha256', '192.168.1.100|Mozilla/5.0');
        $expectedIdentifier = 'fingerprint:' . $expectedHash;

        // Проверяем, что хранилищу передан именно сформированный fingerprint
        $this->storageMock->expects($this->once())
            ->method('incrementAndGet')
            ->with($this->equalTo($expectedIdentifier), 'action', 60)
            ->willReturn(1);

        $this->rateLimiter->check('action');
    }
}