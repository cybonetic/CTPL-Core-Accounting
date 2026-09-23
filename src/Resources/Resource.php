<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Resources;

use Ctpl\CoreAccounting\Http\Connection;
use Ctpl\CoreAccounting\Support\IdempotencyKey;
use Ctpl\CoreAccounting\Support\Page;

/**
 * Shared plumbing for the resource classes.
 *
 * Each resource maps one group of endpoints and does nothing else - no caching,
 * no local model, no clever write-behind. The ledger is the record; this is a
 * client to it.
 */
abstract class Resource
{
    public function __construct(protected readonly Connection $connection) {}

    /**
     * A page of a listing.
     *
     * Named `fetchPage` rather than `list` so that each resource can expose a
     * `list()` of its own with the filters that endpoint actually supports - a
     * shared signature taking a path and an array would push every caller back
     * to guessing at query-string keys, which is the thing a typed SDK exists
     * to remove.
     *
     * @param array<string,mixed> $query
     */
    protected function fetchPage(string $path, array $query = []): Page
    {
        $envelope = $this->connection->get($path, array_filter(
            $query,
            static fn ($value) => $value !== null && $value !== ''
        ));

        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

        return new Page($data, $envelope['meta'], $envelope['request_id']);
    }

    /** @return array<string,mixed> */
    protected function one(string $path, array $query = []): array
    {
        $envelope = $this->connection->get($path, $query);

        return is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function write(string $path, array $payload, IdempotencyKey|string $key): array
    {
        $envelope = $this->connection->post(
            $path,
            $this->prune($payload),
            $key instanceof IdempotencyKey ? $key->value() : IdempotencyKey::of($key)->value(),
        );

        return is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
    }

    /**
     * Drop nulls before sending, but keep `false` and `"0"`.
     *
     * `array_filter` with no callback would remove both, and `reverse_charge`
     * set to false is a deliberate statement rather than an absent one - the
     * platform treats a missing field and an explicit false differently on
     * several endpoints.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function prune(array $payload): array
    {
        $pruned = [];

        foreach ($payload as $key => $value) {
            if ($value === null) {
                continue;
            }

            $pruned[$key] = is_array($value) ? $this->prune($value) : $value;
        }

        return $pruned;
    }
}
