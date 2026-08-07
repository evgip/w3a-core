<?php

declare(strict_types=1);

namespace W3a\Core\Storage;

use W3a\Core\Storage\Contracts\StorageInterface;
use W3a\Core\Storage\Exceptions\FileNotFoundException;
use W3a\Core\Storage\Exceptions\UploadException;
use RuntimeException;

/**
 * Реализация хранилища на локальной файловой системе.
 * 
 * Работает с файлами относительно заданного корневого каталога.
 * Все пути в методах — относительные (относительно корня диска).
 */
class LocalStorage implements StorageInterface
{
    private string $root;
    private string $visibility;
    private ?string $baseUrl;

    /**
     * @param string $root Абсолютный путь к корню диска
     * @param string $visibility 'public' или 'private'
     * @param string|null $baseUrl Публичный URL (только для public-дисков)
     */
    public function __construct(string $root, string $visibility = 'private', ?string $baseUrl = null)
    {
        $this->root = rtrim($root, '/\\');
        $this->visibility = $visibility;
        $this->baseUrl = $baseUrl ? rtrim($baseUrl, '/') : null;

        // Создаём корневую директорию, если её нет
        if (!is_dir($this->root)) {
            if (!mkdir($this->root, 0755, true) && !is_dir($this->root)) {
                throw new RuntimeException("Не удалось создать корневую директорию: {$this->root}");
            }
        }
    }

    public function put(string $path, string $contents): bool
    {
        $fullPath = $this->path($path);
        $directory = dirname($fullPath);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("Не удалось создать директорию: {$directory}");
            }
        }

        return file_put_contents($fullPath, $contents) !== false;
    }

    public function putFile(UploadedFile $file, string $directory = '', ?string $name = null): string
    {
        // Генерируем уникальное имя, если не указано
        $fileName = ($name ?? bin2hex(random_bytes(16))) . '.' . $file->guessExtension();
        
        // Нормализуем путь (защита от directory traversal)
        $directory = $this->normalizePath($directory);
        $relativePath = $directory ? "{$directory}/{$fileName}" : $fileName;
        
        $fullPath = $this->path($relativePath);
        
        // Создаём директорию, если нужно
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Перемещаем загруженный файл
        if (!$file->moveTo($fullPath)) {
            throw new UploadException("Не удалось переместить загруженный файл в: {$fullPath}");
        }

        return $relativePath;
    }

    public function get(string $path): string
    {
        $fullPath = $this->path($path);

        if (!is_file($fullPath)) {
            throw new FileNotFoundException("Файл не найден: {$path}");
        }

        $contents = file_get_contents($fullPath);
        
        if ($contents === false) {
            throw new RuntimeException("Не удалось прочитать файл: {$path}");
        }

        return $contents;
    }

    public function exists(string $path): bool
    {
        return is_file($this->path($path));
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->path($path);

        if (!is_file($fullPath)) {
            return false;
        }

        return @unlink($fullPath);
    }

    public function url(string $path): ?string
    {
        if ($this->visibility !== 'public' || $this->baseUrl === null) {
            return null;
        }

        // Нормализуем путь для URL (forward slashes)
        $normalizedPath = str_replace('\\', '/', $path);
        
        return $this->baseUrl . '/' . ltrim($normalizedPath, '/');
    }

    public function path(string $path): string
    {
        // Защита от directory traversal: ../../etc/passwd
        $normalizedPath = $this->normalizePath($path);
        
        return $this->root . '/' . $normalizedPath;
    }

    public function size(string $path): int
    {
        $fullPath = $this->path($path);

        if (!is_file($fullPath)) {
            throw new FileNotFoundException("Файл не найден: {$path}");
        }

        $size = filesize($fullPath);
        
        if ($size === false) {
            throw new RuntimeException("Не удалось получить размер файла: {$path}");
        }

        return $size;
    }

    public function lastModified(string $path): int
    {
        $fullPath = $this->path($path);

        if (!is_file($fullPath)) {
            throw new FileNotFoundException("Файл не найден: {$path}");
        }

        $mtime = filemtime($fullPath);
        
        if ($mtime === false) {
            throw new RuntimeException("Не удалось получить время модификации: {$path}");
        }

        return $mtime;
    }

    public function makeDirectory(string $path): bool
    {
        $fullPath = $this->path($path);

        if (is_dir($fullPath)) {
            return true;
        }

        return mkdir($fullPath, 0755, true);
    }

    public function deleteDirectory(string $path): bool
    {
        $fullPath = $this->path($path);

        if (!is_dir($fullPath)) {
            return false;
        }

        return $this->recursiveDelete($fullPath);
    }

    public function files(string $directory = ''): array
    {
        $fullPath = $this->path($directory);

        if (!is_dir($fullPath)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                // Возвращаем путь относительно корня диска
                $relativePath = substr($file->getPathname(), strlen($this->root) + 1);
                $files[] = str_replace('\\', '/', $relativePath);
            }
        }

        return $files;
    }

    /**
     * Получить корневой путь диска.
     */
    public function getRoot(): string
    {
        return $this->root;
    }

    /**
     * Получить видимость диска.
     */
    public function getVisibility(): string
    {
        return $this->visibility;
    }

    /**
     * Нормализация пути: убираем ../ и ./, приводим к единому формату.
     * Это защита от directory traversal атак.
     */
    private function normalizePath(string $path): string
    {
        // Убираем дублирующиеся слеши
        $path = preg_replace('#/+#', '/', $path);
        
        // Разбиваем на части и убираем . и ..
        $parts = explode('/', $path);
        $normalized = [];
        
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($normalized);
                continue;
            }
            $normalized[] = $part;
        }

        return implode('/', $normalized);
    }

    /**
     * Рекурсивное удаление директории.
     */
    private function recursiveDelete(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        return rmdir($directory);
    }
	
	/**
	 * Получить относительный путь от полного абсолютного пути.
	 */
	public function relativePath(string $absolutePath): string
	{
		$absolutePath = str_replace('\\', '/', $absolutePath);
		$root = str_replace('\\', '/', $this->root);
		
		if (str_starts_with($absolutePath, $root)) {
			return ltrim(substr($absolutePath, strlen($root)), '/');
		}
		
		return basename($absolutePath);
	}
}