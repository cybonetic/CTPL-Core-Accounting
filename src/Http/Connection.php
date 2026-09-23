<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Http;

use Ctpl\CoreAccounting\Exceptions\CoreAccountingException;
use Ctpl\CoreAccounting\Exceptions\LedgerUnavailable;
use Ctpl\CoreAccounting\Jobs\DeliverDeferredWrite;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * One connection to one Core Accounting installation.
 *
 * Everything the SDK sends goes through here: the four headers, the retry
 * policy, and the decision about what to do with a write that could not be
 * delivered.
 */
class Connection
{
    /**
     * The three application headers and the company header, spelled once.
     *
     * They are declared in exactly one place inside the platform too, and for
     * the same reason: a header name typed a second time is a header name that
     * can drift, and the failure - every request unauthenticated, with no
     * explanation - looks nothing like a typo.
     */
    public const HEADER_APP_ID = 'X-CTPL-APPID';

    public const HEADER_KEY = 'X-CTPL-KEY';

    public const HEADER_SECRET = 'X-CTPL-SECRET';

    public const HEADER_COMPANY = 'X-Company-Id';

    public const HEADER_IDEMPOTENCY = 'Idempotency-Key';

    /**
     * @param  array<string,mixed>  $config
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
        private readonly ?int $companyId = null,
    ) {}

    /** A connection acting for a different legal entity, sharing everything else. */
    public function forCompany(int $companyId): self
    {
        return new self($this->http, $this->config, $companyId);
    }

    public function companyId(): ?int
    {
        return $this->companyId ?? ($this->config['company_id'] ?? null);
    }

    // -----------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $query
     * @return array{data: mixed, meta: array<string,mixed>, request_id: ?string}
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    /**
     * A write.
     *
     * `$idempotencyKey` is not optional and has no default, which is the whole
     * point - see `IdempotencyKey`. A random key generated here would satisfy
     * the type and defeat the mechanism: the retry below would send a *second*
     * create rather than replaying the first.
     *
     * @param  array<string,mixed>  $payload
     * @return array{data: mixed, meta: array<string,mixed>, request_id: ?string}
     */
    public function post(string $path, array $payload, string $idempotencyKey): array
    {
        return $this->send('post', $path, $payload, $idempotencyKey);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{data: mixed, meta: array<string,mixed>, request_id: ?string}
     */
    public function patch(string $path, array $payload, string $idempotencyKey): array
    {
        return $this->send('patch', $path, $payload, $idempotencyKey);
    }

    // -----------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $data
     * @return array{data: mixed, meta: array<string,mixed>, request_id: ?string}
     */
    protected function send(string $method, string $path, array $data, ?string $idempotencyKey = null): array
    {
        $attempts = max(1, (int) ($this->config['retry']['times'] ?? 3));
        $baseDelay = max(0, (int) ($this->config['retry']['base_delay_ms'] ?? 200));

        $lastFailure = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $url = $this->url($path);

                $response = $this->request($idempotencyKey)->{$method}($url, $data);

                return Envelope::open($response, $url);
            } catch (CoreAccountingException $e) {
                /*
                 * Only unavailability is retried. A 422 repeated is a 422; a 403
                 * repeated is a 403. Retrying those spends round trips to be
                 * told the same thing, and on a write it also burns the
                 * idempotency window for no reason.
                 */
                if (! $e->isRetryable() || $attempt === $attempts) {
                    throw $this->maybeDefer($e, $method, $path, $data, $idempotencyKey);
                }

                $lastFailure = $e;
            } catch (ConnectionException $e) {
                // The ledger was not reached at all: DNS, refused, timed out.
                $lastFailure = new LedgerUnavailable(
                    'Core Accounting could not be reached: '.$e->getMessage(),
                    errorCode: 'unreachable',
                    cause: $e,
                );

                if ($attempt === $attempts) {
                    throw $this->maybeDefer($lastFailure, $method, $path, $data, $idempotencyKey);
                }
            }

            /*
             * Exponential, with jitter. Without the jitter, every application
             * that failed against the same restart retries in lockstep and
             * arrives together - which is how a ledger that has just come back
             * up goes down again.
             */
            $delay = $baseDelay * (2 ** ($attempt - 1));

            usleep(($delay + random_int(0, max(1, intdiv($delay, 2)))) * 1000);
        }

        throw $lastFailure ?? new LedgerUnavailable('Core Accounting could not be reached.');
    }

    /**
     * Hand an undeliverable WRITE to a queued job, and say so on the exception.
     *
     * Reads are never deferred: nobody wants yesterday's ageing report delivered
     * tomorrow, and a read has no side effect worth preserving.
     *
     * The job carries the same idempotency key, which is what makes the eventual
     * delivery the same write rather than a second one. That is the entire
     * reason the key is required rather than generated per attempt.
     *
     * @param  array<string,mixed>  $data
     */
    protected function maybeDefer(
        CoreAccountingException $failure,
        string $method,
        string $path,
        array $data,
        ?string $idempotencyKey,
    ): CoreAccountingException {
        if (! $failure instanceof LedgerUnavailable) {
            return $failure;
        }

        if ($idempotencyKey === null || ! ($this->config['defer_writes'] ?? false)) {
            return $failure;
        }

        try {
            Bus::dispatch(new DeliverDeferredWrite(
                method: $method,
                path: $path,
                payload: $data,
                idempotencyKey: $idempotencyKey,
                companyId: $this->companyId(),
                connectionName: $this->config['name'] ?? 'default',
            ));

            return $failure->markQueued($idempotencyKey);
        } catch (Throwable) {
            /*
             * The queue is down too. Returning the original failure UNMARKED is
             * the point: an application that is told its invoice was kept, when
             * it was not, is worse off than one told plainly that it failed.
             */
            return $failure;
        }
    }

    protected function request(?string $idempotencyKey = null): PendingRequest
    {
        $headers = [
            self::HEADER_APP_ID => (string) ($this->config['app_id'] ?? ''),
            self::HEADER_KEY => (string) ($this->config['key'] ?? ''),
            self::HEADER_SECRET => (string) ($this->config['secret'] ?? ''),
            'Accept' => 'application/json',
        ];

        $company = $this->companyId();

        if ($company !== null) {
            $headers[self::HEADER_COMPANY] = (string) $company;
        }

        if ($idempotencyKey !== null) {
            $headers[self::HEADER_IDEMPOTENCY] = $idempotencyKey;
        }

        return $this->http
            ->withHeaders($headers)
            ->timeout((int) ($this->config['timeout'] ?? 15))
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 5))
            ->asJson()
            // Laravel's own retry is deliberately not used: it cannot tell a
            // retryable failure from a 422, and the deferral above needs to run
            // after the last attempt rather than inside it.
            //
            // `allow_redirects` is off for a sharper reason. Guzzle's default is
            // to follow a 301 or 302 and, in doing so, to turn a POST into a
            // GET. A create would then arrive as a list, answer 200, and the
            // application would believe it had raised an invoice that does not
            // exist. See Envelope::redirected().
            ->withOptions(['http_errors' => false, 'allow_redirects' => false]);
    }

    protected function url(string $path): string
    {
        $base = rtrim((string) ($this->config['base_url'] ?? ''), '/');
        $prefix = trim((string) ($this->config['prefix'] ?? 'api/v1'), '/');

        return $base.'/'.$prefix.'/'.ltrim($path, '/');
    }
}
