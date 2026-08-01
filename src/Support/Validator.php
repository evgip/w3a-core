<?php

declare(strict_types=1);

namespace W3a\Core\Support;

use InvalidArgumentException;
use RuntimeException;
use W3a\Core\Contracts\DatabaseInterface;

class Validator
{
    protected array $errors = [];
    protected array $data = [];
    protected array $customMessages = [];
    protected ?DatabaseInterface $db;

    /**
     * Конструктор с инъекцией DatabaseInterface
     */
    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db;
    }

    /**
     * Валидация входных данных по заданным правилам
     *
     * @param array $data Данные для проверки
     * @param array $rules Правила в формате ['field' => 'required|email|min:5']
     * @param array $messages Кастомные сообщения в формате ['field.rule' => 'Текст ошибки']
     * @return bool true, если валидация прошла успешно
     */
    public function validate(array $data, array $rules, array $messages = []): bool
    {
        $this->data = $data;
        $this->errors = [];
        $this->customMessages = $messages;

        foreach ($rules as $field => $fieldRules) {
            // Безопасное получение значения (поддержка массивов и null)
            $rawValue = $data[$field] ?? null;
            $value = is_array($rawValue) ? $rawValue : trim((string)$rawValue);
            
            $rulesArray = explode('|', (string)$fieldRules);

            foreach ($rulesArray as $rule) {
                $colonPos = strpos($rule, ':');

                if ($colonPos !== false) {
                    $ruleName = substr($rule, 0, $colonPos);
                    $param = substr($rule, $colonPos + 1);
                } else {
                    $ruleName = $rule;
                    $param = null;
                }

                $this->checkRule($field, $value, $ruleName, $param);
            }
        }

        return empty($this->errors);
    }

    /**
     * Внутренняя проверка конкретного правила
     */
    protected function checkRule(string $field, mixed $value, string $ruleName, ?string $param = null): void
    {
        // Если значение пустое и правило не 'required', пропускаем проверку (значение необязательно)
        $isEmpty = ($value === '' || $value === null || (is_array($value) && empty($value)));
        if ($isEmpty && $ruleName !== 'required') {
            return;
        }

        $isValid = true;

        switch ($ruleName) {
            case 'required':
                $isValid = !$isEmpty;
                break;

            case 'email':
                $isValid = filter_var((string)$value, FILTER_VALIDATE_EMAIL) !== false;
                break;

            case 'min':
                $isValid = is_array($value) 
                    ? count($value) >= (int)$param 
                    : mb_strlen((string)$value) >= (int)$param;
                break;

            case 'max':
                $isValid = is_array($value) 
                    ? count($value) <= (int)$param 
                    : mb_strlen((string)$value) <= (int)$param;
                break;

            case 'match':
                $matchValue = $this->data[$param] ?? null;
                $isValid = ((string)$value === (string)$matchValue);
                break;

            case 'regex':
                $isValid = (bool)preg_match((string)$param, (string)$value);
                break;

            case 'unique':
                $isValid = $this->checkUnique($field, (string)$value, $param);
                break;
                
            case 'exists': // Бонус: обратное правило для unique
                $isValid = $this->checkExists($field, (string)$value, $param);
                break;

            case 'integer':
            case 'numeric':
                $isValid = is_numeric($value);
                break;

            default:
                throw new InvalidArgumentException("Неизвестное правило валидации: {$ruleName}");
        }

        if (!$isValid) {
            $this->addError($field, $ruleName, $param, $value);
        }
    }

    /**
     * Проверка правила unique (вынесено для чистоты кода)
     */
    protected function checkUnique(string $field, string $value, ?string $param): bool
    {
        if ($param === null) {
            return true;
        }

        $parts = explode(',', $param);
        $table = $parts[0];
        $column = $parts[1] ?? $field;

        // ✅ Защита от SQL-инъекций в именах таблиц/колонок
        if (
            !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) ||
            !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)
        ) {
            throw new InvalidArgumentException("Недопустимое имя таблицы или колонки в правиле unique: {$param}");
        }

        if ($this->db === null) {
            throw new RuntimeException("Database не внедрён в Validator для проверки правила unique");
        }

        $count = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :val LIMIT 1",
            ['val' => $value]
        );

        return $count === 0;
    }

    /**
     * Проверка правила exists (бонус)
     */
    protected function checkExists(string $field, string $value, ?string $param): bool
    {
        if ($param === null) return true;

        $parts = explode(',', $param);
        $table = $parts[0];
        $column = $parts[1] ?? $field;

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw new InvalidArgumentException("Недопустимое имя таблицы или колонки в правиле exists: {$param}");
        }

        if ($this->db === null) {
            throw new RuntimeException("Database не внедрён в Validator для проверки правила exists");
        }

        $count = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :val LIMIT 1",
            ['val' => $value]
        );

        return $count > 0;
    }

    /**
     * Добавление ошибки с поддержкой кастомных сообщений и плейсхолдеров
     */
    protected function addError(string $field, string $ruleName, ?string $param, mixed $value): void
    {
        $messageKey = "{$field}.{$ruleName}";
        
        // 1. Проверяем кастомное сообщение
        if (isset($this->customMessages[$messageKey])) {
            $message = $this->customMessages[$messageKey];
        } else {
            // 2. Иначе берем сообщение по умолчанию
            $message = $this->getDefaultMessage($field, $ruleName, $param, $value);
        }

        // 3. Заменяем плейсхолдеры в сообщении (например, :min, :value)
        $message = str_replace(
            [':field', ':param', ':value'],
            [$field, (string)($param ?? ''), (string)($value ?? '')],
            $message
        );

        $this->errors[$field][] = $message;
    }

    /**
     * Получение сообщения по умолчанию (Fallback)
     */
    protected function getDefaultMessage(string $field, string $ruleName, ?string $param, mixed $value): string
    {
        return match ($ruleName) {
            'required' => "Поле '{$field}' обязательно для заполнения.",
            'email' => "Поле '{$field}' должно быть корректным email-адресом.",
            'min' => "Поле '{$field}' должно быть не менее :param символов.",
            'max' => "Поле '{$field}' должно быть не более :param символов.",
            'match' => "Поле '{$field}' должно совпадать с полем '{$param}'.",
            'regex' => "Поле '{$field}' имеет недопустимый формат.",
            'unique' => "Такое значение ':value' уже существует в системе.",
            'exists' => "Такое значение ':value' не найдено в системе.",
            'integer', 'numeric' => "Поле '{$field}' должно быть числом.",
            default => "Поле '{$field}' не прошло валидацию.",
        };
    }

    /**
     * Проверка, прошла ли валидация успешно
     */
    public function isValid(): bool
    {
        return empty($this->errors);
    }

    /**
     * Получить все ошибки валидации
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Получить первую ошибку для конкретного поля (удобно для простого вывода)
     */
    public function getFirstError(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }
    
    /**
     * Получить все ошибки в виде плоского массива (удобно для JSON-ответа)
     */
    public function getFlatErrors(): array
    {
        $flat = [];
        foreach ($this->errors as $fieldErrors) {
            $flat = array_merge($flat, $fieldErrors);
        }
        return $flat;
    }
}