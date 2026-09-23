<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Exceptions;

use Throwable;

/**
 * The ledger could not be reached, or answered 5xx, after every retry.
 *
 * ---
 *
 * **The important thing this carries is whether the write was kept.**
 *
 * When deferral is on, a write that exhausts its retries is handed to a queued
 * job carrying the **same idempotency key**, and this is still thrown - because
 * your request cannot be given an invoice number that does not exist yet.
 *
 * So `wasQueued()` is the question that decides what you tell the person at the
 * screen:
 *
 *     catch (LedgerUnavailable $e) {
 *         return $e->wasQueued()
 *             ? back()->with('notice', 'Billing is catching up; the invoice will appear shortly.')
 *             : back()->withErrors('Could not reach accounting. Nothing was recorded.');
 *     }
 *
 * The distinction is not cosmetic. "It will appear shortly" told to somebody
 * whose invoice was actually dropped is worse than an honest failure, which is
 * why this reports what happened rather than assuming the optimistic case.
 *
 * When the queued job succeeds it fires `DocumentRecorded`, so the loop closes
 * without anybody polling: listen for it and update your own record with the
 * number that came back.
 */
class LedgerUnavailable extends CoreAccountingException
{
    private bool $queued = false;

    private ?string $idempotencyKey = null;

    public function __construct(
        string $message,
        ?string $errorCode = null,
        array $details = [],
        ?string $requestId = null,
        ?int $status = null,
        public readonly ?Throwable $cause = null,
    ) {
        parent::__construct($message, $errorCode, $details, $requestId, $status);
    }

    /** Always true: this is the one failure where trying again is the answer. */
    public function isRetryable(): bool
    {
        return true;
    }

    public function markQueued(string $idempotencyKey): self
    {
        $this->queued = true;
        $this->idempotencyKey = $idempotencyKey;

        return $this;
    }

    /**
     * Was the write handed to a queued job that will keep trying?
     *
     * False means nothing was kept and the call is yours to make again.
     */
    public function wasQueued(): bool
    {
        return $this->queued;
    }

    /**
     * The key the queued write carries.
     *
     * Worth storing against your own record: it is what makes the eventual
     * write the SAME write rather than a second one, and it is how you match
     * the document up afterwards if the event is missed.
     */
    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }
}
