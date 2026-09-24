<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Tests\Feature;

use Ctpl\CoreAccounting\CoreAccounting;
use Ctpl\CoreAccounting\Crypto\Envelope as Sealed;
use Ctpl\CoreAccounting\Crypto\Oaep;
use Ctpl\CoreAccounting\Exceptions\EncryptionFailed;
use Ctpl\CoreAccounting\Http\Signature;
use Ctpl\CoreAccounting\Support\IdempotencyKey;
use Ctpl\CoreAccounting\Tests\TestCase;
use Ctpl\CoreAccounting\Values\InvoiceLine;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Payload encryption, on top of TLS.
 *
 * ---
 *
 * **Two assertions here carry the weight.**
 *
 * `the_padding_agrees_with_openssl_in_both_directions` is the first. This SDK
 * implements RFC 8017 padding by hand, because PHP's `openssl_public_encrypt()`
 * is hard-wired to SHA-1 OAEP and the platform requires SHA-512. Hand-written
 * padding tested only against its own inverse round-trips perfectly while being
 * wrong - the platform's copy had three deliberate breaks introduced and every
 * one of them passed a self-round-trip. So it is checked against OpenSSL's own
 * implementation, in both directions.
 *
 * `a_fetched_key_is_refused_unless_somebody_has_checked_it` is the second.
 * Core Accounting's certificates are self-signed, so there is no chain and the
 * thumbprint is the whole trust decision. A client that fetches a key and uses
 * it has made that decision silently and wrongly.
 */
class EncryptionTest extends TestCase
{
    /**
     * One pair per process. 2048 bits rather than the 4096 the platform
     * demands: this exercises the padding and the envelope, not the key size,
     * and 4096 generation is seconds rather than milliseconds.
     *
     * @return array{0:string,1:string}
     */
    private static function pair(string $slot): array
    {
        static $pairs = [];

        if (! isset($pairs[$slot])) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            $pairs[$slot] = [$private, (string) openssl_pkey_get_details($key)['key']];
        }

