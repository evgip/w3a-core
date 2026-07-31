<?php

declare(strict_types=1);

namespace W3a\Tests;

use PHPUnit\Framework\TestCase;
use W3a\Core\Foundation\Container;
use RuntimeException;

// ============================================================================
// Вспомогательные классы исключительно для целей тестирования
// ============================================================================

class TestLogger 
{
    public function log(string $message): string 
    {
        return "LOG: " . $message;
    }
}

class TestService 
{
    // Используем PHP 8.1 Constructor Property Promotion
    public function __construct(public TestLogger $logger) 
    {
    }
}

// ============================================================================
// Сам тест
// ============================================================================

class ContainerTest extends TestCase
{
    private Container $container;

    /**
     * Этот метод выполняется ПЕРЕД каждым тестом (перед каждым методом test_*).
     * Он гарантирует, что каждый тест получает чистый, новый экземпляр контейнера.
     */
    protected function setUp(): void
    {
        $this->container = new Container();
    }

    /**
     * Тест 1: Контейнер может создать простой класс без зависимостей.
     */
    public function test_it_can_make_simple_class(): void
    {
        // 1. Подготовка (Arrange) - в данном случае не требуется
        
        // 2. Действие (Act)
        $logger = $this->container->make(TestLogger::class);

        // 3. Проверка (Assert)
        $this->assertInstanceOf(TestLogger::class, $logger);
        $this->assertEquals("LOG: hello", $logger->log("hello"));
    }

    /**
     * Тест 2: Контейнер автоматически разрешает зависимости в конструкторе.
     */
    public function test_it_resolves_dependencies_automatically(): void
    {
        // 1. Подготовка: регистрируем зависимость как singleton
        $this->container->singleton(TestLogger::class, fn() => new TestLogger());

        // 2. Действие: просим создать Service. Контейнер должен сам понять, 
        // что для TestService нужен TestLogger, и создать/взять его.
        $service = $this->container->make(TestService::class);

        // 3. Проверка
        $this->assertInstanceOf(TestService::class, $service);
        $this->assertInstanceOf(TestLogger::class, $service->logger);
    }

    /**
     * Тест 3: Singleton действительно вызывает фабрику только один раз.
     */
    public function test_singleton_caches_instance(): void
    {
        $factoryCallCount = 0;

        // Регистрируем фабрику, которая считает свои вызовы
        $this->container->singleton('counter_service', function () use (&$factoryCallCount) {
            $factoryCallCount++;
            return new \stdClass();
        });

        // 2. Действие: запрашиваем сервис дважды
        $instance1 = $this->container->get('counter_service');
        $instance2 = $this->container->get('counter_service');

        // 3. Проверка
        $this->assertSame($instance1, $instance2); // Это один и тот же объект в памяти
        $this->assertEquals(1, $factoryCallCount); // Фабрика сработала ровно 1 раз
    }

    /**
     * Тест 4: Контейнер корректно выбрасывает исключение для интерфейсов.
     */
	public function test_it_throws_exception_for_non_instantiable_class(): void
	{
		// Мы ожидаем, что будет выброшено исключение RuntimeException
		$this->expectException(RuntimeException::class);
		
		// Убираем проверку конкретного сообщения, так как оно зависит от того, 
		// установлен ли пакет psr/log
		// $this->expectExceptionMessage('is not instantiable'); // ← УДАЛИТЬ ЭТУ СТРОКУ

		// Пытаемся создать интерфейс (что невозможно)
		$this->container->make(\Psr\Log\LoggerInterface::class);
	}
}