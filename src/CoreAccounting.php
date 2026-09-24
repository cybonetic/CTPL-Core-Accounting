<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting;

use Ctpl\CoreAccounting\Http\Connection;
use Ctpl\CoreAccounting\Resources\Customers;
use Ctpl\CoreAccounting\Resources\Invoices;
use Ctpl\CoreAccounting\Resources\Notes;
use Ctpl\CoreAccounting\Resources\Payments;
use Ctpl\CoreAccounting\Resources\Receipts;
use Ctpl\CoreAccounting\Resources\Receivables;

/**
 * The ledger, from your application's point of view.
 *
 *     $ledger = app(CoreAccounting::class);
 *
 *     $invoice = $ledger->invoices()->create(
 *         customerId: $customer['id'],
 *         lines: [InvoiceLine::make('Payroll, September', '25000.00', taxCodeId: 7, costCentreId: 1)],
 *         idempotencyKey: IdempotencyKey::for('hrms', $run->id),
 *         sourceSystem: 'hrms',
 *         sourceId: (string) $run->id,
 *     );
 *
 *     $invoice['document_number'];   // CTPL-INV-26-0001
 *     $invoice['grand_total'];       // "29500.0000" - a string, always
 *
 * ---
 *
 * **Store the number and the id against your own record**, and store the
 * idempotency key with them. Those three are what let you find the document
 * again, prove what was billed, and retry safely if anything is ever in doubt.
 */
class CoreAccounting
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * Act for a different company, keeping the same credentials.
     *
     * An application registered for several legal entities names the one it
     * means on every call; there is no default and the platform will not guess.
     * This returns a new instance rather than mutating, so a request handling
     * two companies cannot leak one into the other.
     */
    public function forCompany(int $companyId): self
    {
        return new self($this->connection->forCompany($companyId));
    }

    public function customers(): Customers
    {
        return new Customers($this->connection);
    }

    public function invoices(): Invoices
    {
        return new Invoices($this->connection);
    }

    public function notes(): Notes
    {
        return new Notes($this->connection);
    }

    /**
     * Gateway payment ids attached to documents raised earlier.
     *
     * When the customer has already paid, send the id with the invoice instead:
     * `invoices()->create(..., paymentId: 'pay_ABC123')` is one call rather
     * than two, and the id lands in the same transaction as the document.
     */
    public function payments(): Payments
    {
        return new Payments($this->connection);
    }

    public function receipts(): Receipts
    {
        return new Receipts($this->connection);
    }

    public function receivables(): Receivables
    {
        return new Receivables($this->connection);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }
}