        return $pairs[$slot];
    }

    private static function thumbprint(string $publicPem, string $algorithm = 'sha512'): string
    {
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $publicPem), true);

        return strtoupper(implode(':', str_split(hash($algorithm, (string) $der), 2)));
    }

    /** A self-signed certificate over a key, the shape the platform now issues. */
    private static function certificateFor(string $privatePem): string
    {
        $csr = openssl_csr_new(
            ['commonName' => 'CTPL Core Accounting', 'organizationName' => 'Cybonetic Technologies Private Limited'],
            $privatePem,
            ['digest_alg' => 'sha512'],
        );

        openssl_x509_export(openssl_csr_sign($csr, null, $privatePem, 365, ['digest_alg' => 'sha512']), $pem);

        return $pem;
    }

    /** @return array<int, InvoiceLine> */
    private function lines(): array
    {
        return [InvoiceLine::make('Payroll ₹ 09/2026', '25000.00', taxCodeId: 7, costCentreId: 1)];
    }

    private function ledger(): CoreAccounting
    {
        return $this->app->make(CoreAccounting::class);
    }

    /** @param array<string,mixed> $extra */
    private function encrypting(array $extra = []): void
    {
        [, $platformPublic] = self::pair('platform');
        [$appPrivate] = self::pair('app');

        config(['core-accounting.encryption' => array_merge([
            'enabled' => true,
            'public_key' => $platformPublic,
            'private_key' => $appPrivate,
        ], $extra)]);
    }

    // -----------------------------------------------------------------
    // The padding
    // -----------------------------------------------------------------

    /**
     * Against OpenSSL, not against itself.
     */
    #[Test]
    public function the_padding_agrees_with_openssl_in_both_directions(): void
    {
        if (trim((string) shell_exec('command -v openssl')) === '') {
            $this->markTestSkipped('The openssl command line is not available to check against.');
        }

        [$private, $public] = self::pair('interop');

        $dir = sys_get_temp_dir().'/oaep-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        file_put_contents($dir.'/private.pem', $private);
        file_put_contents($dir.'/public.pem', $public);

        $message = random_bytes(32);
        $opts = '-pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha512 -pkeyopt rsa_mgf1_md:sha512';

        // 1. This code encrypts; openssl decrypts.
        file_put_contents($dir.'/ours.bin', Oaep::encrypt($message, $public, Oaep::SHA512));

        shell_exec(sprintf(
            'openssl pkeyutl -decrypt -inkey %s/private.pem %s -in %s/ours.bin -out %s/ours.txt 2>/dev/null',
            $dir, $opts, $dir, $dir
        ));

        $this->assertFileExists($dir.'/ours.txt', 'OpenSSL could not decrypt what this padding produced.');

        $this->assertSame(
            bin2hex($message),
            bin2hex((string) file_get_contents($dir.'/ours.txt')),
            'OpenSSL must be able to read what this padding produced.'
        );

        // 2. openssl encrypts; this code decrypts.
        file_put_contents($dir.'/plain.bin', $message);

        shell_exec(sprintf(
            'openssl pkeyutl -encrypt -pubin -inkey %s/public.pem %s -in %s/plain.bin -out %s/theirs.bin 2>/dev/null',
            $dir, $opts, $dir, $dir
        ));

        $this->assertSame(
            bin2hex($message),
            bin2hex(Oaep::decrypt((string) file_get_contents($dir.'/theirs.bin'), $private, Oaep::SHA512)),
            'This padding must be able to read what OpenSSL produced.'
        );

        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }

    /**
     * The constraint that decides the whole design: a key wraps a key, never a
     * document.
     */
    #[Test]
    public function rsa_refuses_a_payload_and_says_why(): void
    {
        [, $public] = self::pair('platform');

        $this->expectException(EncryptionFailed::class);
        $this->expectExceptionMessageMatches('/carries at most \d+ bytes/');

        Oaep::encrypt(random_bytes(400), $public, Oaep::SHA512);
    }

    // -----------------------------------------------------------------
    // The envelope on the wire
    // -----------------------------------------------------------------

    #[Test]
    public function a_write_goes_out_sealed_to_the_platform_key(): void
    {
        $this->encrypting();

        [$platformPrivate] = self::pair('platform');

        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->ledger()->invoices()->create(4, $this->lines(), IdempotencyKey::for('hrms', 'run-1'));

        Http::assertSent(function ($request) use ($platformPrivate) {
            $envelope = json_decode($request->body(), true);

            // The platform's contract, field for field.
            $this->assertSame(1, $envelope['v']);
            $this->assertSame('RSA-OAEP-512', $envelope['alg']);
            $this->assertSame('A256GCM', $envelope['enc']);
            $this->assertNotEmpty($envelope['kid']);

            // base64url, no padding.
            foreach (['key', 'iv', 'ct', 'tag'] as $field) {
                $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $envelope[$field]);
            }

            $this->assertSame(12, strlen(Sealed::unb64($envelope['iv'])));
            $this->assertSame(16, strlen(Sealed::unb64($envelope['tag'])));

            // The plaintext is gone from the wire...
            $this->assertStringNotContainsString('25000.00', $request->body());

            // ...and the holder of the private half gets it back.
            $opened = json_decode(Sealed::open($envelope, $platformPrivate), true);
            $this->assertSame('25000.00', $opened['lines'][0]['unit_price']);
            $this->assertSame(4, $opened['customer_id']);

            return true;
        });
    }

    /**
     * Encrypted first, signed second.
     *
     * The platform verifies the signature BEFORE it decrypts, so the signature
     * has to cover the ciphertext. Signing the plaintext and then encrypting
     * would produce a signature over bytes the platform never sees, and every
     * request would be refused with a message about the payload rather than
     * about the order.
     */
    #[Test]
    public function the_signature_covers_the_ciphertext(): void
    {
        $this->encrypting();
        config(['core-accounting.signing_secret' => 'sk_signing_2f9d4c7b']);

        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->ledger()->invoices()->create(4, $this->lines(), IdempotencyKey::for('hrms', 'run-1'));

        Http::assertSent(function ($request) {
            $header = $request->header(Signature::HEADER)[0] ?? '';
            parse_str(str_replace(',', '&', $header), $parts);

            // Recomputed from the body as transmitted, which is the envelope.
            $this->assertSame(
                Signature::digest($request->body(), 'sk_signing_2f9d4c7b', (int) $parts['t']),
                $parts['v1'],
            );

            $this->assertTrue(Sealed::looksLikeOne(json_decode($request->body(), true)));

            return true;
        });
    }

    /** A read has no body, so there is nothing to seal. */
    #[Test]
    public function a_read_is_not_encrypted(): void
    {
        $this->encrypting();

        Http::fake(['*' => Http::response(['data' => [], 'meta' => []], 200)]);

        $this->ledger()->invoices()->list();

        Http::assertSent(fn ($request) => $request->body() === '');
    }

    // -----------------------------------------------------------------
    // The reply
    // -----------------------------------------------------------------

    #[Test]
    public function an_encrypted_reply_is_opened_and_its_status_kept(): void
    {
        $this->encrypting();

        [, $appPublic] = self::pair('app');

        $sealed = Sealed::seal(
            json_encode(['error' => ['code' => 'accounting_rule_violation', 'message' => 'Line 1 needs a Cost Centre.']]),
            $appPublic,
            'AA:BB',
        );

        Http::fake(['*' => Http::response($sealed, 422, [Sealed::RESPONSE_HEADER => Sealed::ALG])]);

        try {
            $this->ledger()->invoices()->create(4, $this->lines(), IdempotencyKey::for('hrms', 'run-1'));
            $this->fail('Expected the decrypted refusal to surface as an exception.');
        } catch (\Ctpl\CoreAccounting\Exceptions\AccountingRuleViolation $e) {
            // Decrypted, and the status survived encryption - 422 and not 200.
            $this->assertStringContainsString('Cost Centre', $e->getMessage());
            $this->assertSame(422, $e->status);
        }
    }

    #[Test]
    public function a_reply_we_cannot_open_names_the_missing_key(): void
    {
        $this->encrypting(['private_key' => null, 'private_key_path' => null]);

        [, $appPublic] = self::pair('app');

        Http::fake(['*' => Http::response(Sealed::seal('{"data":{}}', $appPublic, 'AA:BB'), 200)]);

        $this->expectException(EncryptionFailed::class);
        $this->expectExceptionMessageMatches('/CORE_ACCOUNTING_PRIVATE_KEY_PATH/');

        $this->ledger()->invoices()->create(4, $this->lines(), IdempotencyKey::for('hrms', 'run-1'));
    }

    #[Test]
    public function a_reply_naming_a_weaker_algorithm_is_refused(): void
    {
        $this->encrypting();

        [, $appPublic] = self::pair('app');

        $sealed = Sealed::seal('{"data":{}}', $appPublic, 'AA:BB');
        $sealed['alg'] = 'none';

        Http::fake(['*' => Http::response($sealed, 200)]);

        $this->expectException(EncryptionFailed::class);
        $this->expectExceptionMessageMatches('/chooses for itself/');

        $this->ledger()->invoices()->create(4, $this->lines(), IdempotencyKey::for('hrms', 'run-1'));
    }

    // -----------------------------------------------------------------
    // Where the key comes from
    // -----------------------------------------------------------------

    /**
     * A certificate and the bare key inside it must produce the SAME `kid`.
     *
     * **This is the one that would have bitten silently.** Core Accounting
     * identifies its keys by the SHA-256 thumbprint of the public key's DER,
     * and that is what an envelope's `kid` has to carry. Hashing the
     * certificate instead gives a different digest, so the platform would not
     * find the key named - and it would still work, by falling back to
     * whichever key is current, right up until a rotation makes that fallback
     * the wrong key. The failure would appear weeks later, during the one
     * operation the overlap exists to make safe.
     */
    #[Test]
    public function a_certificate_and_a_bare_key_give_the_same_key_id(): void
    {
        [$platformPrivate, $platformPublic] = self::pair('platform');

        $certificate = self::certificateFor($platformPrivate);

        $fromKey = new \Ctpl\CoreAccounting\Crypto\PlatformKey(['public_key' => $platformPublic]);
        $fromCertificate = new \Ctpl\CoreAccounting\Crypto\PlatformKey(['public_key' => $certificate]);

        $this->assertSame($fromKey->kid(), $fromCertificate->kid());
        $this->assertSame(trim($fromKey->pem()), trim($fromCertificate->pem()));

        // And it is the thumbprint openssl prints, so it can be checked by hand
        // against the value on the application's screen.
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $platformPublic), true);
        $this->assertSame(
            strtoupper(implode(':', str_split(hash('sha256', (string) $der), 2))),
            $fromKey->kid(),
        );
    }

    /** The certificate is accepted end to end, not just by the key reader. */
    #[Test]
    public function a_certificate_can_be_configured_instead_of_a_key(): void
    {
        [$platformPrivate] = self::pair('platform');
        [$appPrivate] = self::pair('app');

        config(['core-accounting.encryption' => [
            'enabled' => true,
            'public_key' => self::certificateFor($platformPrivate),
            'private_key' => $appPrivate,
        ]]);

        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->ledger()->invoices()->create(4, $this->lines(), IdempotencyKey::for('hrms', 'run-1'));

        Http::assertSent(function ($request) use ($platformPrivate) {
            $envelope = json_decode($request->body(), true);
            $this->assertStringContainsString('25000.00', Sealed::open($envelope, $platformPrivate));

            return true;
        });
    }

    /** The key may live in a file rather than an environment variable. */
    #[Test]
    public function the_key_can_come_from_a_file(): void
    {
        [$platformPrivate, $platformPublic] = self::pair('platform');
        [$appPrivate] = self::pair('app');

        $path = sys_get_temp_dir().'/platform-'.bin2hex(random_bytes(5)).'.pem';
        file_put_contents($path, $platformPublic);

        config(['core-accounting.encryption' => [
            'enabled' => true,
            'public_key_path' => $path,
            'private_key' => $appPrivate,
        ]]);

        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->ledger()->invoices()->create(4, $this->lines(), IdempotencyKey::for('hrms', 'run-1'));

        Http::assertSent(fn ($request) => Sealed::looksLikeOne(json_decode($request->body(), true)));

        @unlink($path);
    }

    /**
     * Nothing is fetched. Ever.
     *
     * Asserted rather than assumed, because an earlier version of this SDK did
     * fetch the key and the endpoint still exists. A reintroduced fetch would
     * work in every test above - the key would arrive and the payload would
     * encrypt - and would quietly put the trust decision back on the wire.
     */
    #[Test]
    public function the_key_is_never_fetched_over_the_wire(): void
    {
        $this->encrypting();

        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->ledger()->invoices()->create(4, $this->lines(), IdempotencyKey::for('hrms', 'run-1'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'crypto/public-key'));
    }

    #[Test]
    public function encryption_without_a_key_says_which_setting_is_blank(): void
    {
        [$appPrivate] = self::pair('app');

        config(['core-accounting.encryption' => [
            'enabled' => true,
            'private_key' => $appPrivate,
        ]]);

        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->expectException(EncryptionFailed::class);
        $this->expectExceptionMessageMatches('/CORE_ACCOUNTING_PLATFORM_KEY/');

        $this->ledger()->invoices()->create(4, $this->lines(), IdempotencyKey::for('hrms', 'run-1'));
    }

    /**
     * A private key pasted where the public one belongs is named for what it
     * is. It is the mistake that matters most and the one easiest to make with
     * two key pairs in play.
     */
    #[Test]
    public function a_private_key_in_the_public_setting_is_refused_by_name(): void
    {
        [$platformPrivate] = self::pair('platform');
        [$appPrivate] = self::pair('app');

        config(['core-accounting.encryption' => [
            'enabled' => true,
            'public_key' => $platformPrivate,
            'private_key' => $appPrivate,
        ]]);

        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->expectException(EncryptionFailed::class);
        $this->expectExceptionMessageMatches('/PRIVATE key/');

        $this->ledger()->invoices()->create(4, $this->lines(), IdempotencyKey::for('hrms', 'run-1'));
    }

    /** With encryption off, nothing changes. */
    #[Test]
    public function nothing_is_encrypted_when_it_is_switched_off(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->ledger()->invoices()->create(4, $this->lines(), IdempotencyKey::for('hrms', 'run-1'));

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('25000.00', $request->body());
            $this->assertFalse(Sealed::looksLikeOne(json_decode($request->body(), true)));

            return true;
        });
    }
}
