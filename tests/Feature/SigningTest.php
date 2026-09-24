<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Tests\Feature;

use Ctpl\CoreAccounting\CoreAccounting;
use Ctpl\CoreAccounting\Exceptions\PermissionDenied;
use Ctpl\CoreAccounting\Http\Connection;
use Ctpl\CoreAccounting\Http\Signature;
use Ctpl\CoreAccounting\Support\IdempotencyKey;
use Ctpl\CoreAccounting\Tests\TestCase;
use Ctpl\CoreAccounting\Values\InvoiceLine;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Request signing, for credentials that require it.
 *
 * ---
 *
 * **The assertion that matters is `the_signature_covers_the_exact_bytes_sent`.**
 *
 * The platform recomputes the signature from the literal bytes that arrived. So
 * the only thing that can be tested usefully is that the string we signed and
 * the string we sent are the same string - not that we called `hash_hmac` with
 * plausible arguments.
 *
 * The failure this guards against is nastier than it looks: an array handed to
 * the HTTP client and separately `json_encode`d to sign produces two strings
 * that differ by an escaped slash, every request is refused with "the signature
 * does not match the payload", and the body in the log looks perfectly correct.
 * People lose days to it.
 */
class SigningTest extends TestCase
{
    private const SECRET = 'sk_signing_2f9d4c7b1a6e8f03';

    private function ledger(): CoreAccounting
    {
        return $this->app->make(CoreAccounting::class);
    }

    /** @return array<int, InvoiceLine> */
    private function lines(): array
    {
        // A description with a slash and a non-ASCII character, deliberately.
        // Both are the characters json_encode escapes by default, and both are
        // how a body signed by one encoder and sent by another comes apart.
        return [InvoiceLine::make('Payroll ₹ processing 09/2026', '25000.00', taxCodeId: 7, costCentreId: 1)];
    }

    private function key(): IdempotencyKey
    {
        return IdempotencyKey::for('hrms', 'payroll-run-88');
    }

    private function signing(): void
    {
        config(['core-accounting.signing_secret' => self::SECRET]);
    }

    // -----------------------------------------------------------------

