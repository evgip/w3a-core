<?php

declare(strict_types=1);

namespace W3a\Core\View;

use W3a\Core\Exceptions\HttpException;

/**
 * Класс для рендеринга шаблонов.
 */
class View
{
    /**
     * Отрендерить шаблон в изолированной области видимости.
     * 
     * @param string $__viewFile__ Абсолютный путь к файлу шаблона
     * @param array $__data__ Данные для передачи в шаблон
     * @return string Отрендеренный HTML
     */
    public function render(string $__viewFile__, array $__data__ = []): string
    {
        if (!file_exists($__viewFile__)) {
            throw new HttpException(500, "View file not found: {$__viewFile__}");
        }

        ob_start();
        (function () use ($__data__, $__viewFile__) {
            extract($__data__, EXTR_SKIP);
            include $__viewFile__;
        })();

        return ob_get_clean();
    }
}