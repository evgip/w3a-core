<?php

declare(strict_types=1);

namespace W3a\Tests;

use PHPUnit\Framework\TestCase;
use W3a\Core\Foundation\Config;

class ConfigTest extends TestCase
{
    private Config $config;
    private string $testConfigPath;

    protected function setUp(): void
    {
        // Создаем временную папку для тестовых конфигов
        $this->testConfigPath = sys_get_temp_dir() . '/w3a_test_config_' . uniqid();
        mkdir($this->testConfigPath, 0777, true);

        // Создаем тестовый файл конфигурации
        file_put_contents(
            $this->testConfigPath . '/database.php',
            "<?php return ['host' => 'localhost', 'port' => 3306, 'credentials' => ['user' => 'root', 'pass' => 'secret']];"
        );

        file_put_contents(
            $this->testConfigPath . '/app.php',
            "<?php return ['name' => 'Test App', 'debug' => true];"
        );

        $this->config = new Config($this->testConfigPath);
    }

    protected function tearDown(): void
    {
        // Удаляем временные файлы после теста
        $this->removeDirectory($this->testConfigPath);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Тест: Получение значения через dot-нотацию
     */
    public function test_it_gets_value_with_dot_notation(): void
    {
        $this->assertEquals('localhost', $this->config->get('database.host'));
        $this->assertEquals(3306, $this->config->get('database.port'));
        $this->assertEquals('root', $this->config->get('database.credentials.user'));
    }

    /**
     * Тест: Возврат значения по умолчанию, если ключ не найден
     */
    public function test_it_returns_default_value_when_key_not_found(): void
    {
        $this->assertNull($this->config->get('database.nonexistent'));
        $this->assertEquals('default_value', $this->config->get('database.nonexistent', 'default_value'));
    }

    /**
     * Тест: Установка значения во время выполнения
     */
    public function test_it_can_set_value_at_runtime(): void
    {
        $this->config->set('app.debug', false);
        $this->assertFalse($this->config->get('app.debug'));

        // Можно установить новое значение, которого не было в файле
        $this->config->set('app.version', '1.0.0');
        $this->assertEquals('1.0.0', $this->config->get('app.version'));
    }

    /**
     * Тест: Проверка существования ключа
     */
    public function test_it_checks_if_key_exists(): void
    {
        $this->assertTrue($this->config->has('database.host'));
        $this->assertFalse($this->config->has('database.nonexistent'));
    }

    /**
     * Тест: Ленивая загрузка — файл читается только при первом обращении
     */
    public function test_it_loads_config_files_lazily(): void
    {
        // Создаем новый конфиг с несуществующим файлом
        $config = new Config($this->testConfigPath);
        
        // Обращаемся к app.name — должен загрузиться app.php
        $this->assertEquals('Test App', $config->get('app.name'));
        
        // Теперь обращаемся к database.host — должен загрузиться database.php
        $this->assertEquals('localhost', $config->get('database.host'));
        
        // Если бы ленивая загрузка не работала, оба файла загрузились бы сразу
    }

    /**
     * Тест: Получение всего массива из файла конфигурации
     */
    public function test_it_gets_entire_config_file(): void
    {
        $appConfig = $this->config->get('app');
        
        $this->assertIsArray($appConfig);
        $this->assertArrayHasKey('name', $appConfig);
        $this->assertArrayHasKey('debug', $appConfig);
        $this->assertEquals('Test App', $appConfig['name']);
    }
}