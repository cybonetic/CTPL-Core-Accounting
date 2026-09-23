<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Support;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * One page of a listing, with the paging figures the platform returned.
 *
 * `meta` is passed through rather than interpreted, so a caller can page
 * without this class having to guess at a convention the platform may extend.
 *
 * @implements IteratorAggregate<int, array<string,mixed>>
 */
final class Page implements Countable, IteratorAggregate
{
    /**
     * @param  array<int, array<string,mixed>>  $items
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public readonly array $items,
        public readonly array $meta = [],
        public readonly ?string $requestId = null,
    ) {}

    /** @return array<int, array<string,mixed>> */
    public function all(): array
    {
        return $this->items;
    }

    public function first(): ?array
    {
        return $this->items[0] ?? null;
    }

    public function total(): ?int
    {
        return isset($this->meta['total']) ? (int) $this->meta['total'] : null;
    }

    public function currentPage(): int
    {
        return (int) ($this->meta['current_page'] ?? 1);
    }

    public function lastPage(): int
    {
        return (int) ($this->meta['last_page'] ?? 1);
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage() < $this->lastPage();
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
