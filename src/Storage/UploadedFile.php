<?php

declare(strict_types=1);

namespace W3a\Core\Storage;

use W3a\Core\Storage\Exceptions\UploadException;
use RuntimeException;

/**
 * Обёртка над загруженным файлом из $_FILES.
 * 
 * Предоставляет безопасный API для работы с загруженными файлами:
 * - Проверка через isUploadedFile() (защита от подмены)
 * - Определение реального MIME-типа через finfo (а не по расширению)
 * - Безопасное перемещение через moveTo()
 * - Получение размера, имени, расширения
 */
class UploadedFile
{
    private string $tempPath;
    private string $originalName;
    private int $size;
    private int $error;
    private ?string $mimeType = null;

    /**
     * @param array $fileData Массив из $_FILES (один элемент)
     * @throws UploadException Если файл не был загружен через HTTP
     */
    public function __construct(array $fileData)
    {
        if (!isset($fileData['tmp_name'], $fileData['name'], $fileData['size'], $fileData['error'])) {
            throw new UploadException('Некорректная структура данных загруженного файла');
        }

        $this->tempPath = (string) $fileData['tmp_name'];
        $this->originalName = (string) $fileData['name'];
        $this->size = (int) $fileData['size'];
        $this->error = (int) $fileData['error'];

        // Проверка: файл действительно был загружен через HTTP
        if (!is_uploaded_file($this->tempPath)) {
            throw new UploadException('Файл не был загружен через HTTP POST');
        }

        // Проверка ошибок загрузки
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new UploadException($this->getErrorMessage());
        }
    }

    /**
     * Создать из массива $_FILES['fieldname'].
     */
    public static function fromRequest(string $fieldName): self
    {
        if (!isset($_FILES[$fieldName])) {
            throw new UploadException("Поле '{$fieldName}' отсутствует в загруженных файлах");
        }

        return new self($_FILES[$fieldName]);
    }

    /**
     * Переместить файл в указанное место.
     * Использует move_uploaded_file() для безопасности.
     */
    public function moveTo(string $destination): bool
    {
        $directory = dirname($destination);
        
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("Не удалось создать директорию: {$directory}");
            }
        }

        return move_uploaded_file($this->tempPath, $destination);
    }

    /**
     * Получить оригинальное имя файла (как было у клиента).
     * ⚠️ НЕ используйте для сохранения — только для отображения.
     */
    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    /**
     * Получить "чистое" имя файла (без расширения, с санитизацией).
     * Подходит для генерации имени при сохранении.
     */
    public function getCleanName(): string
    {
        $name = pathinfo($this->originalName, PATHINFO_FILENAME);
        
        // Транслитерация и санитизация
        $name = preg_replace('/[^\p{L}\p{N}_-]/u', '_', $name);
        $name = preg_replace('/_+/', '_', $name);
        
        return trim($name, '_') ?: 'file';
    }

    /**
     * Получить расширение файла (из оригинального имени).
     */
    public function getExtension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    /**
     * Определить реальное расширение по MIME-типу через finfo.
     * Безопаснее, чем доверять расширению из имени файла.
     */
    public function guessExtension(): string
    {
        $mimeType = $this->getMimeType();
        
        // Карта популярных MIME-типов в расширения
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
        ];

        return $map[$mimeType] ?? $this->getExtension() ?: 'bin';
    }

    /**
     * Получить реальный MIME-тип файла через finfo.
     * Это единственный надёжный способ — расширение можно подделать.
     */
    public function getMimeType(): string
    {
        if ($this->mimeType === null) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($this->tempPath);
            $this->mimeType = $detected ?: 'application/octet-stream';
        }

        return $this->mimeType;
    }

    /**
     * Получить размер файла в байтах.
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Получить размер в человекочитаемом формате.
     */
    public function getSizeFormatted(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->size;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2) . ' ' . $units[$unit];
    }

    /**
     * Проверить, является ли файл изображением.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->getMimeType(), 'image/');
    }

    /**
     * Получить путь к временному файлу.
     */
    public function getTempPath(): string
    {
        return $this->tempPath;
    }

    /**
     * Получить код ошибки загрузки.
     */
    public function getError(): int
    {
        return $this->error;
    }

    /**
     * Получить человекочитаемое сообщение об ошибке загрузки.
     */
    private function getErrorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_INI_SIZE => 'Размер файла превышает лимит upload_max_filesize в php.ini',
            UPLOAD_ERR_FORM_SIZE => 'Размер файла превышает лимит, указанный в HTML-форме',
            UPLOAD_ERR_PARTIAL => 'Файл был загружен только частично',
            UPLOAD_ERR_NO_FILE => 'Файл не был загружен',
            UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка на сервере',
            UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск',
            UPLOAD_ERR_EXTENSION => 'Загрузка остановлена PHP-расширением',
            default => 'Неизвестная ошибка загрузки файла',
        };
    }
}