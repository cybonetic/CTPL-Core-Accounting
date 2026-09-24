<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Tests\Feature;

use Ctpl\CoreAccounting\CoreAccounting;
use Ctpl\CoreAccounting\Http\Connection;
use Ctpl\CoreAccounting\Support\IdempotencyKey;
use Ctpl\CoreAccounting\Tests\TestCase;
use Ctpl\CoreAccounting\Values\InvoiceLine;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Sending the gateway payment id with an invoice.
 *
 * ---
 *
 * **What is actually being tested is restraint.**
 *
 * The application knows one thing: the id its gateway gave it. Core Accounting
 * calls the application's own payment-status endpoint for everything else - the
 * amount, the method, whether the payment succeeded - and posts it into the
 * gateway's clearing account only if the reply says the money was captured.
 *
 * So the SDK's job here is to send that id and send nothing else. An amount or
 * a status sent from this side would be a second opinion about a number the
 * platform is going to establish for itself, and the two will disagree the
 * first time a webhook arrives late.
 */
class PaymentIdTest extends TestCase
{
    private function ledger(): CoreAccounting
    {
        return $this->app->make(CoreAccounting::class);
    }

    /** @return array<int, InvoiceLine> */
    private function lines(): array
    {
        return [InvoiceLine::make('Consultation', '11800.00', hsnSac: '998311')];
    }

    private function key(): IdempotencyKey
    {
        return IdempotencyKey::for('hrms', 'booking-4471');
    }

    /** @return array<string,mixed> */
    private function sentBody(): array
    {
        $body = [];

        Http::assertSent(function ($request) use (&$body) {
            $body = json_decode((string) $request->body(), true) ?: [];

            return true;
        });

        return $body;
    }

    // -----------------------------------------------------------------

    #[Test]
    public function the_payment_id_goes_out_with_the_invoice(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->ledger()->invoices()->create(
            4,
            $this->lines(),
            $this->key(),
            paymentId: 'pay_ABC123',
        );

        $this->assertSame('pay_ABC123', $this->sentBody()['payment_id'] ?? null);
    }

    /**
     * And nothing else about the payment.
     *
     * The platform fetches the amount, the status and the method from the
     * application's own endpoint. Sending them from here as well would give two
     * sources for one fact, and a late webhook is all it takes for them to
     * disagree.
     */
    #[Test]
    public function the_sdk_sends_the_id_and_no_other_payment_detail(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->ledger()->invoices()->create(
            4,
            $this->lines(),
            $this->key(),
            paymentId: 'pay_ABC123',
        );

        $body = $this->sentBody();

        foreach (['payment_status', 'payment_amount', 'payment_method', 'gateway', 'paid_at'] as $key) {
            $this->assertArrayNotHasKey($key, $body);
        }
    }

    /**
     * An invoice without one carries no key at all.
     *
     * The platform treats an absent `payment_id` as "the customer has not paid
     * through a gateway" and calls nobody. An empty string would be a payment
     * id it could not look up.
     */
    #[Test]
    public function an_unpaid_invoice_carries_no_payment_key(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->ledger()->invoices()->create(4, $this->lines(), $this->key());

        $this->assertArrayNotHasKey('payment_id', $this->sentBody());
    }

    /**
     * The parameter is last, and that is load-bearing.
     *
     * Every existing caller passes its arguments positionally up to
     * `$attributes`. A new parameter inserted before that would silently shift
     * one along - `$sourceId` becoming `$attributes` - and the first symptom
     * would be an invoice attributed to the wrong application.
     */
    #[Test]
    public function positional_callers_are_unaffected(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->ledger()->invoices()->create(
            4,
            $this->lines(),
            $this->key(),
            '2026-07-15',
            '10',
            'hrms',
            'booking-4471',
            ['notes' => 'Paid at the counter'],
        );

        $body = $this->sentBody();

        $this->assertSame('hrms', $body['source_system']);
        $this->assertSame('booking-4471', $body['source_id']);
        $this->assertSame('Paid at the counter', $body['notes']);
        $this->assertArrayNotHasKey('payment_id', $body);
    }

    // -----------------------------------------------------------------
    // Paid after the invoice was raised
    // -----------------------------------------------------------------

    #[Test]
    public function a_payment_can_be_attached_to_an_invoice_raised_earlier(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 9, 'lookup_state' => 'fetched']], 201)]);

        $record = $this->ledger()->payments()->attach(8, 'pay_ABC123', $this->key());

        $this->assertSame(9, $record['id']);

        $body = $this->sentBody();

        $this->assertSame('sales_invoice', $body['document_type']);
        $this->assertSame(8, $body['document_id']);
        $this->assertSame('pay_ABC123', $body['payment_id']);
    }

    #[Test]
    public function an_empty_payment_id_is_refused_before_it_reaches_the_wire(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the id your gateway issued');

        $this->ledger()->payments()->attach(8, '   ', $this->key());
    }

    #[Test]
    public function a_document_type_that_takes_no_payment_is_refused(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        $this->ledger()->payments()->attach(8, 'pay_ABC123', $this->key(), 'journal_voucher');
    }

    /**
     * A refresh carries NO idempotency key, and that is deliberate.
     *
     * The key exists so that a retry replays the first answer. Refreshing a
     * lookup is the one case where a retry must not replay: it is being retried
     * precisely because the endpoint was down the first time.
     */
    #[Test]
    public function refreshing_a_lookup_sends_no_idempotency_key(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 9, 'lookup_state' => 'fetched']], 200)]);

        $this->ledger()->payments()->refresh(9);

        Http::assertSent(function ($request) {
            $this->assertStringEndsWith('document-payments/9/refresh', $request->url());
            $this->assertSame([], $request->header(Connection::HEADER_IDEMPOTENCY));

            return true;
        });
    }
}
