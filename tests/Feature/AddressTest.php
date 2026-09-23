<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Tests\Feature;

use Ctpl\CoreAccounting\CoreAccounting;
use Ctpl\CoreAccounting\Exceptions\GenericFailure;
use Ctpl\CoreAccounting\Support\IdempotencyKey;
use Ctpl\CoreAccounting\Tests\TestCase;
use Ctpl\CoreAccounting\Values\InvoiceLine;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Where the SDK sends things, and what it refuses to do on the way.
 */
class AddressTest extends TestCase
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

    /** @return array<string,mixed> */
    private function freshConfig(): array
    {
        return require __DIR__.'/../../config/core-accounting.php';
    }

    // -----------------------------------------------------------------
    // The address
    // -----------------------------------------------------------------

    /**
     * The production installation is compiled in.
     *
     * An application that requires this package and sets its three credentials
     * reaches the real ledger without anybody having to be told the address.
     * Pinned by a test because the value is now a fact about the package rather
     * than a placeholder: changing it moves every installation at once.
     */
    #[Test]
    public function the_production_ledger_is_the_default(): void
    {
        putenv('CORE_ACCOUNTING_URL');
        unset($_ENV['CORE_ACCOUNTING_URL'], $_SERVER['CORE_ACCOUNTING_URL']);

        $this->assertSame('https://cacc.cybonetic.com', $this->freshConfig()['base_url']);
    }

    /** Staging and local work still need somewhere else to point. */
    #[Test]
    public function an_explicit_url_overrides_the_default(): void
    {
        putenv('CORE_ACCOUNTING_URL=https://staging.example.test');

        try {
            $this->assertSame('https://staging.example.test', $this->freshConfig()['base_url']);
        } finally {
            putenv('CORE_ACCOUNTING_URL');
        }
    }

    /**
     * A blank variable falls back to production rather than to "".
     *
     * `env('X', $default)` returns the default only when the variable is
     * absent; an empty one yields an empty string, and the SDK would then post
     * invoices to "/api/v1/invoices" with no host at all. The config uses `?:`
     * for exactly this, so the behaviour is asserted.
     */
    #[Test]
    public function a_blank_url_falls_back_rather_than_emptying_the_address(): void
    {
        putenv('CORE_ACCOUNTING_URL=');

        try {
            $this->assertSame('https://cacc.cybonetic.com', $this->freshConfig()['base_url']);
        } finally {
            putenv('CORE_ACCOUNTING_URL');
        }
    }

    // -----------------------------------------------------------------
    // Redirects
    // -----------------------------------------------------------------

    /**
     * The one that matters.
     *
     * Guzzle follows a 301 by default and, in doing so, downgrades a POST to a
     * GET. An invoice create would arrive at the ledger as a list request,
     * answer 200 with a page of invoices, and the calling application would
     * record a number for a document that was never raised. The SDK refuses
     * instead, and says why.
     */
    #[Test]
    public function a_redirect_on_a_write_is_refused_rather_than_followed(): void
    {
        Http::fake([
            '*' => Http::response('', 301, ['Location' => 'https://elsewhere.test/api/v1/invoices']),
        ]);

        try {
            $this->ledger()->invoices()->create(4, $this->lines(), IdempotencyKey::for('hrms', 'run-1'));
            $this->fail('A redirected write should not have been reported as a success.');
        } catch (GenericFailure $e) {
            $this->assertStringContainsString('does not follow redirects', $e->getMessage());
            $this->assertStringContainsString('https://elsewhere.test/api/v1/invoices', $e->getMessage());
            $this->assertSame(301, $e->status);
        }

        // Not followed, and not retried: a redirect is a deployment fact.
        Http::assertSentCount(1);
    }

    /**
     * A redirect to the address just requested is an infinite loop, and it has
     * one overwhelmingly common cause. The SDK names it, because the
     * alternative is an afternoon spent reading routes that are innocent.
     */
    #[Test]
    public function a_redirect_to_itself_names_the_tls_termination_cause(): void
    {
        Http::fake([
            '*' => Http::response('', 301, ['Location' => 'https://ledger.test/api/v1/invoices']),
        ]);

        try {
            $this->ledger()->invoices()->list();
            $this->fail('A redirect loop should not have been reported as a success.');
        } catch (GenericFailure $e) {
            $this->assertStringContainsString('redirecting to itself', $e->getMessage());
            $this->assertStringContainsString('Flexible', $e->getMessage());
            $this->assertStringContainsString('X-Forwarded-Proto', $e->getMessage());
        }
    }

    /**
     * Not retryable. Retrying a permanent redirect spends the retry budget to
     * be told the same thing three times, and on a write it burns the
     * idempotency window for nothing.
     */
    #[Test]
    public function a_redirect_is_not_retryable(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.test/'])]);

        try {
            $this->ledger()->invoices()->list();
            $this->fail('Expected a failure.');
        } catch (GenericFailure $e) {
            $this->assertFalse($e->isRetryable());
        }

        Http::assertSentCount(1);
    }
}
