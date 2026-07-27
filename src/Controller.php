<?php

declare(strict_types=1);

namespace W3a\Core;

use W3a\Core\Events\Event;
use W3a\Core\Events\EventDispatcher;
use W3a\Core\Exceptions\HttpException;
use W3a\Core\Exceptions\JsonResponseException;
use W3a\Core\Exceptions\RedirectException;
use W3a\Core\Contracts\UserIdProviderInterface;

/**
 * Базовый абстрактный контроллер для всех модулей.
 * Предоставляет общую функциональность, не зависящую от бизнес-логики приложения.
 */
abstract class Controller
{
    protected Request $request;
    protected EventDispatcher $eventDispatcher;
    protected Container $container;
    protected View $view;
    protected ViewFinder $viewFinder;
    protected UserIdProviderInterface $userIdProvider;

    public function __construct(
        Request $request,
        EventDispatcher $eventDispatcher,
        Container $container,
        View $view,
        ViewFinder $viewFinder,
        UserIdProviderInterface $userIdProvider
    ) {
        $this->request = $request;
        $this->eventDispatcher = $eventDispatcher;
        $this->container = $container;
        $this->view = $view;
        $this->viewFinder = $viewFinder;
        $this->userIdProvider = $userIdProvider;
    }

    protected function dispatch(Event $event): void
    {
        $this->eventDispatcher->dispatch($event);
    }

    // =========================================================================
    // КОНТЕКСТ ПОЛЬЗОВАТЕЛЯ (Только через интерфейсы!)
    // =========================================================================

    protected function getUserContext(): array
    {
        $userId = $this->userIdProvider->getUserId();
        $isLoggedIn = $userId !== null && (int)$userId > 0;

        return [
            'id' => (int)($userId ?? 0),
            'isLoggedIn' => $isLoggedIn,
            // isAdmin и isModerator должны определяться в модуле Auth, 
            // здесь мы возвращаем только базовый факт авторизации.
            // Для ролей лучше использовать отдельные Middleware или сервисы.
            'isAuthor' => fn(int $authorId): bool => $isLoggedIn && (int)$userId === $authorId,
        ];
    }

    // =========================================================================
    // РЕНДЕРИНГ
    // =========================================================================

    protected function render(string $viewName, array $data = []): void
    {
        $data['csrf_token'] = $this->request->getCsrfToken();
        
        // ✅ Объединяем с данными, которые предоставляет приложение (см. ниже)
        $data = array_merge($data, $this->getAppViewData());

        $calledClass = get_called_class();
        $parts = explode('\\', $calledClass);
        $moduleName = $parts[2] ?? 'Common';

        if (!empty($moduleName) && $moduleName !== 'Common') {
            \W3a\Core\Lang::loadModuleLang($moduleName);
        }

        $viewFile = $this->viewFinder->find($viewName, $moduleName);
        $content = $this->view->render($viewFile, $data);
        $layoutFile = $this->viewFinder->findLayout($moduleName);
        
        $data['content'] = $content;
        echo $this->view->render($layoutFile, $data);
    }

    /**
     * Метод-заглушка, который ПЕРЕОПРЕДЕЛЯЕТСЯ в базовом контроллере приложения.
     * Ядро не знает про уведомления, флаги и т.д.
     */
    protected function getAppViewData(): array
    {
        return [];
    }

    // =========================================================================
    // ОТВЕТЫ И РЕДИРЕКТЫ
    // =========================================================================

    protected function json(array $data, int $statusCode = 200): void
    {
        throw new JsonResponseException($data, $statusCode);
    }

    protected function redirect(string $url, int $code = 302): void
    {
        throw new RedirectException($url, $code);
    }

    protected function redirectBack(string $fallback = '/'): void
    {
        $this->redirect($this->getSafeBackUrl($fallback));
    }

    private function getSafeBackUrl(string $fallback = '/'): string
    {
        $referer = $this->request->header('HTTP_REFERER', $fallback);
        return $this->isSafeUrl($referer) ? $referer : $fallback;
    }

    private function isSafeUrl(string $url): bool
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }
        
        $urlHost = parse_url($url, PHP_URL_HOST);
        if ($urlHost === null) {
            return false;
        }

        $appHost = parse_url($this->container->get(Config::class)->get('app.url', ''), PHP_URL_HOST);
        return $urlHost === $appHost;
    }

    protected function redirectWithMessage(string $url, string $message, string $type = 'success'): void
    {
        $session = $this->container->get(Session::class);
        $session->flash($type, $message);
        $this->redirect($url);
    }

    protected function backWithMessage(string $message, string $type = 'success', string $fallback = '/'): void
    {
        $this->redirectWithMessage($this->getSafeBackUrl($fallback), $message, $type);
    }

    // =========================================================================
    // ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
    // =========================================================================

    protected function service(string $class): mixed
    {
        return $this->container->get($class);
    }

    protected function logError(\Throwable $e, string $prefix = ''): void
    {
        if ($prefix === '') {
            $prefix = (new \ReflectionClass($this))->getShortName();
        }
        
        try {
            $logger = $this->container->get(Logger::class);
            $logger->error("[{$prefix}] " . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $this->getUserContext()['id'] ?? 0,
                'url' => $this->request->getUri(),
            ]);
        } catch (\Throwable $logError) {
            error_log("[{$prefix}] " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
    }
}