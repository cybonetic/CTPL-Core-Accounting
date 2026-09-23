<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Testing;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

/**
 * A ledger for your application's tests.
 *
 * ---
 *
 * **Why an SDK ships this at all.**
 *
 * Without it, every application integrating with Core Accounting writes its own
 * `Http::fake()` with its own guess at the envelope, and those guesses drift
 * from the real one - so the tests pass and the integration does not. The
 * shapes here are the shapes the platform actually returns, taken from live
 * responses.
 *
 *     FakeLedger::start()->invoiceNumbered('HRMS-INV-26-0001');
 *
 *     $this->post('/payroll/88/bill')->assertOk();
 *
 *     FakeLedger::assertInvoiceRaised(fn (array $body) =>
 *         $body['customer_id'] === 4 && $body['lines'][0]['unit_price'] === '25000.00'
 *     );
 *
 * ---
 *
 * **The assertions are about what YOU sent**, not about what the fake returned.
 * A test that checks its own fixture proves nothing; the question worth asking
 * of an integration is whether the payload leaving your application is the one
 * you meant - and, above all, whether the idempotency key is stable.
 */
final class FakeLedger
{
    /** @var array<string, array<string,mixed>> */
    private array $responses = [];

    private function __construct() {}

    public static function start(): self
    {
        $fake = new self;
        $fake->install();

        return $fake;
    }

    /** The next invoice create succeeds and comes back with this number. */
    public function invoiceNumbered(string $number, int $id = 1, string $grandTotal = '29500.0000'): self
    {
        return $this->responds('invoices', [
            'id' => $id,
            'document_number' => $number,
            'status' => 'posted',
            'journal_entry_id' => $id,
            // A string. Every amount the platform returns is a string, and a
            // fake that returns a float trains an application to accept one.
            'grand_total' => $grandTotal,
            'taxable_total' => '25000.0000',
            'tax_total' => '4500.0000',
            'settlement_status' => 'open',
        ]);
    }

    /** The ledger refuses because the company has no cost centre yet. */
    public function refusesWithoutCostCentre(): self
    {
        return $this->fails('invoices', 422, [
            'code' => 'accounting_rule_violation',
            'message' => 'Line 1 posts to 4200 Service Revenue, which requires a Cost Centre.',
            'details' => ['line' => 1, 'account_code' => '4200', 'dimension' => 'COST_CENTRE'],
        ]);
    }

    /** The ledger is down, so the retry and the deferral can be exercised. */
    public function unavailable(string $path = '*'): self
    {
        $this->responses[$path] = Http::response('gateway timeout', 504);
        $this->install();

        return $this;
    }

    /** @param array<string,mixed> $data */
    public function responds(string $path, array $data, int $status = 201): self
    {
        $this->responses[$path] = Http::response([
            'data' => $data,
            'request_id' => 'test-'.bin2hex(random_bytes(4)),
        ], $status);

        $this->install();

        return $this;
    }

    /** @param array<string,mixed> $error */
    public function fails(string $path, int $status, array $error): self
    {
        $this->responses[$path] = Http::response([
            'error' => $error,
            'request_id' => 'test-'.bin2hex(random_bytes(4)),
        ], $status);

        $this->install();

        return $this;
    }

    // -----------------------------------------------------------------

    /**
     * @param  null|callable(array<string,mixed>, string): bool  $matching  the body and the idempotency key
     */
    public static function assertInvoiceRaised(?callable $matching = null): void
    {
        self::assertSent('invoices', $matching, 'No invoice was raised.');
    }

    /** @param null|callable(array<string,mixed>, string): bool $matching */
    public static function assertCreditNoteRaised(?callable $matching = null): void
    {
        self::assertSent('notes', $matching, 'No credit note was raised.');
    }

    /** @param null|callable(array<string,mixed>, string): bool $matching */
    public static function assertReceiptRecorded(?callable $matching = null): void
    {
        self::assertSent('receipts', $matching, 'No receipt was recorded.');
    }

    public static function assertNothingRaised(): void
    {
        Http::assertNothingSent();
    }

    /**
     * The assertion worth writing above all the others.
     *
     * Two calls for the same business event must carry the same key, or the
     * retry that happens one day in production raises a second invoice with a
     * second statutory number. Nothing else in a test suite catches that,
     * because in a test nothing is ever retried.
     */
    public static function assertIdempotencyKeyStable(): void
    {
        $keys = [];

        foreach (self::recorded() as [$request]) {
            if ($request->method() === 'GET') {
                continue;
            }

            $key = $request->header('Idempotency-Key')[0] ?? null;

            Assert::assertNotNull($key, sprintf('A write to %s carried no idempotency key.', $request->url()));

            $keys[$request->url()][] = $key;
        }

        foreach ($keys as $url => $seen) {
            Assert::assertCount(
                1,
                array_unique($seen),
                sprintf(
                    'Writes to %s carried %d different idempotency keys. A retry would therefore create '
                    .'a second document rather than replaying the first - derive the key from your own '
                    .'record, not from the clock or a random value.',
                    $url,
                    count(array_unique($seen)),
                ),
            );
        }
    }

    // -----------------------------------------------------------------

    private static function assertSent(string $path, ?callable $matching, string $failure): void
    {
        Http::assertSent(function ($request) use ($path, $matching) {
            if (! str_contains($request->url(), '/'.$path) || $request->method() !== 'POST') {
                return false;
            }

            if ($matching === null) {
                return true;
            }

            return $matching($request->data(), $request->header('Idempotency-Key')[0] ?? '');
        });
    }

    /** @return iterable<array{0: \Illuminate\Http\Client\Request}> */
    private static function recorded(): iterable
    {
        return Http::recorded() ?: [];
    }

    private function install(): void
    {
        $stubs = [];

        foreach ($this->responses as $path => $response) {
            $stubs[$path === '*' ? '*' : '*/'.ltrim($path, '/')] = $response;
        }

        // Anything not explicitly stubbed answers 404 rather than succeeding.
        // A fake that returns an empty 200 for an unexpected call lets a test
        // pass over an endpoint nobody meant to hit.
        $stubs['*'] ??= Http::response([
            'error' => ['code' => 'not_found', 'message' => 'FakeLedger has no stub for this call.'],
        ], 404);

        Http::fake($stubs);
    }
}
