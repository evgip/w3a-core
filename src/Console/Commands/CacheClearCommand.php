<?php

declare(strict_types=1);

namespace W3a\Core\Console\Commands;

use W3a\Core\Console\Application;

/**
 * Команда для очистки кэша приложения.
 */
class CacheClearCommand
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function handle(array $args): void
    {
        // Создаем экземпляр Application только для красивого вывода
        $app = new Application($this->basePath); 
        
        $cacheDir = $this->basePath . '/storage/cache';
        
        if (!is_dir($cacheDir)) {
            $app->output("ℹ️ Директория кэша не существует. Нечего очищать.", 'yellow');
            return;
        }

        $filesToClear = [
            'routes_compiled.php',
            'views_paths.php',
            'providers.php', // На случай, если вы добавите кэширование провайдеров
        ];

        $clearedCount = 0;

        foreach ($filesToClear as $file) {
            $filePath = $cacheDir . '/' . $file;
            if (file_exists($filePath)) {
                if (unlink($filePath)) {
                    $app->output("  ✅ Удален: {$file}", 'green');
                    $clearedCount++;
                } else {
                    $app->output("  ❌ Не удалось удалить: {$file} (проверьте права)", 'red');
                }
            }
        }

        if ($clearedCount === 0) {
            $app->output("ℹ️ Кэш уже был очищен (файлы не найдены).", 'yellow');
        } else {
            $app->output("🎉 Успешно очищено файлов: {$clearedCount}", 'green');
        }
    }
}