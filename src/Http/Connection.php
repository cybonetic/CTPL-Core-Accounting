<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Http;

use Ctpl\CoreAccounting\Crypto\Envelope as Sealed;
use Ctpl\CoreAccounting\Crypto\PlatformKey;
use Ctpl\CoreAccounting\Exceptions\CoreAccountingException;
use Ctpl\CoreAccounting\Exceptions\EncryptionFailed;
use Ctpl\CoreAccounting\Exceptions\LedgerUnavailable;
use Ctpl\CoreAccounting\Jobs\DeliverDeferredWrite;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use GuzzleHttp\Psr7\Response as Psr7Response;
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

    /** Held for the life of this connection once resolved. */
    private ?PlatformKey $platformKey = null;

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
     * A POST that creates nothing, and therefore carries no idempotency key.
     *
     * A handful of endpoints are POSTs because they take an action, not because
     * they write a document - refreshing a payment lookup is one. Giving those
     * a key would be actively wrong: the key exists so that a retry REPLAYS the
     * first answer, and the whole point of retrying a lookup is to get a new
     * one.
     *
     * Separate from `post()` rather than a nullable argument on it, so that
     * omitting a key on a real write stays impossible.
     *
     * @param  array<string,mixed>  $payload
     * @return array{data: mixed, meta: array<string,mixed>, request_id: ?string}
     */
    public function act(string $path, array $payload = []): array
    {
        return $this->send('post', $path, $payload, null);
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

                /*
                 * The body is serialised HERE, once, and the same string is
                 * both signed and sent.
                 *
                 * Letting the HTTP client encode the array while signing a
                 * separately-encoded copy of it is how a signature that looks
                 * right fails every time: the two encoders disagree over an
                 * escaped slash or a unicode character, the bytes differ, and
                 * the platform - which recomputes from what actually arrived -
                 * refuses. See Signature.
                 *
                 * A GET carries its parameters in the query string and has no
                 * body at all, so it signs the empty string.
                 */
                $isWrite = $method !== 'get';
                $body = $isWrite ? $this->encode($data) : '';

                /*
                 * Encrypted BEFORE it is signed, and that order is not a
                 * preference.
                 *
                 * The platform verifies the signature before it decrypts -
                 * signature verification has to see the bytes that actually
                 * arrived, and source attribution has to see the body the
                 * controller will read. So the signature covers the CIPHERTEXT.
                 * Signing the plaintext and then encrypting would produce a
                 * signature over bytes the platform never sees.
                 *
                 * A GET has nothing to encrypt. Its parameters are in the query
                 * string, which this layer does not hide - the platform does
                 * not offer a way to encrypt those, and pretending otherwise
                 * would be worse than saying so.
                 */
                if ($isWrite && $this->encrypts()) {
                    $body = $this->encode(Sealed::seal($body, $this->platformKey()->pem(), $this->platformKey()->kid()));
                }

                $request = $this->request($idempotencyKey, $body);

                $response = $isWrite
                    ? $request->withBody($body, 'application/json')->{$method}($url)
                    : $request->{$method}($url, $data);

                return Envelope::open($this->decipher($response), $url);
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

    /**
     * @param  string  $body  the exact bytes that will be transmitted, which is
     *                        what the signature must be computed over
     */
    protected function request(?string $idempotencyKey = null, string $body = ''): PendingRequest
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

        /*
         * Signed only when a signing secret is configured.
         *
         * Not every credential requires it, and sending a signature computed
         * with an empty secret would be worse than sending none: the platform
         * would compare it against the real one and refuse with "the signature
         * does not match the payload", which sends somebody looking at their
         * body serialisation rather than at the blank line in their .env.
         *
         * The signature is recomputed on every attempt rather than reused,
         * because its timestamp is inside the signed string and a retry a few
         * seconds later must carry a fresh one. The idempotency key stays the
         * same - that is what makes the retry a replay rather than a second
         * write - but the signature cannot.
         */
        $signingSecret = (string) ($this->config['signing_secret'] ?? '');

        if ($signingSecret !== '') {
            $headers[Signature::HEADER] = Signature::header($body, $signingSecret);
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

    // -----------------------------------------------------------------
    // Payload encryption
    // -----------------------------------------------------------------

    /** Is this application configured to encrypt what it sends? */
    protected function encrypts(): bool
    {
        return (bool) ($this->config['encryption']['enabled'] ?? false);
    }

    /**
     * The platform key, from configuration, resolved once and held.
     *
     * Nothing is fetched. An administrator generated the key pair on this
     * application's screen in the back office and handed over the public half;
     * that delivery is the out-of-band step, and a key already in hand needs no
     * thumbprint pinned against it.
     */
    protected function platformKey(): PlatformKey
    {
        return $this->platformKey ??= new PlatformKey($this->config['encryption'] ?? []);
    }

    /**
     * Open an encrypted reply, leaving a plaintext one alone.
     *
     * Detected by shape rather than by whether we encrypted the request. The
     * platform encrypts a reply when the request was encrypted OR when the
     * application is flagged as requiring it, and those are not the same
     * condition - an application switched over at the server while its config
     * still says otherwise would otherwise be handed ciphertext it silently
     * failed to parse.
     *
     * **The status code is preserved.** A 422 stays a 422: the envelope layer
     * branches on status before it has decrypted anything, and a client that
     * has lost its key still has to tell a refusal from a success.
     */
    protected function decipher(Response $response): Response
    {
        $body = json_decode((string) $response->body(), true);

        if (! Sealed::looksLikeOne($body)) {
            return $response;
        }

        $privateKey = $this->applicationPrivateKey();

        $plaintext = Sealed::open($body, $privateKey);

        return new Response(new Psr7Response(
            $response->status(),
            $response->headers(),
            $plaintext,
        ));
    }

    /**
     * This application's OWN private key, which reads the replies.
     *
     * A different key from the one in `platformKey()` and easy to confuse with
     * it. This half never leaves this application - Core Accounting holds only
     * the public half, uploaded once - and it is the reason a reply encrypted
     * to this application cannot be read by anybody else, including the
     * platform's other integrations.
     */
    protected function applicationPrivateKey(): string
    {
        $inline = trim((string) ($this->config['encryption']['private_key'] ?? ''));
        $path = trim((string) ($this->config['encryption']['private_key_path'] ?? ''));

        if ($inline !== '') {
            return $inline;
        }

        if ($path === '') {
            throw new EncryptionFailed(
                'Core Accounting sent an encrypted reply and this application has no private key to '
                .'open it with. Set CORE_ACCOUNTING_PRIVATE_KEY_PATH to the key whose public half you '
                .'uploaded on the application\'s screen. If you did not mean to receive ciphertext, '
                .'the application is flagged as requiring encryption at the server.'
            );
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new EncryptionFailed(sprintf(
                'The private key file %s does not exist or cannot be read by %s.',
                $path,
                function_exists('get_current_user') ? get_current_user() : 'this process'
            ));
        }

        return (string) file_get_contents($path);
    }

    /**
     * The request body, as the one canonical string.
     *
     * `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` is not cosmetic here.
     * They are the flags Laravel's own `asJson()` would have used, so a body
     * built by this method is byte-identical to one the client would have
     * produced - which keeps the two paths interchangeable and stops a future
     * edit reintroducing the mismatch this method exists to prevent.
     *
     * @param  array<string,mixed>  $data
     */
    protected function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    protected function url(string $path): string
    {
        $base = rtrim((string) ($this->config['base_url'] ?? ''), '/');
        $prefix = trim((string) ($this->config['prefix'] ?? 'api/v1'), '/');

        return $base.'/'.$prefix.'/'.ltrim($path, '/');
    }
}
