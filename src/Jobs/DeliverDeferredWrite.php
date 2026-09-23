<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Jobs;

use Ctpl\CoreAccounting\Events\DocumentRecorded;
use Ctpl\CoreAccounting\Exceptions\CoreAccountingException;
use Ctpl\CoreAccounting\Http\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * A write the ledger could not take, kept until it can.
 *
 * ---
 *
 * **Why this is safe to run repeatedly, and why that is the whole design.**
 *
 * It carries the idempotency key the original call used. Replaying it against a
 * ledger that already took the write returns the SAME document rather than
 * raising a second one - which is what makes an at-least-once queue acceptable
 * for something as unforgiving as an invoice.
 *
 * Without that key this job would be a machine for producing duplicate invoices
 * with consecutive statutory numbers, discovered a month later.
 *
 * ---
 *
 * **What it does NOT retry.** Only unavailability. If the ledger comes back and
 * says the payload is invalid, or that a rule of accounting was broken, retrying
 * for a day changes nothing - so the job fails immediately and lands in
 * `failed_jobs` where somebody will see it. A job that retries a 422 for
 * twenty-four hours is a job that hides a bug.
 */
class DeliverDeferredWrite implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Unlimited attempts, bounded by time instead.
     *
     * A fixed attempt count would give up after a few minutes of backoff, which
     * is shorter than most deployments. `retryUntil()` keeps trying across the
     * whole window and then fails once, loudly.
     */
    public int $tries = 0;

    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $payload,
        public readonly string $idempotencyKey,
        public readonly ?int $companyId = null,
        // NOT `$connection`: Queueable already defines that property for the
        // queue connection, and a promoted property of the same name is a
        // fatal composition error rather than an override.
        public readonly string $connectionName = 'default',
    ) {
        $this->onConnection(config('core-accounting.queue.connection'));
        $this->onQueue(config('core-accounting.queue.queue', 'default'));
    }

    public function retryUntil(): Carbon
    {
        return now()->addHours((int) config('core-accounting.queue.retry_hours', 24));
    }

    /** Backoff in seconds: a minute, five, fifteen, then every half hour. */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(HttpFactory $http): void
    {
        $config = config('core-accounting');
        $config['name'] = $this->connectionName;

        // Deferral is switched OFF for the retry itself: this job IS the
        // deferral, and letting it queue another copy of itself on failure
        // would multiply one undeliverable write into a queue full of them.
        $config['defer_writes'] = false;

        $connection = new Connection($http, $config, $this->companyId);

        try {
            $envelope = $connection->{$this->method}($this->path, $this->payload, $this->idempotencyKey);
        } catch (CoreAccountingException $e) {
            if ($e->isRetryable()) {
                // Still down. Let the queue bring it back.
                throw $e;
            }

            /*
             * The ledger answered and refused. Retrying will not change its
             * mind, so this fails now rather than filling a day with round
             * trips - and it is logged with the key, so the record it belongs
             * to can be found.
             */
            Log::error('A deferred Core Accounting write was refused and will not be retried.', [
                'path' => $this->path,
                'idempotency_key' => $this->idempotencyKey,
                'error_code' => $e->errorCode,
                'request_id' => $e->requestId(),
                'message' => $e->getMessage(),
            ]);

            $this->fail($e);

            return;
        }

        DocumentRecorded::dispatch(
            $this->path,
            $this->idempotencyKey,
            is_array($envelope['data'] ?? null) ? $envelope['data'] : [],
            $this->companyId,
        );
    }

    /** One job per business event, so a double dispatch collapses into one. */
    public function uniqueId(): string
    {
        return $this->path.':'.$this->idempotencyKey;
    }
}
