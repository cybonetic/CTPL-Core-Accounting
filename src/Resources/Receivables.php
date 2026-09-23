<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Resources;

use Ctpl\CoreAccounting\Support\Page;

/**
 * What is owed, and how overdue it is.
 *
 * Read-only. These are the numbers a selling application wants on its own
 * screens - a customer's balance before taking another order, an ageing table
 * for a collections view - without standing up a second integration to get them.
 */
class Receivables extends Resource
{
    /** Open items, optionally for one customer. */
    public function open(?int $customerId = null, int $perPage = 50, int $page = 1): Page
    {
        return $this->fetchPage('receivables', [
            'customer_id' => $customerId,
            'per_page' => $perPage,
            'page' => $page,
        ]);
    }

    /**
     * Ageing, bucketed from the due date.
     *
     * `asAt` defaults to today. Pass a date to see what the position looked
     * like at a month end - which is what a credit committee asks for, and what
     * recomputing from your own copy of the invoices would get subtly wrong.
     *
     * @return array<string,mixed>
     */
    public function ageing(?int $customerId = null, ?string $asAt = null): array
    {
        return $this->one('receivables/ageing', array_filter([
            'customer_id' => $customerId,
            'as_at' => $asAt,
        ], static fn ($v) => $v !== null));
    }

    /**
     * One customer's statement of account.
     *
     * @return array<string,mixed>
     */
    public function statement(int|string $customer, ?string $from = null, ?string $to = null): array
    {
        return $this->one(
            'receivables/statements/'.rawurlencode((string) $customer),
            array_filter(['from' => $from, 'to' => $to], static fn ($v) => $v !== null),
        );
    }
}
