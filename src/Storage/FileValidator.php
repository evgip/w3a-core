<?php

declare(strict_types=1);

namespace W3a\Core\Storage;

use W3a\Core\Storage\Exceptions\ValidationException;

/**
 * Валидатор загруженных файлов.
 * 
 * Проверяет MIME-тип (по реальному содержимому, а не по расширению),
 * размер файла и расширение.
 * 
 * Использование:
 *   $validator = new FileValidator([
 *       'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
 *       'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
 *       'max_size' => 5 * 1024 * 1024, // 5 MB
 *   ]);
 *   
 *   if (!$validator->validate($uploadedFile)) {
 *       $errors = $validator->getErrors();
 *   }
 */
class FileValidator
{
    private array $rules;
    private array $errors = [];

    /**
     * @param array $rules Правила валидации:
     *   - mimes: array — разрешённые MIME-типы (проверяются через finfo)
     *   - extensions: array — разрешённые расширения
     *   - max_size: int — максимальный размер в байтах
     *   - min_size: int — минимальный размер в байтах
     */
    public function __construct(array $rules = [])
    {
        $this->rules = $rules;
    }

    /**
     * Валидировать загруженный файл.
     *
     * @return bool true, если файл прошёл все проверки
     */
    public function validate(UploadedFile $file): bool
    {
        $this->errors = [];

        $this->validateMimeType($file);
        $this->validateExtension($file);
        $this->validateSize($file);

        return empty($this->errors);
    }

    /**
     * Получить список ошибок после валидации.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Получить первую ошибку (для простого вывода).
     */
    public function getFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    /**
     * Валидировать и выбросить исключение при ошибке.
     *
     * @throws ValidationException
     */
    public function validateOrFail(UploadedFile $file): void
    {
        if (!$this->validate($file)) {
            throw new ValidationException(
                'Ошибка валидации файла: ' . implode('; ', $this->errors)
            );
        }
    }

    /**
     * Проверка MIME-типа через finfo (по реальному содержимому файла).
     */
    private function validateMimeType(UploadedFile $file): void
    {
        if (!isset($this->rules['mimes'])) {
            return;
        }

        $allowed = $this->rules['mimes'];
        $actual = $file->getMimeType();

        if (!in_array($actual, $allowed, true)) {
            $this->errors[] = sprintf(
                'Недопустимый тип файла: %s. Разрешены: %s',
                $actual,
                implode(', ', $allowed)
            );
        }
    }

    /**
     * Проверка расширения файла.
     */
    private function validateExtension(UploadedFile $file): void
    {
        if (!isset($this->rules['extensions'])) {
            return;
        }

        $allowed = array_map('strtolower', $this->rules['extensions']);
        $actual = strtolower($file->getExtension());

        if (!in_array($actual, $allowed, true)) {
            $this->errors[] = sprintf(
                'Недопустимое расширение: %s. Разрешены: %s',
                $actual,
                implode(', ', $allowed)
            );
        }
    }

    /**
     * Проверка размера файла.
     */
    private function validateSize(UploadedFile $file): void
    {
        $size = $file->getSize();

        if (isset($this->rules['max_size']) && $size > $this->rules['max_size']) {
            $maxFormatted = $this->formatBytes($this->rules['max_size']);
            $this->errors[] = "Размер файла превышает максимальный: {$maxFormatted}";
        }

        if (isset($this->rules['min_size']) && $size < $this->rules['min_size']) {
            $minFormatted = $this->formatBytes($this->rules['min_size']);
            $this->errors[] = "Размер файла меньше минимального: {$minFormatted}";
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2) . ' ' . $units[$unit];
    }
}