<?php

declare(strict_types=1);

namespace W3a\Core\Cache;

/**
 * Файловый кэш с безопасным хранением данных.
 * 
 * КЛЮЧЕВЫЕ ОСОБЕННОСТИ:
 * - Данные сериализуются через serialize() — нет исполнения PHP-кода
 * - Чтение через flock(LOCK_SH) — защита от race condition при конкурентной записи
 * - Атомарная запись через tmp-файл + rename() — нет частично записанных файлов
 * - Шардирование каталога (2-символьные подпапки) — нет тысяч файлов в одной директории
 * - Расширение .cache вместо .php — дополнительная защита от случайного запуска
 */
class FileCache
{
    private string $cacheDir;
    private string $prefix;

    public function __construct(string $cacheDir, string $prefix = 'app_')
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->prefix = $prefix;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Получить значение из кэша.
     * 
     * Использует shared lock (LOCK_SH) для безопасного конкурентного чтения.
     * Если файл повреждён или истёк TTL — возвращается null.
     */
    public function get(string $key): mixed
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        // Открываем файл для чтения с shared lock
        $fp = fopen($file, 'rb');
        if ($fp === false) {
            return null;
        }

        try {
            flock($fp, LOCK_SH); // Shared lock: несколько читателей могут работать одновременно
            $contents = stream_get_contents($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        if ($contents === false || $contents === '') {
            return null;
        }

        // Десериализация — безопасна, нет исполнения произвольного PHP-кода
        $data = @unserialize($contents);
        if ($data === false || !is_array($data)) {
            // Файл повреждён — удаляем
            @unlink($file);
            return null;
        }

        // Проверяем срок действия
        if (isset($data['expires']) && $data['expires'] > 0 && $data['expires'] < time()) {
            $this->delete($key);
            return null;
        }

        return $data['value'] ?? null;
    }

    /**
     * Сохранить значение в кэш.
     * 
     * Атомарная запись: сначала пишем во временный файл, затем rename() в целевой.
     * Это гарантирует, что читатели никогда не увидят частично записанный файл.
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $file = $this->getFilePath($key);
        $data = [
            'value' => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'created' => time(),
        ];

        // Гарантируем существование поддиректории (шардирование)
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Пишем во временный файл с эксклюзивной блокировкой
        $tmpFile = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $result = file_put_contents($tmpFile, serialize($data), LOCK_EX);
        
        if ($result === false) {
            @unlink($tmpFile);
            return false;
        }

        // Атомарная замена — читатели либо видят старое, либо новое, никогда не сломанное
        if (!rename($tmpFile, $file)) {
            @unlink($tmpFile);
            return false;
        }

        return true;
    }

    /**
     * Удалить значение из кэша.
     */
    public function delete(string $key): bool
    {
        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            return @unlink($file);
        }

        return true;
    }

    /**
     * Очистить весь кэш (рекурсивно, учитывает шардирование).
     */
    public function clear(): bool
    {
        if (!is_dir($this->cacheDir)) {
            return true;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        return true;
    }

    /**
     * Проверить существование ключа.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Получить путь к файлу кэша с шардированием по первым 2 символам MD5.
     * 
     * Пример: cacheDir/a1/app_a1b2c3d4e5f6.cache
     * 
     * Это предотвращает создание одной директории с десятками тысяч файлов,
     * что замедляет файловую систему (особенно ext4).
     */
    private function getFilePath(string $key): string
    {
        $hash = md5($key);
        $shard = substr($hash, 0, 2); // Первые 2 символа — имя подпапки
        
        return $this->cacheDir . '/' . $shard . '/' . $this->prefix . $hash . '.cache';
    }
}
