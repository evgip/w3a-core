<?php

declare(strict_types=1);

namespace W3a\Core\Auth;

use W3a\Core\Auth\Models\RememberToken;
use W3a\Core\Auth\Models\EmailActivation;
use W3a\Core\Auth\Exceptions\InvalidCredentialsException;
use W3a\Core\Auth\Exceptions\AccountNotActiveException;
use W3a\Core\Auth\Exceptions\RegistrationFailedException;
use W3a\Core\Auth\Exceptions\InvalidTokenException;
use W3a\Core\Support\Logger;
use W3a\Core\Support\Audit;
use W3a\Core\Foundation\Config;
use W3a\Core\Http\Request;
use W3a\Core\Http\Session;

/**
 * Базовый сервис аутентификации (ядро).
 * 
 * Отвечает за универсальную бизнес-логику:
 * - Вход/выход с защитой от timing-атак
 * - Регистрация с активацией по email
 * - Функция "Запомнить меня" (selector + hashed_validator)
 * 
 * Приложение расширяет этот класс (AppAuthService), переопределяя
 * защищённые методы для своей специфики (письма, баны, аватары).
 */
class AuthService
{
    protected object $userModel;
    protected RememberToken $rememberTokenModel;
    protected EmailActivation $emailActivationModel;
    protected Logger $logger;
    protected Session $session;
    protected Audit $audit;
    protected Config $config;
    protected Request $request;

    // Хэш-заглушка для защиты от timing-атак при несуществующем пользователе
    protected const DUMMY_HASH = '$2y$10$DummyHashForTimingProtection00000000000000000000';

    protected const COOKIE_NAME = 'remember_me';
    protected const COOKIE_DAYS = 30;

    public function __construct(
        object $userModel,
        RememberToken $rememberTokenModel,
        EmailActivation $emailActivationModel,
        Logger $logger,
        Session $session,
        Audit $audit,
        Config $config,
        Request $request
    ) {
        $this->userModel            = $userModel;
        $this->rememberTokenModel   = $rememberTokenModel;
        $this->emailActivationModel = $emailActivationModel;
        $this->logger               = $logger;
        $this->session              = $session;
        $this->audit                = $audit;
        $this->config               = $config;
        $this->request              = $request;
    }

    // ═══════════════════════════════════════════════════════════
    //  АУТЕНТИФИКАЦИЯ
    // ═══════════════════════════════════════════════════════════

    /**
     * Аутентификация пользователя по email и паролю.
     *
     * @throws InvalidCredentialsException Если email или пароль неверны
     * @throws AccountNotActiveException   Если аккаунт не активирован или забанен
     */
    public function authenticate(string $email, string $password): array
    {
        $user = $this->userModel->findBy('email', $email);

        // Защита от timing-атак: если пользователя нет, всё равно хешируем dummy
        if (!$user || !password_verify($password, $user['password'])) {
            if (!$user) {
                password_verify($password, self::DUMMY_HASH);
            }
            throw new InvalidCredentialsException('Неверный email или пароль.');
        }

        // Проверка активации аккаунта
        if ((int)$user['is_active'] !== 1) {
            throw new AccountNotActiveException('Аккаунт не активирован. Проверьте вашу почту.');
        }

        // Проверка бана (метод переопределяется в приложении)
        if ($this->isUserBanned((int)$user['id'])) {
            throw new AccountNotActiveException('Ваш аккаунт заблокирован администрацией.');
        }

        return $user;
    }

    // ═══════════════════════════════════════════════════════════
    //  РЕГИСТРАЦИЯ И АКТИВАЦИЯ
    // ═══════════════════════════════════════════════════════════

    /**
     * Регистрация нового пользователя и отправка письма активации.
     *
     * @throws RegistrationFailedException Если не удалось создать пользователя
     */
    public function register(string $username, string $email, string $password): int
    {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $userId = $this->userModel->create([
            'username'  => $username,
            'email'     => $email,
            'password'  => $hashedPassword,
            'is_active' => 0,
            'role'      => 'user',
        ]);

        if (!$userId) {
            throw new RegistrationFailedException('Не удалось создать пользователя в базе данных.');
        }

        // Генерируем токен активации и отправляем письмо
        $token = bin2hex(random_bytes(32));
        $this->emailActivationModel->createToken($userId, $token);
        $this->sendActivationEmail($email, $username, $token);

        return $userId;
    }

    /**
     * Активация аккаунта по токену из письма.
     *
     * @throws InvalidTokenException Если токен недействителен или истёк
     */
    public function activateAccount(string $token): bool
    {
        if (empty($token)) {
            throw new InvalidTokenException('Недействительная ссылка активации.');
        }

        $tokenData = $this->emailActivationModel->findByToken($token);

        if (!$tokenData) {
            throw new InvalidTokenException('Ссылка активации не найдена или уже использована.');
        }

        // Токен живёт 24 часа
        $createdAt = strtotime($tokenData['created_at']);
        if ((time() - $createdAt) > 86400) {
            $this->emailActivationModel->deleteByToken($token);
            throw new InvalidTokenException('Срок действия ссылки активации истёк.');
        }

        $user = $this->userModel->find((int)$tokenData['user_id']);

        if (!$user) {
            $this->emailActivationModel->deleteByToken($token);
            throw new InvalidTokenException('Пользователь не найден.');
        }

        $success = $this->userModel->update((int)$user['id'], ['is_active' => 1]);

        if ($success) {
            $this->emailActivationModel->deleteByToken($token);
            $this->audit->log('auth.account_activated', 'Аккаунт активирован', 'auth', [
                'user_id' => $user['id'],
                'email'   => $user['email'],
            ]);
        }

        return $success;
    }

