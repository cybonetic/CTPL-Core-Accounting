<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Resources;

use Ctpl\CoreAccounting\Support\IdempotencyKey;
use Ctpl\CoreAccounting\Support\Page;

/**
 * Customers.
 *
 * The one resource here that genuinely can be updated: a customer is a master
 * record, not a posted document, so correcting an address or a credit period
 * changes nothing that has already hit the ledger.
 */
class Customers extends Resource
{
    /**
     * Create a customer.
     *
     * `registration` decides how tax is computed and is not cosmetic - one of
     * registered, unregistered, composition, sez, overseas, uin. A GSTIN is
     * checked for its check digit AND for agreeing with the place of supply,
     * so a mismatch is refused here rather than surfacing on the first invoice.
     *
     * Pass `sourceSystem` and `sourceId` - your own identifier for this party.
     * It is what lets you find them again without storing the ledger's id, and
     * it is what makes this create safe to repeat.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function create(
        string $code,
        string $name,
        IdempotencyKey|string $idempotencyKey,
        string $registration = 'registered',
        ?string $gstin = null,
        ?string $placeOfSupplyState = null,
        ?string $email = null,
        ?int $creditDays = null,
        ?string $sourceSystem = null,
        ?string $sourceId = null,
        array $attributes = [],
    ): array {
        return $this->write('customers', array_merge($attributes, [
            'code' => $code,
            'name' => $name,
            'registration' => $registration,
            'gstin' => $gstin,
            'place_of_supply_state' => $placeOfSupplyState,
            'email' => $email,
            'credit_days' => $creditDays,
            'source_system' => $sourceSystem,
            'source_id' => $sourceId,
        ]), $idempotencyKey);
    }

    /** @return array<string,mixed> */
    public function find(int|string $customer): array
    {
        return $this->one('customers/'.rawurlencode((string) $customer));
    }

    public function list(?string $search = null, int $perPage = 50, int $page = 1): Page
    {
        return $this->fetchPage('customers', [
            'search' => $search,
            'per_page' => $perPage,
            'page' => $page,
        ]);
    }

    /**
     * Correct a customer's details.
     *
     * A master record, so this is a real update - unlike an invoice. What it
     * does NOT do is reach back into documents already raised: an invoice
     * carries the address and the GSTIN as they stood when it was posted,
     * because that is what the customer was sent and what the return reported.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function update(int|string $customer, array $attributes, IdempotencyKey|string $idempotencyKey): array
    {
        $envelope = $this->connection->patch(
            'customers/'.rawurlencode((string) $customer),
            $this->prune($attributes),
            $idempotencyKey instanceof IdempotencyKey
                ? $idempotencyKey->value()
                : IdempotencyKey::of($idempotencyKey)->value(),
        );

        return is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
    }
}
