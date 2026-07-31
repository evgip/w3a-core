<?php

declare(strict_types=1);

namespace W3a\Tests;

use PHPUnit\Framework\TestCase;
use W3a\Core\Http\Router;
use W3a\Core\Http\Request;
use W3a\Core\Foundation\Container;
use W3a\Core\Foundation\Config;
use W3a\Core\Support\Logger;

class RouterTest extends TestCase
{
    private Router $router;
    private Request $requestMock;
    private Container $containerMock;
    private Config $configMock;
    private string $tempBasePath;

    protected function setUp(): void
    {
        // 1. Создаем временную директорию для эмуляции basePath
        $this->tempBasePath = sys_get_temp_dir() . '/w3a_router_test_' . uniqid();
        mkdir($this->tempBasePath . '/app/Modules', 0777, true);

        // 2. Мокаем Request
        $this->requestMock = $this->createMock(Request::class);
        $this->requestMock->method('getUri')->willReturn('/');
        $this->requestMock->method('getMethod')->willReturn('GET');

        // 3. Мокаем Config (возвращаем 'development', чтобы отключить чтение кэша)
        $this->configMock = $this->createMock(Config::class);
        $this->configMock->method('getString')->with('app.env', 'development')->willReturn('development');
        $this->configMock->method('getArray')->with('rate_limit.rules', [])->willReturn([]);

        // 4. Мокаем Container (для Logger и RateLimiter, если они понадобятся)
        $this->containerMock = $this->createMock(Container::class);
        $loggerMock = $this->createMock(Logger::class);
        $this->containerMock->method('get')->willReturn($loggerMock);

        // 5. Создаем экземпляр Router
        $this->router = new Router(
            $this->requestMock,
            $this->containerMock,
            $this->configMock,
            $this->tempBasePath
        );
    }

    protected function tearDown(): void
    {
        // Очистка временной директории
        $this->removeDirectory($this->tempBasePath);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Тест 1: Базовая регистрация и генерация URL с параметром
     */
    public function test_basic_route_registration_and_url_generation(): void
    {
        $this->router->add('GET', '/story/{id}', 'StoriesController@show', 'story.show');

        // Проверяем генерацию URL
        $url = $this->router->route('story.show', ['id' => 42]);
        $this->assertEquals('/story/42', $url);

        $urlWithSpecialChars = $this->router->route('story.show', ['id' => 'hello-world_123']);
        $this->assertEquals('/story/hello-world_123', $urlWithSpecialChars);
    }

    /**
     * Тест 2: Группировка маршрутов (префикс и middleware)
     */
    public function test_route_group_applies_prefix_and_middleware(): void
    {
        $this->router->group(['prefix' => '/api/v1', 'middleware' => ['web', 'auth']], function ($router) {
            $router->add('POST', '/stories/create', 'StoriesController@create', 'api.story.create');
        });

        // 2.1. Проверяем, что префикс применился к генерации URL
        $url = $this->router->route('api.story.create');
        $this->assertEquals('/api/v1/stories/create', $url);

        // 2.2. Проверяем через Reflection, что middleware применились корректно
        // (Это профессиональный подход для тестирования protected свойств)
        $reflection = new \ReflectionClass($this->router);
        
        $routeMiddlewareProp = $reflection->getProperty('routeMiddleware');
        $routeMiddlewareProp->setAccessible(true);
        $routeMiddleware = $routeMiddlewareProp->getValue($this->router);

        // Ключом в массиве является regex маршрута. Найдем наш маршрут.
        $foundMiddleware = null;
        foreach ($routeMiddleware as $regex => $middleware) {
            if (strpos($regex, 'api/v1/stories/create') !== false) {
                $foundMiddleware = $middleware;
                break;
            }
        }

        $this->assertNotNull($foundMiddleware, 'Middleware для группового маршрута не найден');
        $this->assertEquals(['web', 'auth'], $foundMiddleware);
    }

    /**
     * Тест 3: Генерация URL с несколькими параметрами
     */
    public function test_url_generation_with_multiple_parameters(): void
    {
        $this->router->add('GET', '/user/{username}/stories', 'StoriesController@userStories', 'user.stories');

        $url = $this->router->route('user.stories', [
            'username' => 'evgip',
        ]);

        $this->assertEquals('/user/evgip/stories', $url);
    }

    /**
     * Тест 4: Обработка несуществующего именованного маршрута
     */
    public function test_missing_named_route_returns_fallback(): void
    {
        // Должны вызвать логирование ошибки, но не упасть с фатальной ошибкой
        $url = $this->router->route('non.existent.route.name', ['id' => 1]);

        $this->assertEquals('#route-not-found', $url);
    }

    /**
     * Тест 5: Вложенные группы (префиксы суммируются)
     */
    public function test_nested_route_groups(): void
    {
        $this->router->group(['prefix' => '/admin'], function ($router) {
            $router->group(['prefix' => '/stories'], function ($router) {
                $router->add('POST', '/{id}/delete', 'AdminController@delete', 'admin.story.delete');
            });
        });

        $url = $this->router->route('admin.story.delete', ['id' => 99]);
        $this->assertEquals('/admin/stories/99/delete', $url);
    }
}