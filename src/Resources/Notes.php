<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Resources;

use Ctpl\CoreAccounting\Support\IdempotencyKey;
use Ctpl\CoreAccounting\Support\Page;
use Ctpl\CoreAccounting\Values\InvoiceLine;
use InvalidArgumentException;

/**
 * Credit and debit notes - the way a posted invoice is adjusted.
 *
 * ---
 *
 * **A credit note is not an undo.** It is a second document that says what
 * changed and why, leaving the first one standing. Both appear on the GST
 * return, both are in the ledger, and an auditor can see the whole story. That
 * is the difference between correcting a mistake and hiding one.
 *
 * The reason code is not decoration. `sales_return`, `post_sale_discount` and
 * `rate_difference` are reported differently, and the tax treatment follows the
 * reason rather than the amount.
 *
 * **There is a time limit.** Section 34(2) of the CGST Act bars the tax
 * adjustment on a credit note issued after the November return following the
 * financial year, or the annual return, whichever is earlier. The platform
 * knows; `outsideTaxWindow()` asks it before you promise a customer anything.
 */
class Notes extends Resource
{
    public const REASONS = [
        'sales_return', 'deficiency', 'price_revision_down', 'price_revision_up',
        'post_sale_discount', 'rate_difference', 'short_supply', 'additional_charges', 'other',
    ];

    /**
     * A credit note against a customer - money back, or billed too much.
     *
     * @param  array<int, InvoiceLine|array<string,mixed>>  $lines
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function credit(
        int $customerId,
        array $lines,
        string $reasonCode,
        IdempotencyKey|string $idempotencyKey,
        int|string|null $againstInvoiceId = null,
        ?string $reason = null,
        ?string $noteDate = null,
        ?string $placeOfSupply = null,
        ?string $sourceSystem = null,
        ?string $sourceId = null,
        array $attributes = [],
    ): array {
        return $this->note('credit', 'sales', $customerId, $lines, $reasonCode, $idempotencyKey,
            $againstInvoiceId, $reason, $noteDate, $placeOfSupply, $sourceSystem, $sourceId, $attributes);
    }

    /**
     * A debit note against a customer - billed too little.
     *
     * @param  array<int, InvoiceLine|array<string,mixed>>  $lines
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function debit(
        int $customerId,
        array $lines,
        string $reasonCode,
        IdempotencyKey|string $idempotencyKey,
        int|string|null $againstInvoiceId = null,
        ?string $reason = null,
        ?string $noteDate = null,
        ?string $placeOfSupply = null,
        ?string $sourceSystem = null,
        ?string $sourceId = null,
        array $attributes = [],
    ): array {
        return $this->note('debit', 'sales', $customerId, $lines, $reasonCode, $idempotencyKey,
            $againstInvoiceId, $reason, $noteDate, $placeOfSupply, $sourceSystem, $sourceId, $attributes);
    }

    /** @return array<string,mixed> */
    public function find(int|string $note): array
    {
        return $this->one('notes/'.rawurlencode((string) $note));
    }

    public function list(?string $noteType = null, ?string $side = 'sales', int $perPage = 50, int $page = 1): Page
    {
        return $this->fetchPage('notes', [
            'note_type' => $noteType,
            'side' => $side,
            'per_page' => $perPage,
            'page' => $page,
        ]);
    }

    /**
     * How much is still creditable against an invoice.
     *
     * Ask before promising a refund. Credit notes against one invoice cannot
     * exceed it in total, and finding that out by being refused - after telling
     * the customer - is the wrong order.
     *
     * @return array<string,mixed>
     */
    public function headroom(int|string $invoiceId): array
    {
        return $this->one('notes/headroom', ['original_document_id' => $invoiceId]);
    }

    /**
     * Notes whose tax adjustment is out of time under section 34(2).
     *
     * The note can still be raised - the commercial adjustment is real - but the
     * tax on it cannot be reclaimed. Worth knowing before, not after.
     *
     * @return array<string,mixed>
     */
    public function outsideTaxWindow(): Page
    {
        return $this->fetchPage('notes/outside-tax-window');
    }

    /** @return array<string,mixed> */
    public function cancel(int|string $note, string $reason, IdempotencyKey|string $idempotencyKey): array
    {
        return $this->write(
            'notes/'.rawurlencode((string) $note).'/cancel',
            ['reason' => $reason],
            $idempotencyKey,
        );
    }

    /**
     * @param  array<int, InvoiceLine|array<string,mixed>>  $lines
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    protected function note(
        string $noteType,
        string $side,
        int $partyId,
        array $lines,
        string $reasonCode,
        IdempotencyKey|string $idempotencyKey,
        int|string|null $againstInvoiceId,
        ?string $reason,
        ?string $noteDate,
        ?string $placeOfSupply,
        ?string $sourceSystem,
        ?string $sourceId,
        array $attributes,
    ): array {
        if ($lines === []) {
            throw new InvalidArgumentException('A note needs at least one line.');
        }

        if (! in_array($reasonCode, self::REASONS, true)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a reason this platform reports. It must be one of: %s. The reason drives '
                .'the tax treatment, so it is not free text - the free text is the separate `reason` '
                .'argument.',
                $reasonCode,
                implode(', ', self::REASONS),
            ));
        }

        return $this->write('notes', array_merge($attributes, [
            'note_type' => $noteType,
            'side' => $side,
            'customer_id' => $partyId,
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'original_document_id' => $againstInvoiceId,
            'note_date' => $noteDate,
            'place_of_supply' => $placeOfSupply,
            'source_system' => $sourceSystem,
            'source_id' => $sourceId,
            'lines' => array_map(
                static fn ($line) => $line instanceof InvoiceLine ? $line->toArray() : $line,
                array_values($lines),
            ),
        ]), $idempotencyKey);
    }
}
