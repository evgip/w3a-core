<?php

declare(strict_types=1);

namespace W3a\Core\Cache;

/**
 * Безопасный файловый кэш.
 *
 * - Данные сериализуются через serialize() (НЕТ исполнения произвольного PHP).
 * - Чтение под блокировкой flock(LOCK_SH).
 * - Атомарная запись: tmp-файл + rename() — нет race condition при конкурентной записи.
 * - Шардирование каталога: первые 2 символа MD5 — подпапки 00..ff (256 штук),
 *   что исключает деградацию ФС при десятках тысяч ключей.
 */
class FileCache
{
    private string $cacheDir;
    private string $prefix;

    public function __construct(string $cacheDir, string $prefix = 'app_')
    {
        $this->cacheDir = rtrim($cacheDir, '/\\');
        $this->prefix = $prefix;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Получить значение из кэша
     */
    public function get(string $key): mixed
    {
        $file = $this->getFilePath($key);

        if (!is_file($file)) {
            return null;
        }

        $fp = @fopen($file, 'rb');
        if ($fp === false) {
            return null;
        }

        try {
            if (!flock($fp, LOCK_SH)) {
                return null;
            }
            $contents = stream_get_contents($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        if ($contents === false || $contents === '') {
            return null;
        }

        $data = @unserialize($contents);
        if (!is_array($data) || !array_key_exists('value', $data)) {
            return null;
        }

        // Проверяем срок действия
        if ($data['expires'] > 0 && $data['expires'] < time()) {
            @unlink($file);
            return null;
        }

        return $data['value'];
    }

    /**
     * Сохранить значение в кэш (атомарная запись через tmp + rename)
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $file = $this->getFilePath($key);

        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $data = [
            'value' => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'created' => time(),
        ];

        $content = serialize($data);

        // Уникальный временный файл: параллельные процессы не мешают друг другу
        $tmp = $file . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * Удалить значение из кэша
     */
    public function delete(string $key): bool
    {
        $file = $this->getFilePath($key);

        if (is_file($file)) {
            return @unlink($file);
        }

        return true;
    }

    /**
     * Очистить весь кэш
     */
    public function clear(): bool
    {
        $this->deleteDirContents($this->cacheDir);
        return true;
    }

    /**
     * Проверить существование ключа
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Путь к файлу: shard-подпапка (2 символа MD5) + префикс + хэш.
     * Пример: storage/cache/data/a1/app_<md5>.cache
     */
    private function getFilePath(string $key): string
    {
        $hash = md5($this->prefix . $key);
        $shard = substr($hash, 0, 2);

        return $this->cacheDir . '/' . $shard . '/' . $this->prefix . $hash . '.cache';
    }

    /**
     * Рекурсивное удаление содержимого директории (поддиректории остаются пустыми).
     */
    private function deleteDirContents(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }
}
