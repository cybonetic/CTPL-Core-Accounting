<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Tests\Feature;

use Ctpl\CoreAccounting\CoreAccounting;
use Ctpl\CoreAccounting\Events\DocumentRecorded;
use Ctpl\CoreAccounting\Exceptions\AccountingRuleViolation;
use Ctpl\CoreAccounting\Exceptions\ImmutableDocument;
use Ctpl\CoreAccounting\Exceptions\LedgerUnavailable;
use Ctpl\CoreAccounting\Exceptions\PermissionDenied;
use Ctpl\CoreAccounting\Exceptions\ValidationFailed;
use Ctpl\CoreAccounting\Http\Connection;
use Ctpl\CoreAccounting\Jobs\DeliverDeferredWrite;
use Ctpl\CoreAccounting\Support\IdempotencyKey;
use Ctpl\CoreAccounting\Tests\TestCase;
use Ctpl\CoreAccounting\Values\InvoiceLine;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class LedgerTest extends TestCase
{
    private function ledger(): CoreAccounting
    {
        return $this->app->make(CoreAccounting::class);
    }

    /** @return array<int, InvoiceLine> */
    private function lines(): array
    {
        return [InvoiceLine::make('Payroll processing, September', '25000.00', taxCodeId: 7, costCentreId: 1)];
    }

    private function key(): IdempotencyKey
    {
        return IdempotencyKey::for('hrms', 'payroll-run-88');
    }

    // -----------------------------------------------------------------
    // The wire
    // -----------------------------------------------------------------

    /**
     * The four headers, on every request, spelled the way the platform reads
     * them. A header name that drifts makes every call unauthenticated with no
     * explanation, so it is asserted rather than assumed.
     */
    #[Test]
    public function every_request_carries_the_credentials_and_the_company(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->ledger()->invoices()->create(4, $this->lines(), $this->key());

        Http::assertSent(function ($request) {
            $this->assertSame('CTPL-TEST-APP', $request->header(Connection::HEADER_APP_ID)[0]);
            $this->assertSame('ak_test', $request->header(Connection::HEADER_KEY)[0]);
            $this->assertSame('sk_test', $request->header(Connection::HEADER_SECRET)[0]);
            $this->assertSame('1', $request->header(Connection::HEADER_COMPANY)[0]);
            $this->assertSame('hrms-payroll-run-88', $request->header(Connection::HEADER_IDEMPOTENCY)[0]);

            return true;
        });
    }

    #[Test]
    public function money_reaches_the_wire_as_a_string(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->ledger()->invoices()->create(4, $this->lines(), $this->key());

        Http::assertSent(function ($request) {
            $line = $request->data()['lines'][0];

            $this->assertSame('25000.00', $line['unit_price']);
            $this->assertIsString($line['unit_price']);

            return true;
        });
    }

    #[Test]
    public function acting_for_another_company_does_not_change_the_original(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $ledger = $this->ledger();
        $other = $ledger->forCompany(7);

        $other->receivables()->ageing();
        $ledger->receivables()->ageing();

        $companies = [];

        foreach (Http::recorded() as [$request]) {
            $companies[] = $request->header(Connection::HEADER_COMPANY)[0];
        }

        // Each call carried its own entity. A mutating `forCompany` would have
        // leaked the second company into the first instance.
        $this->assertSame(['7', '1'], $companies);
    }

    // -----------------------------------------------------------------
    // Errors
    // -----------------------------------------------------------------

    /**
     * The two 422s mean opposite things and must not collapse into one.
     */
    #[Test]
    public function a_rule_of_accounting_is_not_reported_as_a_validation_error(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 'accounting_rule_violation',
                'message' => 'Line 1 posts to 4200 Service Revenue, which requires a Cost Centre.',
                'details' => ['line' => 1, 'dimension' => 'COST_CENTRE'],
            ],
        ], 422)]);

        try {
            $this->ledger()->invoices()->create(4, $this->lines(), $this->key());
            $this->fail('The refusal was not raised.');
        } catch (AccountingRuleViolation $e) {
            $this->assertTrue($e->needsCostCentre());
            $this->assertSame(1, $e->line());
            // The sentence is the useful part and reaches the caller intact.
            $this->assertStringContainsString('requires a Cost Centre', $e->getMessage());
            $this->assertFalse($e->isRetryable());
        }
    }

    #[Test]
    public function a_validation_failure_carries_the_fields(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'The request payload is invalid.',
                'details' => ['lines' => ['The lines field is required.']],
            ],
        ], 422)]);

        try {
            $this->ledger()->invoices()->create(4, $this->lines(), $this->key());
            $this->fail('Validation did not fail.');
        } catch (ValidationFailed $e) {
            $this->assertSame(['lines' => ['The lines field is required.']], $e->errors());
            $this->assertSame('The lines field is required.', $e->firstError());
        }
    }

    /** A missing scope names itself, so an administrator can grant it. */
    #[Test]
    public function a_missing_permission_says_which_one(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 'forbidden',
                'message' => 'This action requires the "invoice.create" permission.',
                'details' => ['permission' => 'invoice.create'],
            ],
        ], 403)]);

        $this->expectException(PermissionDenied::class);
        $this->expectExceptionMessageMatches('/Grant "invoice\.create" to this application/');

        $this->ledger()->invoices()->create(4, $this->lines(), $this->key());
    }

    /**
     * An HTML error page from a proxy is unavailability, not a client error.
     */
    #[Test]
    public function a_non_json_body_is_treated_as_the_ledger_being_unreachable(): void
    {
        Http::fake(['*' => Http::response('<html>502 Bad Gateway</html>', 502)]);

        try {
            $this->ledger()->receivables()->ageing();
            $this->fail('A gateway error was accepted.');
        } catch (LedgerUnavailable $e) {
            $this->assertTrue($e->isRetryable());
            $this->assertStringContainsString('not JSON', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // Retry
    // -----------------------------------------------------------------

    #[Test]
    public function a_5xx_is_retried_and_the_retry_carries_the_same_key(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('', 503)
            ->push(['data' => ['id' => 9, 'document_number' => 'HRMS-INV-26-0009']], 201)]);

        $invoice = $this->ledger()->invoices()->create(4, $this->lines(), $this->key());

        $this->assertSame('HRMS-INV-26-0009', $invoice['document_number']);

        $keys = [];

        foreach (Http::recorded() as [$request]) {
            $keys[] = $request->header(Connection::HEADER_IDEMPOTENCY)[0];
        }

        // Two attempts, ONE key. This is the property the whole design rests
        // on: the second attempt replays the first write rather than raising a
        // second invoice with a second statutory number.
        $this->assertCount(2, $keys);
        $this->assertSame(['hrms-payroll-run-88', 'hrms-payroll-run-88'], $keys);
    }

    #[Test]
    public function a_422_is_not_retried(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'validation_failed', 'message' => 'nope', 'details' => []],
        ], 422)]);

        try {
            $this->ledger()->invoices()->create(4, $this->lines(), $this->key());
        } catch (ValidationFailed) {
            // expected
        }

        // One attempt. Repeating a 422 spends a round trip to be told the same
        // thing, and on a write it burns the idempotency window for nothing.
        $this->assertCount(1, iterator_to_array(Http::recorded()));
    }

    // -----------------------------------------------------------------
    // Deferral
    // -----------------------------------------------------------------

    #[Test]
    public function an_undeliverable_write_is_queued_and_the_exception_says_so(): void
    {
        config()->set('core-accounting.defer_writes', true);
        Bus::fake();
        Http::fake(['*' => Http::response('', 503)]);

        try {
            $this->ledger()->invoices()->create(4, $this->lines(), $this->key());
            $this->fail('The failure was swallowed.');
        } catch (LedgerUnavailable $e) {
            $this->assertTrue($e->wasQueued());
            $this->assertSame('hrms-payroll-run-88', $e->idempotencyKey());
        }

        Bus::assertDispatched(
            DeliverDeferredWrite::class,
            fn (DeliverDeferredWrite $job) => $job->idempotencyKey === 'hrms-payroll-run-88'
                && $job->path === 'invoices'
        );
    }

    /**
     * A read is never deferred: nobody wants yesterday's ageing tomorrow.
     */
    #[Test]
    public function a_failed_read_is_not_queued(): void
    {
        config()->set('core-accounting.defer_writes', true);
        Bus::fake();
        Http::fake(['*' => Http::response('', 503)]);

        try {
            $this->ledger()->receivables()->ageing();
        } catch (LedgerUnavailable $e) {
            $this->assertFalse($e->wasQueued());
        }

        Bus::assertNothingDispatched();
    }

    /**
     * If the queue is down too, the exception must NOT claim the write was kept.
     *
     * Telling an application its invoice is safe when it was dropped is worse
     * than an honest failure - it is the difference between a retry and a
     * missing invoice nobody looks for.
     */
    #[Test]
    public function a_failed_dispatch_does_not_claim_the_write_was_kept(): void
    {
        config()->set('core-accounting.defer_writes', true);
        Http::fake(['*' => Http::response('', 503)]);

        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('queue is down'));

        try {
            $this->ledger()->invoices()->create(4, $this->lines(), $this->key());
            $this->fail('The failure was swallowed.');
        } catch (LedgerUnavailable $e) {
            $this->assertFalse($e->wasQueued(), 'The SDK claimed a write was queued when it was not.');
            $this->assertNull($e->idempotencyKey());
        }
    }

    // -----------------------------------------------------------------
    // The deferred job
    // -----------------------------------------------------------------

    #[Test]
    public function the_deferred_job_delivers_and_announces_the_document(): void
    {
        \Illuminate\Support\Facades\Event::fake([DocumentRecorded::class]);

        Http::fake(['*' => Http::response([
            'data' => ['id' => 9, 'document_number' => 'HRMS-INV-26-0009'],
        ], 201)]);

        $job = new DeliverDeferredWrite(
            method: 'post',
            path: 'invoices',
            payload: ['customer_id' => 4],
            idempotencyKey: 'hrms-payroll-run-88',
            companyId: 1,
        );

        $job->handle($this->app->make(\Illuminate\Http\Client\Factory::class));

        \Illuminate\Support\Facades\Event::assertDispatched(
            DocumentRecorded::class,
            fn (DocumentRecorded $e) => $e->idempotencyKey === 'hrms-payroll-run-88'
                && $e->document['document_number'] === 'HRMS-INV-26-0009'
        );
    }

    /**
     * A refusal is not retried for a day.
     *
     * A job that keeps retrying a 422 is a job that hides a bug: the ledger
     * answered and said no, and it will say no again in an hour.
     */
    #[Test]
    public function the_deferred_job_fails_immediately_on_a_refusal(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'validation_failed', 'message' => 'nope', 'details' => []],
        ], 422)]);

        $job = $this->getMockBuilder(DeliverDeferredWrite::class)
            ->setConstructorArgs([
                'method' => 'post',
                'path' => 'invoices',
                'payload' => [],
                'idempotencyKey' => 'hrms-payroll-run-88',
                'companyId' => 1,
                'connectionName' => 'default',
            ])
            ->onlyMethods(['fail'])
            ->getMock();

        $job->expects($this->once())->method('fail');

        $job->handle($this->app->make(\Illuminate\Http\Client\Factory::class));
    }

    // -----------------------------------------------------------------
    // The shape of the API itself
    // -----------------------------------------------------------------

    /**
     * The method everybody reaches for, and why it is not simply absent.
     */
    #[Test]
    public function updating_an_invoice_explains_itself_rather_than_failing_obscurely(): void
    {
        try {
            $this->ledger()->invoices()->update(1, ['grand_total' => '1.00']);
            $this->fail('An invoice was updated.');
        } catch (ImmutableDocument $e) {
            $this->assertStringContainsString('cannot be updated', $e->getMessage());
            // It names both remedies and when each applies.
            $this->assertStringContainsString('cancel', $e->getMessage());
            $this->assertStringContainsString('credit note', $e->getMessage());
        }
    }

    /** A customer, by contrast, is a master record and really is updatable. */
    #[Test]
    public function a_customer_can_be_updated(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 4, 'email' => 'new@example.test']], 200)]);

        $customer = $this->ledger()->customers()->update(
            4,
            ['email' => 'new@example.test'],
            IdempotencyKey::for('hrms', 'customer-4-email-2026-09'),
        );

        $this->assertSame('new@example.test', $customer['email']);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH');
    }

    #[Test]
    public function a_receipt_without_a_deposit_account_is_refused_before_the_round_trip(): void
    {
        Http::fake();

        $this->expectExceptionMessageMatches('/where the money landed/');

        $this->ledger()->receipts()->record(4, '29500.00', $this->key());

        Http::assertNothingSent();
    }

    #[Test]
    public function an_unknown_note_reason_is_refused_with_the_list(): void
    {
        Http::fake();

        try {
            $this->ledger()->notes()->credit(4, $this->lines(), 'because-i-said-so', $this->key());
            $this->fail('An invented reason was accepted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('sales_return', $e->getMessage());
            $this->assertStringContainsString('post_sale_discount', $e->getMessage());
        }
    }
}
