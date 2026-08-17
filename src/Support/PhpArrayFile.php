<?php

declare(strict_types=1);

namespace W3a\Core\Support;

/**
 * Унифицированное чтение/запись PHP-массивов в файл.
 *
 * Используется для всех "var_export + require"-кэшей ядра
 * (маршруты, пути шаблонов, провайдеры):
 * - Запись атомарная: tmp-файл + rename() — нет race condition.
 * - После записи сбрасывается OPcache, чтобы PHP загрузил новую версию.
 * - Чтение безопасное: повреждённый/отсутствующий файл возвращает null,
 *   а не падает с фатальной ошибкой.
 */
final class PhpArrayFile
{
    /**
     * Атомарно записать массив в PHP-файл (формат "return array;").
     *
     * @return bool true при успехе, false при ошибке записи
     */
    public static function write(string $file, array $data): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $code = "<?php\nreturn " . var_export($data, true) . ";\n";

        $tmp = $file . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $code, LOCK_EX) === false) {
            return false;
        }

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }

        return true;
    }

    /**
     * Прочитать массив из PHP-файла.
     *
     * @return array|null null, если файл отсутствует, повреждён или не содержит массив
     */
    public static function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        try {
            $data = @include $file;
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
