<?php

declare(strict_types=1);

namespace W3a\Core\Support;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Легковесная реализация коллекции (вдохновлена Laravel).
 * Позволяет работать с массивами данных через fluent-интерфейс.
 * 
 * @template TKey of array-key
 * @template TValue
 * @implements ArrayAccess<TKey, TValue>
 * @implements IteratorAggregate<TKey, TValue>
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate
{
    /**
     * @var array<TKey, TValue>
     */
    protected array $items;

    /**
     * @param iterable<TKey, TValue> $items
     */
    public function __construct(iterable $items = [])
    {
        $this->items = $this->getArrayableItems($items);
    }

    /**
     * Создать новую коллекцию.
     */
    public static function make(iterable $items = []): static
    {
        return new static($items);
        }

    /**
     * Получить все элементы в виде массива.
     *
     * @return array<TKey, TValue>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Алиас для all().
     */
    public function toArray(): array
    {
        return $this->all();
    }

    // =========================================================================
    // ТРАНСФОРМАЦИЯ (Возвращают новую коллекцию - иммутабельность)
    // =========================================================================

    /**
     * Применить callback к каждому элементу.
     */
    public function map(callable $callback): static
    {
        $keys = array_keys($this->items);
        $items = array_map($callback, $this->items, $keys);

        return new static(array_combine($keys, $items));
    }

    /**
     * Оставить только элементы, проходящие проверку.
     */
    public function filter(?callable $callback = null): static
    {
        if ($callback) {
            return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
        }

        return new static(array_filter($this->items));
    }

    /**
     * Обратная операция filter (отбросить элементы, проходящие проверку).
     */
    public function reject(callable $callback): static
    {
        return $this->filter(fn ($value, $key) => !$callback($value, $key));
    }

    /**
     * Извлечь значения одного ключа из всех элементов.
     */
    public function pluck(string $value, ?string $key = null): static
    {
        $results = [];

        foreach ($this->items as $item) {
            $itemValue = is_object($item) ? $item->$value : ($item[$value] ?? null);

            if (is_null($key)) {
                $results[] = $itemValue;
            } else {
                $itemKey = is_object($item) ? $item->$key : ($item[$key] ?? null);
                $results[$itemKey] = $itemValue;
            }
        }

        return new static($results);
    }

    /**
     * Сбросить ключи массива (сделать их последовательными 0, 1, 2...).
     */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /**
     * Оставить только уникальные значения.
     */
    public function unique(?string $key = null): static
    {
        if (is_null($key)) {
            return new static(array_unique($this->items, SORT_REGULAR));
        }

        $exists = [];
        $results = [];

        foreach ($this->items as $itemKey => $item) {
            $value = is_object($item) ? $item->$key : ($item[$key] ?? null);
            
            if (!in_array($value, $exists, true)) {
                $exists[] = $value;
                $results[$itemKey] = $item;
            }
        }

        return new static($results);
    }

    // =========================================================================
    // ГРУППИРОВКА И СОРТИРОВКА
    // =========================================================================

    /**
     * Сгруппировать элементы по ключу.
     */
    public function groupBy(string $key): static
    {
        $results = [];

        foreach ($this->items as $item) {
            $groupKey = is_object($item) ? $item->$key : ($item[$key] ?? 'null');
            $results[$groupKey][] = $item;
        }

        return new static($results);
    }

    /**
     * Отсортировать элементы.
     */
    public function sort(?callable $callback = null): static
    {
        $items = $this->items;

        if ($callback) {
            uasort($items, $callback);
        } else {
            asort($items, SORT_REGULAR);
        }

        return new static($items);
    }

    /**
     * Отсортировать по ключам массива.
     */
    public function sortKeys(): static
    {
        $items = $this->items;
        ksort($items, SORT_REGULAR);
        return new static($items);
    }

    // =========================================================================
    // ПОИСК И ПРОВЕРКИ
    // =========================================================================

    /**
     * Получить первый элемент, удовлетворяющий условию.
     */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if (is_null($callback)) {
            return empty($this->items) ? value($default) : reset($this->items);
        }

        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return value($default);
    }

    /**
     * Получить первый элемент, где ключ равен значению.
     */
    public function firstWhere(string $key, mixed $value): mixed
    {
        return $this->first(fn ($item) => (is_object($item) ? $item->$key : ($item[$key] ?? null)) === $value);
    }

    /**
     * Проверить, что все элементы удовлетворяют условию.
     */
    public function every(callable $callback): bool
    {
        foreach ($this->items as $key => $item) {
            if (!$callback($item, $key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Проверить, содержит ли коллекция данный элемент.
     */
    public function contains(mixed $value): bool
    {
        if (is_callable($value)) {
            return !is_null($this->first($value));
        }

        return in_array($value, $this->items, true);
    }

    // =========================================================================
    // УТИЛИТЫ
    // =========================================================================

    /**
     * Разбить коллекцию на части заданного размера.
     */
    public function chunk(int $size): static
    {
        if ($size <= 0) {
            return new static();
        }

        $chunks = [];
        foreach (array_chunk($this->items, $size, true) as $chunk) {
            $chunks[] = new static($chunk);
        }

        return new static($chunks);
    }

    // =========================================================================
    // РЕАЛИЗАЦИЯ ИНТЕРФЕЙСОВ PHP
    // =========================================================================

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    /**
     * Преобразовать переданные данные в массив.
     */
    protected function getArrayableItems(iterable $items): array
    {
        if (is_array($items)) {
            return $items;
        }

        if ($items instanceof self) {
            return $items->all();
        }

        return iterator_to_array($items);
    }
}

/**
 * Вспомогательная функция для вычисления значения.
 */
if (!function_exists('value')) {
    function value(mixed $value, mixed ...$args): mixed
    {
        return $value instanceof \Closure ? $value(...$args) : $value;
    }
}