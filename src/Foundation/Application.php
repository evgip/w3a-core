<?php

declare(strict_types=1);

namespace W3a\Core\Foundation;

use W3a\Core\Http\Request;
use W3a\Core\Exceptions\ExceptionHandler;
use W3a\Core\Support\Lang; 
use W3a\Core\Support\Benchmark;

class Application
{
    private Container $container;
    private string $basePath;
    private array $providers;

    public function __construct(string $basePath, array $providers = [])
    {
        $this->basePath = $basePath;
        // Если провайдеры не переданы, используем только ядро
        $this->providers = $providers ?: [CoreServiceProvider::class];
    }

    public function bootstrap(): self
    {
		Benchmark::start();
		
        // 1. Базовая инициализация
        $this->container = new Container();
        $this->container->instance(Application::class, $this);
        $this->container->instance(Container::class, $this->container);
		
        // Инициализируем глобальный хелпер
        container($this->container);

        $config = new Config($this->basePath . '/app/Config');
        $this->container->instance(Config::class, $config);

		Lang::init($config, $this->basePath);

        // 2. Инициализация базовых сервисов и Request
        $request = new Request();
        $this->container->instance(Request::class, $request);

        // 3. Регистрация сервисов Ядра
        $coreProvider = new CoreServiceProvider();
        $coreProvider->register($this->container, $request);

        // 4. Регистрация провайдеров Приложения и Модулей (делегировано)
        $providerRepo = new ProviderRepository($this->basePath, $this->container, $config);
        $providerRepo->load($this->providers);

        return $this;
    }

    public function run(): void
    {
        try {
            // Весь роутинг и выполнение
            $this->container->get(\W3a\Core\Http\Router::class)->dispatch();
        } catch (\Throwable $e) {
            // Вся обработка ошибок делегирована одному классу
            $this->container->get(ExceptionHandler::class)->handle($e);
        }
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }
}