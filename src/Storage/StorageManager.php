<?php

declare(strict_types=1);

namespace W3a\Core\Storage;

use W3a\Core\Storage\Contracts\StorageInterface;
use W3a\Core\Foundation\Config;
use RuntimeException;

/**
 * Менеджер дисков хранилища.
 * 
 * Создаёт и кэширует экземпляры дисков на основе конфигурации.
 * Работает аналогично Config: ленивая инициализация при первом обращении.
 * 
 * Использование:
 *   $storage->disk('avatars')->putFile($file, 'users');
 *   $storage->disk()->put(...) // используется диск по умолчанию
 */
class StorageManager
{
    private Config $config;
    
    /** @var array<string, StorageInterface> Кэш созданных дисков */
    private array $disks = [];

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Получить экземпляр диска.
     * Если имя не указано — используется диск по умолчанию из конфига.
     *
     * @throws RuntimeException Если драйвер не поддерживается или диск не настроен
     */
    public function disk(?string $name = null): StorageInterface
    {
        $name = $name ?? $this->getDefaultDisk();

        if (isset($this->disks[$name])) {
            return $this->disks[$name];
        }

        $this->disks[$name] = $this->resolve($name);

        return $this->disks[$name];
    }

    /**
     * Получить имя диска по умолчанию.
     */
    public function getDefaultDisk(): string
    {
        return $this->config->get('storage.default', 'local');
    }

    /**
     * Установить диск по умолчанию (во время выполнения).
     */
    public function setDefaultDisk(string $name): void
    {
        $this->config->set('storage.default', $name);
    }

    /**
     * Создать экземпляр диска по его имени в конфиге.
     */
    private function resolve(string $name): StorageInterface
    {
        $diskConfig = $this->config->getArray("storage.disks.{$name}");

        if (empty($diskConfig)) {
            throw new RuntimeException("Диск хранилища не настроен: {$name}");
        }

        $driver = $diskConfig['driver'] ?? null;

        return match ($driver) {
            'local' => $this->createLocalDriver($diskConfig),
            default => throw new RuntimeException("Неподдерживаемый драйвер хранилища: {$driver}"),
        };
    }

    /**
     * Создание драйвера локальной файловой системы.
     */
    private function createLocalDriver(array $config): LocalStorage
    {
        $root = $config['root'] ?? null;

        if (empty($root)) {
            throw new RuntimeException("Для локального диска не указан параметр 'root'");
        }

        $visibility = $config['visibility'] ?? 'private';
        $baseUrl = $config['url'] ?? null;

        return new LocalStorage($root, $visibility, $baseUrl);
    }
}