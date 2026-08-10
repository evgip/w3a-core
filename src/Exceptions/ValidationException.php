<?php

declare(strict_types=1);

namespace W3a\Core\Exceptions;

/**
 * Исключение для ошибок валидации.
 * 
 * Полностью совместимо с предыдущей версией:
 * - new ValidationException('message') — работает как раньше
 * - new ValidationException('message', ['field' => ['error']]) — новая возможность
 */
class ValidationException extends \Exception
{
    /**
     * @var array Структурированный список ошибок валидации
     */
    protected array $errors;

    /**
     * @param string          $message  Сообщение об ошибке
     * @param array           $errors   Структурированный список ошибок валидации
     * @param int             $code     HTTP-код (по умолчанию 422 - Unprocessable Entity)
     * @param \Throwable|null $previous Предыдущее исключение
     */
    public function __construct(
        string $message = 'Validation failed',
        array $errors = [],
        int $code = 422,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    /**
     * Получить список ошибок валидации.
     * 
     * @return array Ассоциативный массив ошибок вида ['field' => ['error1', 'error2']]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Проверить, есть ли структурированные ошибки.
     * 
     * @return bool
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Получить первую ошибку для указанного поля.
     * 
     * @param string $field Имя поля
     * @return string|null Сообщение об ошибке или null, если ошибки нет
     */
    public function getFirstError(string $field): ?string
    {
        if (!isset($this->errors[$field])) {
            return null;
        }
        
        $fieldErrors = $this->errors[$field];
        
        return is_array($fieldErrors) ? ($fieldErrors[0] ?? null) : $fieldErrors;
    }

    /**
     * Проверить наличие ошибки для указанного поля.
     * 
     * @param string $field Имя поля
     * @return bool
     */
    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]) && !empty($this->errors[$field]);
    }
}