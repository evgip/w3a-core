<?php

declare(strict_types=1);

namespace W3a\Core\Contracts;

/**
 * Интерфейс для обработки и отображения ошибок.
 * Позволяет ядру делегировать рендеринг ошибок основному приложению,
 * не зная о конкретных контроллерах или модулях.
 */
interface ErrorHandlerInterface
{
    /**
     * Отрисовать страницу или ответ с ошибкой.
     *
     * @param int $code HTTP-код ошибки (404, 500, 403 и т.д.)
     * @param string $message Текст ошибки
     * @param array $context Дополнительный контекст (например, URL, метод запроса)
     */
    public function render(int $code, string $message, array $context = []): void;
}
