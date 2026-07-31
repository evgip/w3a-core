<?php

declare(strict_types=1);

namespace W3a\Core\Foundation;

use RuntimeException;

/**
 * Сервис конфигурации с поддержкой dot-нотации и ленивой загрузкой файлов.
 * 
 * Файлы конфигурации загружаются ТОЛЬКО при первом обращении
 * к их ключам. Это исключает ненужные операции чтения с диска и парсинга PHP
 * для конфигов, которые не используются в текущем HTTP-запросе.
 * 
 * Пример: Если запрос обращается только к config('app.name'), файлы database.php,
 * mail.php, rate_limit.php и т.д. НЕ будут прочитаны с диска.
 * 
 * Поддерживает dot-нотацию для доступа к вложенным значениям:
 * - config('database.host') — загрузит database.php и вернет значение ключа 'host'
 * - config('app.theme.name') — загрузит app.php и вернет вложенное значение
 * - config('app') — вернет весь массив из файла app.php
 */
class Config
{
    /**
     * Путь к директории с файлами конфигурации приложения (например, /app/Config).
     * Имеет высший приоритет при слиянии.
     */
    private string $configPath;

    /**
     * Путь к директории с файлами конфигурации ядра (значения по умолчанию).
     */
    private string $coreConfigPath = '';

    /**
     * Хранилище загруженных конфигураций.
     * Ключ верхнего уровня — это имя файла (без расширения .php).
     * 
     * Пример структуры:
     * [
     *     'app' => ['env' => 'development', 'theme' => 'default', ...],
     *     'database' => ['host' => '127.0.0.1', 'name' => 'mydb', ...],
     * ]
     * 
     * Файл загружается в этот массив ТОЛЬКО при первом обращении к его ключам.
     */
    private array $settings = [];

    /**
     * Конструктор.
     *
     * @param string $configPath Абсолютный путь к папке с конфигами приложения (например, /app/Config)
     * @param string|null $coreConfigPath Абсолютный путь к папке с конфигами ядра (необязательно)
     * @throws RuntimeException Если директория приложения не существует
     */
    public function __construct(string $configPath, ?string $coreConfigPath = null)
    {
        if (!is_dir($configPath)) {
            throw new RuntimeException("Директория конфигурации не найдена: {$configPath}");
        }
        $this->configPath = rtrim($configPath, '/\\');
        $this->coreConfigPath = $coreConfigPath !== null ? rtrim($coreConfigPath, '/\\') : '';
    }

    /**
     * Получить значение конфигурации по ключу с поддержкой dot-нотации.
     * 
     * 🔥 ЛЕНИВАЯ ЗАГРУЗКА: Файл читается с диска только при первом обращении.
     * Последующие обращения к тому же файлу мгновенно возвращают значение из памяти.
     *
     * @param string $key Ключ в формате 'файл.ключ.вложенный_ключ' 
     *                    (например, 'database.host' или 'app.theme.name')
     * @param mixed $default Значение по умолчанию, если ключ не найден
     * @return mixed Значение конфигурации или $default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // 1. Разделяем ключ на имя файла и путь внутри массива
        // Например: 'database.default' -> $fileName = 'database', $arrayPath = 'default'
        $parts = explode('.', $key, 2);
        $fileName = $parts[0];
        $arrayPath = $parts[1] ?? null;

        // 2. 🔥 ЛЕНИВАЯ ЗАГРУЗКА И СЛИЯНИЕ: Если файл еще не загружен в память, загружаем и сливаем его
        if (!array_key_exists($fileName, $this->settings)) {
            $coreFilePath = $this->coreConfigPath !== '' ? $this->coreConfigPath . '/' . $fileName . '.php' : '';
            $appFilePath = $this->configPath . '/' . $fileName . '.php';
            
            $coreData = ($coreFilePath !== '' && file_exists($coreFilePath)) ? require $coreFilePath : [];
            $appData = file_exists($appFilePath) ? require $appFilePath : [];

            // 🔥 СЛИЯНИЕ: Настройки приложения имеют приоритет над настройками ядра.
            // Ключи, отсутствующие в приложении, будут взяты из ядра.
            $this->settings[$fileName] = array_replace_recursive($coreData, $appData);
        }

        // 3. Если запрошен весь массив файла целиком (например, config('database'))
        if ($arrayPath === null) {
            return $this->settings[$fileName];
        }

        // 4. Поиск значения по вложенному пути внутри загруженного массива
        return $this->getNestedValue($this->settings[$fileName], $arrayPath, $default);
    }

    /**
     * Получить значение конфигурации, гарантированно как массив.
     * Если значение не является массивом, возвращается $default.
     *
     * @param string $key Ключ конфигурации
     * @param array $default Значение по умолчанию
     * @return array
     */
    public function getArray(string $key, array $default = []): array
    {
        $value = $this->get($key);
        return is_array($value) ? $value : $default;
    }

