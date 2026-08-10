<?php

declare(strict_types=1);

namespace W3a\Core\Http;

use W3a\Core\Foundation\Container;
use W3a\Core\Contracts\DatabaseInterface;
use W3a\Core\Exceptions\ValidationException;
use W3a\Core\Support\Validator;

/**
 * Базовый абстрактный класс для форм запросов.
 * 
 * ЯДРО НЕ ЗНАЕТ ПРО МОДУЛИ. Конкретные реализации FormRequest
 * создаются в модулях приложения и наследуют этот класс.
 * 
 * Пример использования в модуле:
 * 
 * class RegisterUserRequest extends FormRequest
 * {
 *     public function rules(): array
 *     {
 *         return [
 *             'email'    => 'required|email|max:255',
 *             'password' => 'required|min:8',
 *         ];
 *     }
 * }
 * 
 * В контроллере:
 * 
 * $validated = $this->validateForm(new RegisterUserRequest($this->request, $this->container));
 */
abstract class FormRequest
{
    protected Request $request;
    protected Container $container;
    protected array $validatedData = [];
    protected bool $isValidated = false;

    public function __construct(Request $request, Container $container)
    {
        $this->request = $request;
        $this->container = $container;
    }

    /**
     * Получить правила валидации.
     * 
     * @return array Ассоциативный массив правил валидации
     */
    abstract public function rules(): array;

    /**
     * Проверка авторизации пользователя.
     * 
     * Переопределите этот метод для проверки прав доступа.
     * Если вернёт false, будет выброшено исключение.
     * 
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Получить кастомные сообщения об ошибках валидации.
     * 
     * @return array
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * Получить список полей, разрешённых для массового заполнения.
     * 
     * По умолчанию возвращает ключи из rules().
     * Переопределите для кастомного списка.
     * 
     * @return array
     */
    public function fillable(): array
    {
        return array_keys($this->rules());
    }

    /**
     * Валидировать данные запроса.
     * 
     * @throws ValidationException
     * @throws \RuntimeException
     */
    public function validate(): void
    {
        // Проверка авторизации
        if (!$this->authorize()) {
            throw new \RuntimeException('Unauthorized action.');
        }

        // Получаем только разрешённые поля (защита от Mass Assignment)
        $data = $this->request->only($this->fillable());

        // Создаём валидатор через Container
        $validator = new Validator(
            $this->container->get(DatabaseInterface::class)
        );

        // Валидация
        if (!$validator->validate($data, $this->rules(), $this->messages())) {
            throw new ValidationException(
                'Validation failed',
                $validator->getErrors()
            );
        }

        $this->validatedData = $data;
        $this->isValidated = true;
    }

    /**
     * Получить отфильтрованные и валидированные данные.
     * 
     * @return array
     */
    public function validated(): array
    {
        if (!$this->isValidated) {
            $this->validate();
        }

        return $this->validatedData;
    }

    /**
     * Получить экземпляр Request.
     * 
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Получить конкретное поле из запроса.
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->request->input($key, $default);
    }
}