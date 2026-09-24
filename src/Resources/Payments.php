<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Resources;

use Ctpl\CoreAccounting\Support\IdempotencyKey;
use InvalidArgumentException;

/**
 * Gateway payment ids attached to documents.
 *
 * ---
 *
 * **The usual path is not here.** When the customer has already paid by the
 * time you raise the invoice, send the id with it:
 *
 *     $ledger->invoices()->create(..., paymentId: 'pay_ABC123');
 *
 * That is one call instead of two, and the id lands inside the same
 * transaction as the invoice, so it cannot be lost while the invoice survives.
 *
 * This resource is for the case around it: **the customer paid afterwards**, on
 * a reminder or a payment link, days after the document was raised. Then there
 * is an invoice to attach to and no invoice call to attach it with.
 *
 * ---
 *
 * **What Core Accounting does with an id, so you know what not to duplicate.**
 *
 * It calls *your* payment-status endpoint - the one registered against this
 * application - with the id, stores whatever comes back, and posts the payment
 * into the gateway's clearing account if, and only if, the reply says the money
 * was captured. You do not need to send the amount, the method or the status:
 * sending them from two places is how they come to disagree.
 *
 * Nothing here settles an invoice by itself. A reply that says `authorized`
 * rather than `captured` records the fact and posts nothing, because the
 * gateway is holding a block on the card and has not taken the money.
 */
class Payments extends Resource
{
    public const DOCUMENT_TYPES = ['sales_invoice', 'credit_note', 'debit_note', 'purchase_invoice'];

    /**
     * Attach a gateway payment id to a document raised earlier.
     *
     * The same id twice is the same payment: the platform returns the record it
     * already holds rather than creating a second, so a retried webhook is
     * harmless. The same id against a *different* document is refused - one
     * payment belongs to one document, and if the customer paid for two the
     * gateway issued two ids.
     *
     * @return array<string,mixed>  the payment record, with whatever is known so far
     */
    public function attach(
        int $documentId,
        string $paymentId,
        IdempotencyKey|string $idempotencyKey,
        string $documentType = 'sales_invoice',
    ): array {
        $paymentId = trim($paymentId);

        if ($paymentId === '') {
            throw new InvalidArgumentException(
                'A payment needs the id your gateway issued. There is nothing for Core Accounting to '
                .'ask your endpoint about without it.'
            );
        }

        if (! in_array($documentType, self::DOCUMENT_TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a document that takes a payment. One of: %s.',
                $documentType,
                implode(', ', self::DOCUMENT_TYPES)
            ));
        }

        return $this->write('document-payments', [
            'document_type' => $documentType,
            'document_id' => $documentId,
            'payment_id' => $paymentId,
        ], $idempotencyKey);
    }

    /**
     * Ask again about a payment whose details could not be fetched.
     *
     * For when your own endpoint was down when the invoice was raised. The
     * record carries `lookup_state` and `lookup_error` saying what happened;
     * this retries it and, if the payment turns out to have been captured,
     * posts it into the subledger.
     *
     * Not idempotency-keyed, because it is a read on your side and a retry is
     * the whole point of it.
     *
     * @return array<string,mixed>
     */
    public function refresh(int $paymentRecordId): array
    {
        $envelope = $this->connection->act('document-payments/'.$paymentRecordId.'/refresh');

        return is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
    }

    /**
     * Every payment recorded against one document.
     *
     * @return array<int, array<string,mixed>>
     */
    public function forDocument(int $documentId, string $documentType = 'sales_invoice'): array
    {
        $envelope = $this->connection->get('document-payments', [
            'document_type' => $documentType,
            'document_id' => $documentId,
        ]);

        return is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
    }
}
