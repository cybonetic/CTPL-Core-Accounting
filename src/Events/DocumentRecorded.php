<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A write that had been deferred finally reached the ledger.
 *
 * ---
 *
 * **This is how the loop closes without anybody polling.**
 *
 * When the ledger was unreachable, your request was told so and the write went
 * to a queue. There was no document number to give you at the time. This event
 * carries it when it exists, along with the idempotency key you supplied - which
 * is what lets you find your own record again:
 *
 *     Event::listen(DocumentRecorded::class, function (DocumentRecorded $e) {
 *         PayrollRun::where('ledger_key', $e->idempotencyKey)->update([
 *             'invoice_id' => $e->document['id'],
 *             'invoice_number' => $e->document['document_number'],
 *         ]);
 *     });
 *
 * Store the key when you make the call, not only when it fails. A deferred
 * write is the uncommon path, and code on the uncommon path that depends on
 * something the common path did not save is code that has never run.
 */
class DocumentRecorded
{
    use Dispatchable;

    /**
     * @param  array<string,mixed>  $document
     */
    public function __construct(
        public readonly string $path,
        public readonly string $idempotencyKey,
        public readonly array $document,
        public readonly ?int $companyId = null,
    ) {}
}
