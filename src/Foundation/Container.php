<?php

namespace W3a\Core\Foundation;

use RuntimeException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

class Container
{
    /** @var array<string, callable> Фабрики для создания сервисов */
    private array $bindings = [];

    /** @var array<string, mixed> Уже созданные экземпляры (singleton) */
    private array $instances = [];

    /** 
     * @var array<string, array> Кэш метаданных рефлексии.
     * Ключ: имя класса, Значение: массив с информацией о конструкторе.
     */
    private array $reflectionCache = [];

    /**
     * Регистрация singleton-сервиса.
     */
    public function singleton(string $abstract, callable $factory): void
    {
        $this->bindings[$abstract] = $factory;
    }

    /**
     * Регистрация transient-сервиса.
     */
    public function bind(string $abstract, callable $factory): void
    {
        $this->bindings[$abstract] = $factory;
        $this->bindings[$abstract . ':transient'] = true;
    }

    /**
     * Получение экземпляра сервиса.
     */
    public function get(string $abstract): mixed
    {
        if (!isset($this->bindings[$abstract])) {
            return $this->make($abstract);
        }

        if (isset($this->bindings[$abstract . ':transient'])) {
            return ($this->bindings[$abstract])($this);
        }

        if (!isset($this->instances[$abstract])) {
            $this->instances[$abstract] = ($this->bindings[$abstract])($this);
        }

        return $this->instances[$abstract];
    }

    /**
     * Проверка, зарегистрирован ли сервис.
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]);
    }

    /**
     * Создание экземпляра класса с автоматической инъекцией зависимостей.
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        // 1. Если есть binding — делегируем get() (там обработается singleton/transient)
        if (isset($this->bindings[$abstract])) {
            return $this->get($abstract);
        }

        // 2. Получаем или строим кэш метаданных для класса
        if (!isset($this->reflectionCache[$abstract])) {
            $this->reflectionCache[$abstract] = $this->buildReflectionMetadata($abstract);
        }

        $metadata = $this->reflectionCache[$abstract];

        if (!$metadata['instantiable']) {
            throw new RuntimeException("Class {$abstract} is not instantiable");
        }

        // 3. Если конструктора нет, создаем быстро без рефлексии
        if (!$metadata['has_constructor']) {
            return new $abstract();
        }

        // 4. Разрешаем параметры на основе быстрого массива, а не объектов Reflection
        $resolvedParameters = $this->resolveParametersFromCache($metadata['parameters'], $parameters);

        // 5. Используем оператор распаковки (PHP 8.0+), он быстрее newInstanceArgs
        return new $abstract(...$resolvedParameters);
    }

    /**
     * Установка экземпляра напрямую.
     */
    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
        if (!isset($this->bindings[$abstract])) {
            $this->bindings[$abstract] = fn() => $instance;
        }
    }

    // =========================================================================
    // НОВЫЕ МЕТОДЫ ДЛЯ ОПТИМИЗАЦИИ
    // =========================================================================

    /**
     * Однократно извлекает метаданные конструктора класса через рефлексию.
     * Результат сохраняется в $reflectionCache.
     */
    private function buildReflectionMetadata(string $abstract): array
    {
        if (!class_exists($abstract)) {
            throw new RuntimeException("Class not found: {$abstract}");
        }

        $reflection = new ReflectionClass($abstract);
        $constructor = $reflection->getConstructor();

        $parametersData = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();
                
                // Определяем, является ли тип классом (не встроенным, вроде int|string)
                $isClassType = ($type instanceof ReflectionNamedType && !$type->isBuiltin());

                $parametersData[] = [
                    'name'          => $param->getName(),
                    'type'          => $isClassType ? $type->getName() : null,
                    'has_default'   => $param->isDefaultValueAvailable(),
                    'default_value' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                    'allows_null'   => $param->allowsNull(),
                ];
            }
        }

        return [
            'instantiable'      => $reflection->isInstantiable(),
            'has_constructor'   => $constructor !== null,
            'parameters'        => $parametersData,
        ];
    }

    /**
     * Разрешение параметров на основе предварительно извлеченных метаданных.
     * Работает в разы быстрее, так как не создает объекты ReflectionParameter.
     */
    private function resolveParametersFromCache(array $cachedParameters, array $overrides): array
    {
        $resolved = [];

        foreach ($cachedParameters as $param) {
            // 1. Явная передача параметра
            if (array_key_exists($param['name'], $overrides)) {
                $resolved[] = $overrides[$param['name']];
                continue;
            }

            // 2. Если параметр имеет тип класса, пытаемся разрешить его из контейнера
            if ($param['type'] !== null) {
                $className = $param['type'];

                if ($this->has($className)) {
                    $resolved[] = $this->get($className);
                } else {
                    try {
                        $resolved[] = $this->make($className);
                    } catch (RuntimeException $e) {
                        if ($param['has_default']) {
                            $resolved[] = $param['default_value'];
                        } elseif ($param['allows_null']) {
                            $resolved[] = null;
                        } else {
                            throw new RuntimeException(
                                "Cannot resolve parameter {$param['name']} of type {$className}: " . $e->getMessage()
                            );
                        }
                    }
                }
            } 
            // 3. Обработка скалярных типов или отсутствующих типов
            elseif ($param['has_default']) {
                $resolved[] = $param['default_value'];
            } elseif ($param['allows_null']) {
                $resolved[] = null;
            } else {
                throw new RuntimeException(
                    "Cannot resolve parameter {$param['name']}: no type hint, no default value"
                );
            }
        }

        return $resolved;
    }
}