    /**
     * Recompute from what arrived, exactly as the platform does.
     */
    #[Test]
    public function the_signature_covers_the_exact_bytes_sent(): void
    {
        $this->signing();

        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->ledger()->invoices()->create(4, $this->lines(), $this->key());

        Http::assertSent(function ($request) {
            $header = $request->header(Signature::HEADER)[0] ?? '';

            $this->assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $header);

            parse_str(str_replace(',', '&', $header), $parts);

            $expected = Signature::digest(
                $request->body(),          // what actually went on the wire
                self::SECRET,
                (int) $parts['t'],
            );

            $this->assertSame(
                $expected,
                $parts['v1'],
                'The signature must cover the body as transmitted, byte for byte.'
            );

            // And the body really does carry the awkward characters, so the
            // assertion above is not passing over an empty string.
            $this->assertStringContainsString('09/2026', $request->body());
            $this->assertStringContainsString('₹', $request->body());

            return true;
        });
    }

    /**
     * A GET has no body, and the separator stays.
     *
     * `"<t>."` and not `"<t>"`. The platform concatenates the timestamp, a full
     * stop and `$request->getContent()`, which for a GET is the empty string -
     * so dropping the separator produces a signature that is refused on every
     * read while every write succeeds, which is a confusing place to start
     * debugging.
     */
    #[Test]
    public function a_read_signs_the_empty_body(): void
    {
        $this->signing();

        Http::fake(['*' => Http::response(['data' => [], 'meta' => []], 200)]);

        $this->ledger()->invoices()->list();

        Http::assertSent(function ($request) {
            $header = $request->header(Signature::HEADER)[0] ?? '';

            parse_str(str_replace(',', '&', $header), $parts);

            $this->assertSame('', $request->body());
            $this->assertSame(
                hash_hmac('sha256', $parts['t'].'.', self::SECRET),
                $parts['v1'],
            );

            return true;
        });
    }

    /**
     * No secret, no header.
     *
     * Sending a signature computed with an empty secret would be worse than
     * sending none: the platform would compare it against the real one and
     * answer "the signature does not match the payload", which sends somebody
     * to examine their body serialisation rather than the blank line in their
     * own .env.
     */
    #[Test]
    public function nothing_is_signed_when_no_secret_is_configured(): void
    {
        config(['core-accounting.signing_secret' => null]);

        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->ledger()->invoices()->create(4, $this->lines(), $this->key());

        Http::assertSent(fn ($request) => $request->header(Signature::HEADER) === []);
    }

    /**
     * A retry re-signs but does not re-key.
     *
     * The timestamp lives inside the signed string, so a retry a second later
     * needs a fresh signature or it is refused for being stale. The idempotency
     * key must NOT change - that is the whole mechanism by which the retry
     * replays the first write instead of making a second invoice.
     */
    #[Test]
    public function a_retry_is_signed_again_but_keeps_its_idempotency_key(): void
    {
        $this->signing();

        Http::fake(['*' => Http::response(['error' => ['code' => 'unavailable']], 503)]);

        try {
            $this->ledger()->invoices()->create(4, $this->lines(), $this->key());
        } catch (\Throwable) {
            // The failure is the point; what the attempts carried is the test.
        }

        $signatures = [];
        $keys = [];

        Http::assertSent(function ($request) use (&$signatures, &$keys) {
            $signatures[] = $request->header(Signature::HEADER)[0] ?? '';
            $keys[] = $request->header(Connection::HEADER_IDEMPOTENCY)[0] ?? '';

            return true;
        });

        $this->assertCount(2, $signatures, 'The configured retry count is two attempts.');
        $this->assertSame(['hrms-payroll-run-88', 'hrms-payroll-run-88'], $keys);

        foreach ($signatures as $signature) {
            $this->assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $signature);
        }
    }

    // -----------------------------------------------------------------
    // What a refusal tells you
    // -----------------------------------------------------------------

    /**
     * Three different problems wear HTTP 403, and they are fixed by different
     * people. A caller that cannot tell them apart shows the wrong instruction
     * to whoever is looking at the screen.
     */
    #[Test]
    public function an_unsigned_request_is_refused_with_the_setting_to_change(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 'forbidden',
                'message' => 'This credential requires a signed request. Send X-Signature: t=<unix>,v1=<hmac>.',
            ],
        ], 403)]);

        try {
            $this->ledger()->invoices()->create(4, $this->lines(), $this->key());
            $this->fail('Expected the platform to refuse an unsigned request.');
        } catch (PermissionDenied $e) {
            $this->assertTrue($e->needsSignature());
            $this->assertFalse($e->signatureDidNotMatch());
            $this->assertStringContainsString('CORE_ACCOUNTING_SIGNING_SECRET', $e->getMessage());
        }
    }

    #[Test]
    public function a_drifted_clock_is_named_as_a_clock_and_not_a_wrong_secret(): void
    {
        $this->signing();

        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 'forbidden',
                'message' => 'The request signature timestamp is outside the accepted window.',
            ],
        ], 403)]);

        try {
            $this->ledger()->invoices()->create(4, $this->lines(), $this->key());
            $this->fail('Expected a refusal.');
        } catch (PermissionDenied $e) {
            $this->assertTrue($e->signatureExpired());
            $this->assertStringContainsString('NTP', $e->getMessage());
            $this->assertStringContainsString('300 seconds', $e->getMessage());
        }
    }

    #[Test]
    public function a_mismatched_signature_points_at_the_body_and_not_the_secret(): void
    {
        $this->signing();

        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 'forbidden',
                'message' => 'The request signature does not match the payload.',
            ],
        ], 403)]);

        try {
            $this->ledger()->invoices()->create(4, $this->lines(), $this->key());
            $this->fail('Expected a refusal.');
        } catch (PermissionDenied $e) {
            $this->assertTrue($e->signatureDidNotMatch());
            $this->assertStringContainsString('serialised twice', $e->getMessage());
        }
    }

    /**
     * A scope refusal is still a scope refusal.
     *
     * The three branches above must not swallow the ordinary case, which is
     * what a cluster of `str_contains` on one message tends to do.
     */
    #[Test]
    public function an_ordinary_permission_refusal_is_unchanged(): void
    {
        $this->signing();

        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 'forbidden',
                'message' => 'This application may not post invoices.',
                'details' => ['permission' => 'invoice.post'],
            ],
        ], 403)]);

        try {
            $this->ledger()->invoices()->create(4, $this->lines(), $this->key());
            $this->fail('Expected a refusal.');
        } catch (PermissionDenied $e) {
            $this->assertFalse($e->needsSignature());
            $this->assertStringContainsString('Grant "invoice.post"', $e->getMessage());
            $this->assertStringNotContainsString('CORE_ACCOUNTING_SIGNING_SECRET', $e->getMessage());
        }
    }
}
