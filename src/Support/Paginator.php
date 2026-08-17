<?php

declare(strict_types=1);

namespace W3a\Core\Support;

/**
 * Расчёт пагинации для списков.
 *
 * Считает total/lastPage/offset и выдаёт видимый диапазон номеров страниц.
 * Не занимается HTML-рендером — рендер остаётся во view приложения,
 * но данные (currentPage, lastPage, range, hasPrev/hasNext) общие для проектов.
 *
 * @example
 * $pager = new Paginator($total, 15, (int)$request->query('page', 1));
 * $items = $model->getList($limit, $pager->offset());
 * $this->render('list', ['items' => $items, 'pager' => $pager->toArray()]);
 */
class Paginator
{
    private int $total;
    private int $perPage;
    private int $currentPage;
    private int $lastPage;

    /**
     * @param int $total       Общее количество элементов
     * @param int $perPage     Элементов на страницу (> 0)
     * @param int $currentPage Текущая страница (1-based, меньше 1 -> 1)
     */
    public function __construct(int $total, int $perPage, int $currentPage = 1)
    {
        $this->perPage = max(1, $perPage);
        $this->total = max(0, $total);
        $this->lastPage = $this->total > 0 ? (int)ceil($this->total / $this->perPage) : 1;
        $this->currentPage = max(1, min($currentPage, $this->lastPage));
    }

    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function lastPage(): int
    {
        return $this->lastPage;
    }

    /**
     * Смещение для SQL (LIMIT/OFFSET).
     */
    public function offset(): int
    {
        return ($this->currentPage - 1) * $this->perPage;
    }

    public function hasPages(): bool
    {
        return $this->lastPage > 1;
    }

    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * Видимый диапазон номеров страниц вокруг текущей.
     *
     * @param int $delta Сколько страниц показывать слева и справа
     * @return int[] Номера страниц (например, [1, 2, 3, 4, 5])
     */
    public function range(int $delta = 2): array
    {
        $start = max(1, $this->currentPage - $delta);
        $end = min($this->lastPage, $this->currentPage + $delta);

        return $end >= $start ? range($start, $end) : [];
    }

    /**
     * Все данные для передачи в шаблон.
     *
     * @return array{currentPage:int,lastPage:int,perPage:int,total:int,offset:int,hasPrev:bool,hasNext:bool,range:int[]}
     */
    public function toArray(): array
    {
        return [
            'currentPage' => $this->currentPage,
            'lastPage'    => $this->lastPage,
            'perPage'     => $this->perPage,
            'total'       => $this->total,
            'offset'      => $this->offset(),
            'hasPrev'     => $this->currentPage > 1,
            'hasNext'     => $this->hasMorePages(),
            'range'       => $this->range(),
        ];
    }
}
