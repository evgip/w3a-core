<?php

declare(strict_types=1);

namespace W3a\Core;

use RuntimeException;

class Logger
{
    private string $logFile;
    private string $dateFormat;

    /**
     * Конструктор с инъекцией пути к файлу логов.
     * 
     * @param string|null $logFile Абсолютный путь к файлу логов. 
     *                             Если null, будет предпринята попытка умного поиска корня проекта.
     * @param string $dateFormat Формат даты для записей в логе
     */
    public function __construct(?string $logFile = null, string $dateFormat = 'Y-m-d H:i:s')
    {
        if ($logFile === null) {
            // 🔥 УМНЫЙ ПОИСК КОРНЯ ПРОЕКТА
            // Поднимаемся по дереву каталогов, пока не найдем папку, где есть 'vendor' и 'app'
            $currentDir = dirname(__DIR__);
            while ($currentDir !== dirname($currentDir)) {
                if (is_dir($currentDir . '/vendor') && is_dir($currentDir . '/app')) {
                    $logFile = $currentDir . '/storage/logs/app.log';
                    break;
                }
                $currentDir = dirname($currentDir);
            }
            
            // Fallback: если умный поиск не сработал (например, ядро используется изолированно)
            if ($logFile === null) {
                $logFile = dirname(__DIR__, 2) . '/storage/logs/app.log';
            }
        }

        $this->logFile = $logFile;
        $this->dateFormat = $dateFormat;

        // Создаём директорию, если её нет
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true) && !is_dir($logDir)) {
                throw new RuntimeException("Не удалось создать директорию для логов: {$logDir}");
            }
        }
    }

    /**
     * Запись лога с указанным уровнем
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date($this->dateFormat);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';

        $contextStr = !empty($context)
            ? ' | Контекст: ' . json_encode($context, JSON_UNESCAPED_UNICODE)
            : '';

        $logMessage = "[{$timestamp}] [{$ip}] [{$level}]: {$message}{$contextStr}" . PHP_EOL;

        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }

    /**
     * Запись лога уровня ERROR
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    /**
     * Запись лога уровня WARNING
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    /**
     * Запись лога уровня INFO
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    /**
     * Запись лога уровня DEBUG
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    /**
     * Получить путь к файлу логов
     */
    public function getLogFile(): string
    {
        return $this->logFile;
    }

    /**
     * Очистить файл логов
     */
    public function clear(): bool
    {
        if (file_exists($this->logFile)) {
            return unlink($this->logFile);
        }
        return false;
    }
}
