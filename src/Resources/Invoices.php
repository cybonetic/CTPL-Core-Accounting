<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Resources;

use Ctpl\CoreAccounting\Exceptions\ImmutableDocument;
use Ctpl\CoreAccounting\Support\IdempotencyKey;
use Ctpl\CoreAccounting\Support\Page;
use Ctpl\CoreAccounting\Values\InvoiceLine;
use InvalidArgumentException;

/**
 * Sales invoices.
 *
 * ---
 *
 * **One call creates and posts.** There is no separate "post" step to forget:
 * a successful `create()` returns a document whose `status` is `posted` and
 * whose `journal_entry_id` is set, which means the general ledger already has
 * it. That is why the idempotency key matters so much here - a duplicate is not
 * a duplicate row, it is a second statutory number in the ledger.
 *
 * **And there is no update.** See `ImmutableDocument`, and `update()` below,
 * which exists only to say so.
 */
class Invoices extends Resource
{
    /**
     * Raise and post an invoice.
     *
     * @param  array<int, InvoiceLine|array<string,mixed>>  $lines
     * @param  array<string,mixed>  $attributes  anything else the endpoint accepts
     * @return array<string,mixed>  the posted invoice, with its number and journal entry
     */
    public function create(
        int $customerId,
        array $lines,
        IdempotencyKey|string $idempotencyKey,
        ?string $invoiceDate = null,
        ?string $placeOfSupply = null,
        ?string $sourceSystem = null,
        ?string $sourceId = null,
        array $attributes = [],
        /**
         * Whether the unit prices on this invoice already contain the tax.
         *
         * A document-wide answer; a line can override it either way with
         * `InvoiceLine::withPriceIncludingTax()`. Left null, each line follows
         * its tax code, which is the behaviour every caller had before this
         * parameter existed.
         *
         * Last in the signature, after `$attributes`, so that adding it cannot
         * shift a positional argument in code already calling this.
         */
        ?bool $pricesIncludeTax = null,
        /**
         * The gateway payment id, if the customer has already paid.
         *
         * **The only thing your application needs to know about the payment.**
         * Send the id your gateway gave you - `pay_...` from Razorpay, the
         * order id from Cashfree, PayU or PhonePe - and Core Accounting calls
         * your own payment-status endpoint afterwards for the rest: the amount,
         * the method, the bank reference, whether it actually succeeded.
         *
         * It is attached inside the transaction that raises the invoice, so the
         * reference cannot be lost while the invoice survives. The lookup runs
         * after that transaction commits and **cannot fail the invoice**: an
         * endpoint that is down leaves a row to retry, not a refused document.
         *
         * Omit it and nothing happens - no endpoint is called and no payment is
         * recorded. Payment arriving later goes through
         * `$ledger->payments()->attach()`.
         *
         * Last in the signature, like `$pricesIncludeTax`, so adding it cannot
         * shift a positional argument in code already written.
         */
        ?string $paymentId = null,
    ): array {
        if ($lines === []) {
            throw new InvalidArgumentException('An invoice needs at least one line.');
        }

        $payload = array_merge($attributes, [
            'customer_id' => $customerId,
            'invoice_date' => $invoiceDate,
            'place_of_supply' => $placeOfSupply,
            'source_system' => $sourceSystem,
            'source_id' => $sourceId,
            'prices_include_tax' => $pricesIncludeTax,
            'payment_id' => $paymentId,
            'lines' => array_map(
                static fn ($line) => $line instanceof InvoiceLine ? $line->toArray() : $line,
                array_values($lines),
            ),
        ]);

        return $this->write('invoices', $payload, $idempotencyKey);
    }

    /**
     * One invoice, by the id the create returned.
     *
     * @return array<string,mixed>
     */
    public function find(int|string $invoice): array
    {
        return $this->one('invoices/'.rawurlencode((string) $invoice));
    }

    /**
     * A page of invoices.
     *
     * `sourceSystem` is the filter worth knowing about: pass your own system's
     * name and you get back only the invoices your application raised, which is
     * what a "my billing history" screen actually wants.
     */
    public function list(
        ?int $customerId = null,
        ?string $status = null,
        ?string $from = null,
        ?string $to = null,
        ?string $sourceSystem = null,
        int $perPage = 50,
        int $page = 1,
    ): Page {
        return $this->fetchPage('invoices', [
            'customer_id' => $customerId,
            'status' => $status,
            'from' => $from,
            'to' => $to,
            'source_system' => $sourceSystem,
            'per_page' => $perPage,
            'page' => $page,
        ]);
    }

    /**
     * Cancel an invoice: reverse its journal entry and void its number.
     *
     * **Only while it is honest to say the invoice never happened.** Once the
     * customer has a copy, or the period has closed, the answer is a credit note
     * instead - cancelling then leaves them holding a document your ledger
     * denies, which is a conversation nobody wants to have with an auditor.
     *
     * The reason is required and is stored. Write it for whoever reads it in a
     * year, not for the log.
     *
     * @return array<string,mixed>
     */
    public function cancel(
        int|string $invoice,
        string $reason,
        IdempotencyKey|string $idempotencyKey,
        ?string $cancellationDate = null,
    ): array {
        if (strlen(trim($reason)) < 5) {
            throw new InvalidArgumentException(
                'Cancelling an invoice requires a written reason of at least five characters. It is '
                .'kept with the document and is what explains the gap to whoever finds it later.'
            );
        }

        return $this->write(
            'invoices/'.rawurlencode((string) $invoice).'/cancel',
            ['reason' => trim($reason), 'cancellation_date' => $cancellationDate],
            $idempotencyKey,
        );
    }

    /**
     * There is no update. This method exists to explain why, and what to do.
     *
     * It is deliberately not absent: "call to undefined method" teaches nothing,
     * and this is the exact moment an integrator is paying attention. Everyone
     * arrives at a document API with a CRUD shape in mind and reaches for this.
     *
     * @throws ImmutableDocument always
     */
    public function update(int|string $invoice, array $attributes = []): never
    {
        throw ImmutableDocument::cannotUpdate('invoice');
    }
}
