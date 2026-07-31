<?php

declare(strict_types=1);

namespace W3a\Tests;

use PHPUnit\Framework\TestCase;
use W3a\Core\Foundation\Config;

class ConfigTest extends TestCase
{
    private Config $config;
    private string $testAppConfigPath;
    private string $testCoreConfigPath;

    protected function setUp(): void
    {
        // Создаем временные папки для тестовых конфигов Ядра и Приложения
        $this->testCoreConfigPath = sys_get_temp_dir() . '/w3a_test_core_config_' . uniqid();
        $this->testAppConfigPath = sys_get_temp_dir() . '/w3a_test_app_config_' . uniqid();
        
        mkdir($this->testCoreConfigPath, 0777, true);
        mkdir($this->testAppConfigPath, 0777, true);

        // 1. Создаем тестовый файл конфигурации в ЯДРЕ (базовые значения по умолчанию)
        file_put_contents(
            $this->testCoreConfigPath . '/database.php',
            "<?php return ['host' => 'localhost', 'port' => 3306, 'credentials' => ['user' => 'root', 'pass' => 'secret']];"
        );

        file_put_contents(
            $this->testCoreConfigPath . '/app.php',
            "<?php return ['name' => 'Core App', 'debug' => false, 'version' => '1.0'];"
        );

        // 2. Создаем тестовый файл конфигурации в ПРИЛОЖЕНИИ (переопределения)
        // Мы намеренно указываем здесь только те ключи, которые хотим изменить
        file_put_contents(
            $this->testAppConfigPath . '/database.php',
            "<?php return ['host' => '192.168.1.100'];" // Переопределяем только host
        );

        file_put_contents(
            $this->testAppConfigPath . '/app.php',
            "<?php return ['debug' => true];" // Переопределяем только debug
        );

        // 3. Инициализируем Config с ОБОИМИ путями. 
        // Приложение ($testAppConfigPath) имеет приоритет над Ядром ($testCoreConfigPath).
        $this->config = new Config($this->testAppConfigPath, $this->testCoreConfigPath);
    }

    protected function tearDown(): void
    {
        // Удаляем временные файлы после теста
        $this->removeDirectory($this->testAppConfigPath);
        $this->removeDirectory($this->testCoreConfigPath);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Тест: Слияние конфигов (App переопределяет Core, но сохраняет остальные ключи)
     */
    public function test_merges_core_and_app_configs_correctly(): void
    {
        // Проверяем, что значение из App перезаписало значение из Core
        $this->assertEquals('192.168.1.100', $this->config->get('database.host'));
        $this->assertTrue($this->config->get('app.debug')); 

        // Проверяем, что значения, которых нет в App, успешно взялись из Core
        $this->assertEquals(3306, $this->config->get('database.port')); // Из Core
        $this->assertEquals('root', $this->config->get('database.credentials.user')); // Из Core (вложенный массив!)
        $this->assertEquals('Core App', $this->config->get('app.name')); // Из Core
    }

    /**
     * Тест: Получение значения через dot-нотацию
     */
    public function test_it_gets_value_with_dot_notation(): void
    {
        // Host теперь берется из App (переопределен)
        $this->assertEquals('192.168.1.100', $this->config->get('database.host'));
        
        // Остальное берется из Core
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
        $this->config->set('app.version_new', '2.0.0');
        $this->assertEquals('2.0.0', $this->config->get('app.version_new'));
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
        // Создаем новый конфиг с обоими путями
        $config = new Config($this->testAppConfigPath, $this->testCoreConfigPath);
        
        // Обращаемся к app.name — должны загрузиться и слиться app.php из обоих путей
        $this->assertEquals('Core App', $config->get('app.name'));
        
        // Теперь обращаемся к database.host — должны загрузиться database.php
        $this->assertEquals('192.168.1.100', $config->get('database.host'));
    }

    /**
     * Тест: Получение всего массива из файла конфигурации
     */
    public function test_it_gets_entire_config_file(): void
    {
        $appConfig = $this->config->get('app');
        
        $this->assertIsArray($appConfig);
        $this->assertArrayHasKey('name', $appConfig);   // Из Core
        $this->assertArrayHasKey('debug', $appConfig);  // Из App (переопределено)
        $this->assertArrayHasKey('version', $appConfig); // Из Core
        $this->assertEquals('Core App', $appConfig['name']);
        $this->assertTrue($appConfig['debug']);
    }
}