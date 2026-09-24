<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Crypto;

use Ctpl\CoreAccounting\Exceptions\EncryptionFailed;

/**
 * The Core Accounting public key this application encrypts to.
 *
 * ---
 *
 * **It comes from configuration, and from nowhere else.**
 *
 * An administrator generates the key pair on this application's screen in the
 * Core Accounting back office and hands over the public half - as a bare key or
 * as the self-signed certificate over it, whichever came out of the export
 * archive. That delivery IS the out-of-band step: the key arrived by a route
 * somebody chose, not over the wire from an endpoint.
 *
 * So there is no fetching here, and no thumbprint to pin. An earlier version of
 * this class fetched the key from `/api/v1/crypto/public-key` and then demanded
 * a pinned SHA-512 to check it against, because a key pulled over a channel
 * somebody can interfere with is a key somebody can substitute - Core
 * Accounting's certificates are self-signed, so nothing else would catch it.
 *
 * That machinery existed to make fetching safe. Given a key already in hand it
 * is worse than useless: pinning a thumbprint of the key you are holding is
 * checking a value against itself, and it is one more thing to keep in step
 * through a rotation. It was removed.
 *
 * ---
 *
 * **A certificate and a bare key both work, and the `kid` is the same either
 * way.**
 *
 * That is not a convenience, it is a correctness requirement. Core Accounting
 * identifies its keys by the SHA-256 thumbprint of the **public key** - the DER
 * SubjectPublicKeyInfo - and that is what the `kid` in an envelope has to
 * carry. Hashing a certificate instead produces a different digest entirely, so
 * an envelope built that way would name a key the platform cannot find. It
 * would still usually work, by falling back to whatever key is current, and it
 * would break precisely during a rotation - when the fallback is the wrong key
 * and the right one is sitting there unmatched.
 *
 * So a certificate is opened and the key inside it is what gets hashed.
 */
final class PlatformKey
{
    private ?string $pem = null;

    private ?string $kid = null;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config) {}

    /** The public key to encrypt to, as PEM, extracted from a certificate if that is what was given. */
    public function pem(): string
    {
        $this->resolve();

        return (string) $this->pem;
    }

    /** The `kid` to put in the envelope, so the platform knows which of its keys opened it. */
    public function kid(): string
    {
        $this->resolve();

        return (string) $this->kid;
    }

    // -----------------------------------------------------------------

    private function resolve(): void
    {
        if ($this->pem !== null) {
            return;
        }

        $configured = $this->read();

        if ($configured === '') {
            throw new EncryptionFailed(
                'Encryption is switched on but this application has no Core Accounting public key to '
                .'encrypt to. Set CORE_ACCOUNTING_PLATFORM_KEY to the key an administrator gave you, '
                .'or CORE_ACCOUNTING_PLATFORM_KEY_PATH to a file holding it. Both a bare public key '
                .'and the self-signed certificate over it are accepted - they are in the export '
                .'archive on this application\'s screen in the back office.'
            );
        }

        if (str_contains($configured, 'PRIVATE KEY')) {
            /*
             * Named for what it is rather than failing as "unreadable". A
             * private key pasted into a public-key setting is a bad afternoon
             * either way, and the message is the only chance to say that it
             * must now be treated as compromised.
             */
            throw new EncryptionFailed(
                'That is a PRIVATE key, and it has been put where a public key belongs. Nothing has '
                .'been sent. If this is Core Accounting\'s private key it should never have left the '
                .'platform and must be treated as compromised; if it is this application\'s own, it '
                .'belongs in CORE_ACCOUNTING_PRIVATE_KEY_PATH instead.'
            );
        }

        $this->pem = $this->publicKeyOf($configured);
        $this->kid = trim((string) ($this->config['kid'] ?? '')) ?: $this->thumbprint($this->pem);
    }

    /** The configured key, inline or from a file. */
    private function read(): string
    {
        $inline = trim((string) ($this->config['public_key'] ?? ''));

        if ($inline !== '') {
            return $inline;
        }

        $path = trim((string) ($this->config['public_key_path'] ?? ''));

        if ($path === '') {
            return '';
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new EncryptionFailed(sprintf(
                'The Core Accounting public key file %s does not exist or cannot be read.',
                $path
            ));
        }

        return trim((string) file_get_contents($path));
    }

    /**
     * The public key itself, whether a key or a certificate was given.
     *
     * `openssl_pkey_get_public()` accepts both, so this could be one call. It
     * is written out because the failure messages differ and because the
     * certificate case is the one where somebody has pasted the wrong file.
     */
    private function publicKeyOf(string $pem): string
    {
        if (str_contains($pem, 'BEGIN CERTIFICATE')) {
            $certificate = @openssl_x509_read($pem);

            if ($certificate === false) {
                throw new EncryptionFailed(
                    'That looks like a certificate but could not be read. It should begin '
                    .'"-----BEGIN CERTIFICATE-----" and be the file from the export archive, unmodified.'
                );
            }

            $key = @openssl_pkey_get_public($certificate);
            $details = $key === false ? [] : (@openssl_pkey_get_details($key) ?: []);

            if (! isset($details['key'])) {
                throw new EncryptionFailed('That certificate has no readable public key in it.');
            }

            return (string) $details['key'];
        }

        $key = @openssl_pkey_get_public($pem);

        if ($key === false) {
            throw new EncryptionFailed(
                'That is neither a certificate nor a public key. A public key begins '
                .'"-----BEGIN PUBLIC KEY-----"; a certificate begins "-----BEGIN CERTIFICATE-----".'
            );
        }

        $details = @openssl_pkey_get_details($key) ?: [];

        // Normalised through openssl rather than used as pasted, so that a
        // stray blank line or CRLF endings cannot change the bytes that get
        // hashed for the kid.
        return (string) ($details['key'] ?? $pem);
    }

    /**
     * SHA-256 over the DER SubjectPublicKeyInfo, colon-grouped uppercase hex.
     *
     * Exactly what Core Accounting computes for its own keys, and therefore
     * exactly what an envelope's `kid` has to carry. Also what
     * `openssl pkey -pubin -outform DER | openssl dgst -sha256 -c` prints, so
     * it can be checked by hand against the value on the application's screen.
     */
    private function thumbprint(string $publicKeyPem): string
    {
        $body = preg_replace('/-----(BEGIN|END)[^-]+-----|\s+/', '', $publicKeyPem);
        $der = base64_decode((string) $body, true);

        if ($der === false) {
            throw new EncryptionFailed('That public key is not valid base64.');
        }

        return strtoupper(implode(':', str_split(hash('sha256', $der), 2)));
    }
}