    /**
     * Получить значение конфигурации, гарантированно как строку.
     * Если значение не является строкой, возвращается $default.
     *
     * @param string $key Ключ конфигурации
     * @param string $default Значение по умолчанию
     * @return string
     */
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key);
        return is_string($value) ? $value : $default;
    }

    /**
     * Получить значение конфигурации, гарантированно как целое число.
     * Поддерживает автоматическое приведение числовых строк к int.
     *
     * @param string $key Ключ конфигурации
     * @param int $default Значение по умолчанию
     * @return int
     */
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        return is_numeric($value) ? (int)$value : $default;
    }

    /**
     * Получить значение конфигурации, гарантированно как boolean.
     * 
     * Умное преобразование: понимает строки 'true', '1', 'yes', 'on' как true,
     * и 'false', '0', 'no', 'off' как false.
     *
     * @param string $key Ключ конфигурации
     * @param bool $default Значение по умолчанию
     * @return bool
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
        }
        
        if (is_numeric($value)) {
            return (int)$value !== 0;
        }
        
        return $default;
    }

    /**
     * Проверить существование ключа в конфигурации.
     *
     * @param string $key Ключ конфигурации
     * @return bool true, если ключ существует и не равен null
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Установить значение конфигурации во время выполнения.
     * 
     * Полезно для:
     * - Тестов (переопределение настроек)
     * - Динамического изменения конфигурации в рантайме
     * - Слияния настроек модулей с базовыми конфигами
     *
     * @param string $key Ключ в формате 'файл.путь.к.значению'
     * @param mixed $value Новое значение
     */
    public function set(string $key, mixed $value): void
    {
        $parts = explode('.', $key, 2);
        $fileName = $parts[0];
        $arrayPath = $parts[1] ?? null;

        // Если файл еще не загружен, инициализируем его пустым массивом
        if (!array_key_exists($fileName, $this->settings)) {
            $this->settings[$fileName] = [];
        }

        // Если нужно переопределить весь файл целиком
        if ($arrayPath === null) {
            $this->settings[$fileName] = $value;
            return;
        }

        // Установка вложенного значения через рекурсивный обход
        $keys = explode('.', $arrayPath);
        $current = &$this->settings[$fileName];

        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $current[$key] = $value;
            } else {
                if (!isset($current[$key]) || !is_array($current[$key])) {
                    $current[$key] = [];
                }
                $current = &$current[$key];
            }
        }
    }

    /**
     * Добавить путь к конфигурации модуля.
     * 
     * Позволяет модулям переопределять или дополнять базовые конфиги.
     * Настройки модуля имеют приоритет над базовыми (сливаются поверх).
     * 
     * Пример: Если в app/Config/app.php задано 'theme' => 'default',
     * а в app/Modules/Admin/Config/app.php задано 'theme' => 'admin_dark',
     * итоговое значение будет 'admin_dark'.
     *
     * @param string $moduleName Имя модуля (для логирования/отладки)
     * @param string $moduleConfigPath Путь к папке конфигов модуля
     */
    public function addModulePath(string $moduleName, string $moduleConfigPath): void
    {
        $files = glob($moduleConfigPath . '/*.php');
        
        foreach ($files as $file) {
            $fileName = basename($file, '.php');
            $moduleData = require $file;
            
            // Если базовый конфиг еще не загружен, инициализируем пустым массивом
            if (!array_key_exists($fileName, $this->settings)) {
                $this->settings[$fileName] = [];
            }
            
            // 🔥 Рекурсивное слияние: настройки модуля имеют приоритет
            $this->settings[$fileName] = array_replace_recursive(
                $this->settings[$fileName],
                $moduleData
            );
        }
    }

    /**
     * Рекурсивный поиск значения в массиве по строке с точками.
     * 
     * Внутренний метод, используется для обработки вложенных путей
     * вроде 'theme.name' внутри уже загруженного массива файла.
     *
     * @param array $array Массив для поиска
     * @param string $path Путь в формате 'ключ.вложенный_ключ'
     * @param mixed $default Значение по умолчанию
     * @return mixed Найденное значение или $default
     */
    private function getNestedValue(array $array, string $path, mixed $default): mixed
    {
        $keys = explode('.', $path);
        $current = $array;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return $default;
            }
            $current = $current[$key];
        }

        return $current;
    }
}