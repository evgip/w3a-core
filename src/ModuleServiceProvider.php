<?php

declare(strict_types=1);

namespace W3a\Core;

/**
 * Базовый класс для всех провайдеров сервисов (включая модули).
 * 
 * ВАЖНО: Этот класс НЕ содержит логики регистрации сервисов ядра.
 * Он нужен только для того, чтобы модули могли хранить ссылку на 
 * контейнер (для использования в методе boot) и иметь единую структуру.
 * 
 * Это НЕ нарушает независимость Core, так как этот класс ничего 
 * не знает о пространстве имен App\Modules.
 */
abstract class ModuleServiceProvider
{
    protected ?Container $container = null;

    public function register(Container $container): void
    {
        $this->container = $container;
    }

    public function boot(): void
    {
        // Переопределяется в модулях при необходимости
    }
}