    // ═══════════════════════════════════════════════════════════
    //  СЕССИЯ И ВЫХОД
    // ═══════════════════════════════════════════════════════════

    /**
     * Создание сессии после успешного входа.
     * Регенерирует ID сессии для защиты от Session Fixation.
     */
    public function createSession(array $user, bool $remember = false): void
    {
        $this->session->regenerate(true);

        $this->session->set('user_id',   $user['id']);
        $this->session->set('user_name', $user['username'] ?? $user['name']);
        $this->session->set('user_role', $user['role'] ?? 'user');
        $this->session->set('last_activity_time', time());

        if ($remember) {
            $this->createRememberCookie($user['id']);
        }

        $this->audit->log('auth.login_success', 'Пользователь вошел в систему', 'auth');
    }

    /**
     * Корректное завершение сессии с сохранением flash-сообщений.
     */
    public function logout(): void
    {
        $this->clearRememberCookie();

        if ($this->session->has('user_id')) {
            try {
                $this->audit->log('auth.logout', 'Пользователь вышел из системы', 'auth');
            } catch (\Throwable $e) {
                // Игнорируем ошибки логирования при выходе
            }
        }

        // Сохраняем flash-данные перед уничтожением сессии
        $flashData = $this->session->get('flash');

        $this->session->clear();
        $this->session->destroy();
        $this->session->start();

        if ($flashData) {
            $this->session->set('flash', $flashData);
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  "ЗАПОМНИТЬ МЕНЯ"
    // ═══════════════════════════════════════════════════════════

    /**
     * Попытка восстановления сессии по cookie "Запомнить меня".
     */
    public function attemptRememberLogin(): bool
    {
        if (!$this->request->hasCookie(self::COOKIE_NAME)) {
            return false;
        }

        $token = $this->request->cookie(self::COOKIE_NAME);
        $parts = explode(':', $token, 2);

        if (count($parts) !== 2) {
            $this->clearRememberCookie();
            return false;
        }

        [$selector, $validator] = $parts;
        $record = $this->rememberTokenModel->validateToken($selector, $validator);

        if (!$record) {
            $this->clearRememberCookie();
            return false;
        }

        $user = $this->userModel->find((int)$record['user_id']);

        // Забаненные пользователи не могут войти даже по токену
        if (!$user || $this->isUserBanned((int)$user['id'])) {
            $this->clearRememberCookie();
            return false;
        }

        $this->createSession($user, false);
        $this->createRememberCookie($user['id']); // Ротация токена

        $this->audit->log('auth.remember_success', 'Восстановление сессии по токену', 'auth');
        return true;
    }

    // ═══════════════════════════════════════════════════════════
    //  ЗАЩИЩЁННЫЕ МЕТОДЫ (переопределяются в приложении)
    // ═══════════════════════════════════════════════════════════

    /**
     * Проверка бана пользователя.
     * По умолчанию — нет. Приложение переопределяет через свою модель User.
     */
    protected function isUserBanned(int $userId): bool
    {
        if (method_exists($this->userModel, 'isBanned')) {
            return $this->userModel->isBanned($userId);
        }
        return false;
    }

    /**
     * Отправка письма активации.
     * Пустой метод — приложение переопределяет через свой Mailer.
     */
    protected function sendActivationEmail(string $email, string $username, string $token): void
    {
        // Переопределяется в AppAuthService
    }

    /**
     * Создание cookie "Запомнить меня" (selector + hashed_validator).
     */
    protected function createRememberCookie(int $userId): void
    {
        $tokenData = $this->rememberTokenModel->createToken(
            $userId,
            self::COOKIE_DAYS,
            $this->request->header('HTTP_USER_AGENT', 'Unknown'),
            $this->request->getIp()
        );

        setcookie(self::COOKIE_NAME, $tokenData['token'], [
            'expires'  => time() + (self::COOKIE_DAYS * 86400),
            'path'     => '/',
            'domain'   => '',
            'secure'   => $this->request->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Удаление токена из БД и очистка cookie.
     */
    protected function clearRememberCookie(): void
    {
        if ($this->request->hasCookie(self::COOKIE_NAME)) {
            $token = $this->request->cookie(self::COOKIE_NAME);
            $parts = explode(':', $token, 2);

            if (count($parts) === 2) {
                $this->rememberTokenModel->deleteBySelector($parts[0]);
            }

            setcookie(self::COOKIE_NAME, '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $this->request->isSecure(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}