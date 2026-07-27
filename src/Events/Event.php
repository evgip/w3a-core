<?php

declare(strict_types=1);

namespace W3a\Core\Events;

abstract class Event
{
    abstract public function getName(): string;
    abstract public function getData(): array;

    /**
     * Категория события для фильтрации в журнале.
     * По умолчанию 'general'. Переопределяйте в наследниках.
     */
    public function getCategory(): string
    {
        return 'general';
    }
}