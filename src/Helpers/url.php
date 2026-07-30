<?php

declare(strict_types=1);

use W3a\Core\Http\Router;

/**
 * Генерация URL по имени именованного маршрута.
 *
 * @param string $name Имя маршрута (например, 'profile' или 'story.show').
 * @param array $params Ассоциативный массив параметров для подстановки в URL.
 * @return string Сгенерированный URL. В случае ошибки возвращает '#route-error'.
 * 
 * @example
 * route('profile', ['id' => 1]); // Вернет: /user/1
 * route('story.show', ['slug' => 'hello-world']); // Вернет: /story/hello-world
 */
if (!function_exists('route')) {
    function route(string $name, array $params = []): string
    {
        try {
            $router = container(Router::class);
            return $router->route($name, $params);
        } catch (\Throwable $e) {
            error_log("Route helper failed for '{$name}': " . $e->getMessage());
            return '#route-error';
        }
    }
}

/**
 * Выполнение HTTP-редиректа.
 * Выбрасывает специальное исключение, которое перехватывается Application для чистого завершения запроса.
 *
 * @param string $url Целевой URL для перенаправления.
 * @param int $code HTTP-код статуса (по умолчанию 302 Found).
 * @return never Эта функция никогда не возвращает управление.
 * 
 * @example
 * return redirect('/login', 301);
 */
if (!function_exists('redirect')) {
    function redirect(string $url, int $code = 302): never
    {
        throw new \W3a\Core\Exceptions\RedirectException($url, $code);
    }
}

/**
 * Генерация URL для конкретного комментария с HTML-якорем.
 * (Специфичный хелпер для бизнес-логики приложения).
 *
 * @param int $storyId ID истории (поста), к которой относится комментарий.
 * @param int $commentId ID самого комментария.
 * @return string URL с фрагментом (например, `/story/123#comment-block-456`).
 */
if (!function_exists('comment_url')) {
    function comment_url(int $storyId, int $commentId): string
    {
        $baseUrl = "/story/{$storyId}";
        $anchor = "comment-block-{$commentId}";
        return "{$baseUrl}#{$anchor}";
    }
}