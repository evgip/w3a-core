<?php

declare(strict_types=1);

namespace W3a\Core\Storage\Contracts;

/**
 * Контракт для работы с файловым хранилищем.
 * 
 * Любая реализация (локальная ФС, S3, FTP) должна реализовать этот интерфейс.
 * Это позволяет менять хранилище через конфиг, не трогая бизнес-код.
 */
interface StorageInterface
{
    /**
     * Сохранить содержимое по указанному пути.
     *
     * @param string $path Путь относительно корня диска
     * @param string $contents Содержимое файла
     * @return bool true при успехе
     */
    public function put(string $path, string $contents): bool;

    /**
     * Сохранить загруженный файл (объект UploadedFile) на диск.
     * Автоматически генерирует уникальное имя, если не указано.
     *
     * @param \W3a\Core\Storage\UploadedFile $file Загруженный файл
     * @param string $directory Директория внутри диска
     * @param string|null $name Кастомное имя (без расширения). Если null — генерируется.
     * @return string Путь к сохранённому файлу относительно корня диска
     */
    public function putFile(\W3a\Core\Storage\UploadedFile $file, string $directory = '', ?string $name = null): string;

    /**
     * Получить содержимое файла.
     *
     * @throws \W3a\Core\Storage\Exceptions\FileNotFoundException
     */
    public function get(string $path): string;

    /**
     * Проверить существование файла.
     */
    public function exists(string $path): bool;

    /**
     * Удалить файл.
     *
     * @return bool true, если файл существовал и был удалён
     */
    public function delete(string $path): bool;

    /**
     * Получить публичный URL к файлу (если диск публичный).
     * Для приватных дисков может возвращать null.
     */
    public function url(string $path): ?string;

    /**
     * Получить абсолютный путь к файлу на диске.
     */
    public function path(string $path): string;

    /**
     * Получить размер файла в байтах.
     *
     * @throws \W3a\Core\Storage\Exceptions\FileNotFoundException
     */
    public function size(string $path): int;

    /**
     * Получить время последней модификации файла (timestamp).
     *
     * @throws \W3a\Core\Storage\Exceptions\FileNotFoundException
     */
    public function lastModified(string $path): int;

    /**
     * Создать директорию (рекурсивно).
     */
    public function makeDirectory(string $path): bool;

    /**
     * Удалить директорию рекурсивно.
     */
    public function deleteDirectory(string $path): bool;

    /**
     * Получить список файлов в директории.
     *
     * @return array<string> Массив путей относительно корня диска
     */
    public function files(string $directory = ''): array;
}