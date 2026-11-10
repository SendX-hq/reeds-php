<?php

declare(strict_types=1);

namespace Reeds\Core;

/**
 * One page of a list (cursor pagination: `limit`, `starting_after`, `has_more`).
 * Iterate it with `foreach` for every item across pages; the following pages
 * are fetched as you go.
 *
 *     $page = $reeds->shipping->deliveries->list(['limit' => 50]);
 *     foreach ($reeds->shipping->deliveries->list() as $delivery) { ... }
 *
 * @template T of array<array-key, mixed>
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \IteratorAggregate
{
    /**
     * @param list<T> $data This page's objects, newest first.
     * @param bool $hasMore True when another page follows.
     * @param \Closure(string): Page<T> $fetchAfter
     */
    public function __construct(
        public readonly array $data,
        public readonly bool $hasMore,
        private readonly \Closure $fetchAfter,
    ) {
    }

    /**
     * The next page, or null on the last one.
     *
     * @return Page<T>|null
     */
    public function nextPage(): ?Page
    {
        $last = $this->data === [] ? null : $this->data[array_key_last($this->data)];
        $id = is_array($last) && is_string($last['id'] ?? null) ? $last['id'] : null;
        if (!$this->hasMore || $id === null) {
            return null;
        }
        return ($this->fetchAfter)($id);
    }

    /**
     * Every object from this page on.
     *
     * @return \Generator<int, T>
     */
    public function getIterator(): \Generator
    {
        $page = $this;
        while ($page !== null) {
            foreach ($page->data as $item) {
                yield $item;
            }
            $page = $page->nextPage();
        }
    }
}
