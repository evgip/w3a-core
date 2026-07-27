<?php

declare(strict_types=1);

namespace W3a\Core\Console;

/**
 * Базовое CLI-приложение ядра.
 * Отвечает за регистрацию и выполнение консольных команд.
 */
class Application
{
    private string $basePath;
    private array $commands = [];

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
        $this->registerDefaultCommands();
    }

    /**
     * Регистрация встроенных команд ядра.
     */
    private function registerDefaultCommands(): void
    {
        $this->commands['cache:clear'] = new Commands\CacheClearCommand($this->basePath);
        // В будущем здесь появятся: 'make:controller', 'db:migrate' и т.д.
    }

    /**
     * Запуск CLI-приложения.
     * 
     * @param array $argv Массив аргументов командной строки (из $_SERVER['argv'])
     */
    public function run(array $argv): void
    {
        $commandName = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        if ($commandName === 'help' || $commandName === '--help' || $commandName === '-h') {
            $this->printHelp();
            return;
        }

        if (isset($this->commands[$commandName])) {
            try {
                $this->commands[$commandName]->handle($args);
            } catch (\Throwable $e) {
                $this->output("❌ Ошибка выполнения: " . $e->getMessage(), 'red');
                exit(1);
            }
        } else {
            $this->output("⚠️ Неизвестная команда: '{$commandName}'", 'yellow');
            $this->output("Введите 'php bin/w3a help' для списка доступных команд.", 'white');
            exit(1);
        }
    }

    /**
     * Вывод цветного сообщения в консоль.
     */
    public function output(string $message, string $color = 'white'): void
    {
        $colors = [
            'red'    => "\033[31m",
            'green'  => "\033[32m",
            'yellow' => "\033[33m",
            'blue'   => "\033[34m",
            'white'  => "\033[37m",
            'reset'  => "\033[0m",
        ];

        $colorCode = $colors[$color] ?? $colors['white'];
        echo $colorCode . $message . $colors['reset'] . PHP_EOL;
    }

    private function printHelp(): void
    {
        $this->output("🚀 W3A Core CLI", 'blue');
        $this->output("Доступные команды:", 'white');
        foreach (array_keys($this->commands) as $cmd) {
            $this->output("  php bin/w3a {$cmd}", 'green');
        }
    }
}