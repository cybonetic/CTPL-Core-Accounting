<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Resources;

use Ctpl\CoreAccounting\Support\IdempotencyKey;
use Ctpl\CoreAccounting\Support\Page;
use Ctpl\CoreAccounting\Values\Money;
use InvalidArgumentException;

/**
 * Money received from a customer.
 *
 * ---
 *
 * **A receipt has to say where the money landed.** `depositAccountCode` is
 * technically optional on the wire and practically mandatory: without it the
 * platform refuses with "A receipt must say where the money landed: a bank,
 * cash or gateway clearing account", because money that arrived nowhere is not
 * a receipt, it is a discrepancy waiting to be found at a bank reconciliation.
 *
 * `autoAllocate` applies it oldest invoice first, which is what most businesses
 * want and what the law assumes absent instructions. Pass explicit allocations
 * when the customer said which invoice they were paying - and they often do,
 * on the remittance advice.
 */
class Receipts extends Resource
{
    public const MODES = ['cash', 'bank', 'cheque', 'gateway', 'adjustment'];

    /**
     * @param  array<int, array{receivable_id:int, amount:Money|string}>  $allocations
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function record(
        int $customerId,
        Money|string|int $amount,
        IdempotencyKey|string $idempotencyKey,
        string $mode = 'bank',
        ?string $depositAccountCode = null,
        ?int $depositAccountId = null,
        ?string $reference = null,
        ?string $receiptDate = null,
        bool $autoAllocate = true,
        array $allocations = [],
        ?string $sourceSystem = null,
        ?string $sourceId = null,
        array $attributes = [],
    ): array {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a receipt mode. One of: %s.',
                $mode,
                implode(', ', self::MODES),
            ));
        }

        if ($depositAccountCode === null && $depositAccountId === null) {
            throw new InvalidArgumentException(
                'A receipt must say where the money landed - pass depositAccountCode (for example '
                .'"1121" for the current account) or depositAccountId. The platform refuses without '
                .'one, and refusing here saves the round trip.'
            );
        }

        $payload = array_merge($attributes, [
            'customer_id' => $customerId,
            'amount' => Money::of($amount)->value(),
            'mode' => $mode,
            'deposit_account_code' => $depositAccountCode,
            'deposit_account_id' => $depositAccountId,
            'reference' => $reference,
            'receipt_date' => $receiptDate,
            'source_system' => $sourceSystem,
            'source_id' => $sourceId,
        ]);

        if ($allocations !== []) {
            // Explicit allocations and auto-allocation are mutually exclusive:
            // sending both asks the platform to apply the money twice.
            $payload['allocations'] = array_map(static fn (array $a) => [
                'receivable_id' => $a['receivable_id'],
                'amount' => Money::of($a['amount'])->value(),
            ], array_values($allocations));
        } else {
            $payload['auto_allocate'] = $autoAllocate;
        }

        return $this->write('receipts', $payload, $idempotencyKey);
    }

    /** @return array<string,mixed> */
    public function find(int|string $receipt): array
    {
        return $this->one('receipts/'.rawurlencode((string) $receipt));
    }

    public function list(?int $customerId = null, int $perPage = 50, int $page = 1): Page
    {
        return $this->fetchPage('receipts', [
            'customer_id' => $customerId,
            'per_page' => $perPage,
            'page' => $page,
        ]);
    }

    /**
     * Apply an unallocated receipt - or part of one - to specific invoices.
     *
     * For the case where the money arrived before anybody knew what it was for,
     * which is most of them.
     *
     * @param  array<int, array{receivable_id:int, amount:Money|string}>  $allocations
     * @return array<string,mixed>
     */
    public function apply(int|string $receipt, array $allocations, IdempotencyKey|string $idempotencyKey): array
    {
        if ($allocations === []) {
            throw new InvalidArgumentException('Applying a receipt needs at least one allocation.');
        }

        return $this->write('receipts/'.rawurlencode((string) $receipt).'/apply', [
            'allocations' => array_map(static fn (array $a) => [
                'receivable_id' => $a['receivable_id'],
                'amount' => Money::of($a['amount'])->value(),
            ], array_values($allocations)),
        ], $idempotencyKey);
    }
}
