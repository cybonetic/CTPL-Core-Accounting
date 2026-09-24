<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Tests\Feature;

use Ctpl\CoreAccounting\CoreAccounting;
use Ctpl\CoreAccounting\Support\IdempotencyKey;
use Ctpl\CoreAccounting\Tests\TestCase;
use Ctpl\CoreAccounting\Values\InvoiceLine;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Prices that already contain the tax.
 *
 * ---
 *
 * **The failure these exist for is silent, which is what makes it expensive.**
 *
 * An application holds the figure the customer actually paid - 990, with 18%
 * GST already inside it - and sends it as a unit price. Core Accounting has no
 * way to know, so it applies the tax code, adds 178.20 and posts an invoice for
 * 1,168.20. Nothing errors. Revenue is overstated by 151.02, GSTR-1 reports
 * output tax that was never collected, and it surfaces weeks later when the
 * receivable will not clear against a payment of 990.
 *
 * So the assertions here are about what goes ON THE WIRE, not about what the
 * SDK computed: the SDK computes nothing, and the only thing it can get wrong
 * is failing to say what it was told.
 */
class InclusivePricingTest extends TestCase
{
    private function ledger(): CoreAccounting
    {
        return $this->app->make(CoreAccounting::class);
    }

    private function key(): IdempotencyKey
    {
        return IdempotencyKey::for('hrms', 'payroll-run-88');
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

    private function fake(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);
    }

    // -----------------------------------------------------------------

    #[Test]
    public function a_document_can_declare_its_prices_inclusive(): void
    {
        $this->fake();

        $this->ledger()->invoices()->create(
            4,
            [InvoiceLine::make('Consulting', '990.00', hsnSac: '998311')],
            $this->key(),
            pricesIncludeTax: true,
        );

        $this->assertTrue($this->sentBody()['prices_include_tax'] ?? null);
    }

    /**
     * Saying nothing sends nothing.
     *
     * The platform distinguishes three states - inclusive, exclusive, and "the
     * tax code decides". Sending `false` where the caller said nothing would
     * collapse the third into the second and change the answer for every
     * company that configured an inclusive tax code.
     */
    #[Test]
    public function an_ordinary_invoice_carries_no_flag_at_all(): void
    {
        $this->fake();

        $this->ledger()->invoices()->create(
            4,
            [InvoiceLine::make('Consulting', '990.00', hsnSac: '998311')],
            $this->key(),
        );

        $body = $this->sentBody();

        $this->assertArrayNotHasKey('prices_include_tax', $body);
        $this->assertArrayNotHasKey('price_includes_tax', $body['lines'][0]);
    }

    #[Test]
    public function a_single_line_can_declare_itself_inclusive(): void
    {
        $this->fake();

        $this->ledger()->invoices()->create(
            4,
            [InvoiceLine::make('Consulting', '990.00', hsnSac: '998311', priceIncludesTax: true)],
            $this->key(),
        );

        $this->assertTrue($this->sentBody()['lines'][0]['price_includes_tax']);
    }

    /**
     * FALSE has to survive the wire.
     *
     * The SDK strips nulls from payloads before sending. A filter written on
     * truthiness rather than on `!== null` would strip this too - and the line
     * that was opting OUT of an inclusive document would arrive saying nothing,
     * inherit the document's answer, and have the tax taken back out of a price
     * that never contained it. The error is in the direction that looks right.
     */
    #[Test]
    public function a_line_opting_out_of_an_inclusive_document_keeps_its_false(): void
    {
        $this->fake();

        $this->ledger()->invoices()->create(
            4,
            [
                InvoiceLine::make('Consulting', '1180.00', hsnSac: '998311'),
                InvoiceLine::make('Travel reimbursed', '1000.00', hsnSac: '998311')
                    ->withPriceIncludingTax(false),
            ],
            $this->key(),
            pricesIncludeTax: true,
        );

        $lines = $this->sentBody()['lines'];

        $this->assertArrayNotHasKey('price_includes_tax', $lines[0]);
        $this->assertArrayHasKey('price_includes_tax', $lines[1]);
        $this->assertFalse($lines[1]['price_includes_tax']);
    }

    /**
     * The withers rebuild the line positionally, so a new property that is not
     * threaded through them is dropped by the next `withCostCentre()` call -
     * silently, and only for the callers that use them.
     */
    #[Test]
    public function the_other_withers_do_not_drop_it(): void
    {
        $line = InvoiceLine::make('Consulting', '990.00', hsnSac: '998311', priceIncludesTax: true);

        $this->assertTrue($line->withCostCentre(7)->toArray()['price_includes_tax']);
        $this->assertTrue($line->withTaxCode(3)->toArray()['price_includes_tax']);
    }
}
