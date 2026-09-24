<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Crypto;

use Ctpl\CoreAccounting\Exceptions\EncryptionFailed;

/**
 * The encrypted request body, and the encrypted reply.
 *
 * ---
 *
 * **Hybrid, because RSA does not encrypt messages.**
 *
 * RSA encrypts numbers below its modulus: a 4096-bit key under OAEP-SHA-512
 * carries 382 bytes. An invoice with twelve lines is kilobytes. So a fresh
 * AES-256 key is invented per message, the payload is encrypted with it in GCM,
 * and only that 32-byte key is wrapped with RSA. The guarantee is unchanged -
 * only the private-key holder can unwrap the key that opens the payload - and a
 * fresh key per message means two identical invoices do not produce identical
 * ciphertext.
 *
 * ---
 *
 * **The wire contract, which is the platform's and not ours to vary.**
 *
 * ```json
 * {
 *   "v": 1, "alg": "RSA-OAEP-512", "enc": "A256GCM",
 *   "kid": "53:3E:...", "key": "...", "iv": "...", "ct": "...", "tag": "..."
 * }
 * ```
 *
 * base64url with no padding, a 12-byte IV, a 16-byte tag, and **no additional
 * authenticated data** - not the headers, not the envelope, the empty string.
 * Field order is irrelevant; the names are not.
 *
 * `alg` and `enc` are asserted on the way in and never obeyed. An envelope
 * naming something weaker is refused rather than honoured, which is the oldest
 * mistake in this area and has been made by better-reviewed libraries than this
 * one.
 */
final class Envelope
{
    public const VERSION = 1;

    public const ALG = 'RSA-OAEP-512';

    public const ENC = 'A256GCM';

    /** Set by the platform on an encrypted reply. */
    public const RESPONSE_HEADER = 'X-CTPL-ENCRYPTED';

    private const CIPHER = 'aes-256-gcm';

    private const IV_BYTES = 12;

    private const TAG_BYTES = 16;

    /**
     * Seal a payload to the platform's public key.
     *
     * @param  string  $keyId  the `kid` of the key being encrypted to. Sent back
     *                         unchanged so the platform knows which of its keys
     *                         to open this with - it holds one per application
     *                         and keeps superseded ones alive through a
     *                         rotation.
     * @return array<string,mixed>
     */
    public static function seal(string $plaintext, string $recipientKeyPem, string $keyId): array
    {
        $contentKey = random_bytes(32);
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $contentKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES
        );

        if ($ciphertext === false) {
            throw new EncryptionFailed('The payload could not be encrypted: '.(openssl_error_string() ?: 'no reason given'));
        }

        return [
            'v' => self::VERSION,
            'alg' => self::ALG,
            'enc' => self::ENC,
            'kid' => $keyId,
            'key' => self::b64(Oaep::encrypt($contentKey, $recipientKeyPem, Oaep::SHA512)),
            'iv' => self::b64($iv),
            'ct' => self::b64($ciphertext),
            'tag' => self::b64($tag),
        ];
    }

    /**
     * Open a reply addressed to this application.
     *
     * @param  array<string,mixed>  $envelope
     */
    public static function open(array $envelope, string $privateKeyPem): string
    {
        self::assertShape($envelope);

        $contentKey = Oaep::decrypt(self::unb64((string) $envelope['key']), $privateKeyPem, Oaep::SHA512);

        if (strlen($contentKey) !== 32) {
            throw new EncryptionFailed('The wrapped content key is not an AES-256 key.');
        }

        $plaintext = openssl_decrypt(
            self::unb64((string) $envelope['ct']),
            self::CIPHER,
            $contentKey,
            OPENSSL_RAW_DATA,
            self::unb64((string) $envelope['iv']),
            self::unb64((string) $envelope['tag']),
            ''
        );

        if ($plaintext === false) {
            /*
             * GCM failing its tag means the ciphertext was altered, or the
             * wrong key opened it. Said as one thing, because distinguishing
             * them tells an attacker which half of the envelope to keep working
             * on - and because from here they have the same remedy.
             */
            throw new EncryptionFailed(
                'The reply could not be decrypted: it was altered in transit, or it was encrypted to a '
                .'different key than the one this application holds. If the key was replaced recently, '
                .'upload the new public half to Core Accounting.'
            );
        }

        return $plaintext;
    }

    /** Does this look like one of the platform's envelopes at all? */
    public static function looksLikeOne(mixed $payload): bool
    {
        return is_array($payload)
            && isset($payload['alg'], $payload['enc'], $payload['key'], $payload['iv'], $payload['ct'], $payload['tag']);
    }

    /** @param array<string,mixed> $envelope */
    private static function assertShape(array $envelope): void
    {
        foreach (['alg', 'enc', 'key', 'iv', 'ct', 'tag'] as $field) {
            if (! isset($envelope[$field]) || ! is_string($envelope[$field])) {
                throw new EncryptionFailed(sprintf('The encrypted reply is missing "%s".', $field));
            }
        }

        if ($envelope['alg'] !== self::ALG || $envelope['enc'] !== self::ENC) {
            throw new EncryptionFailed(sprintf(
                'This SDK accepts %s with %s. The reply names %s with %s, and an algorithm a message '
                .'chooses for itself is not one this SDK will use.',
                self::ALG,
                self::ENC,
                (string) $envelope['alg'],
                (string) $envelope['enc']
            ));
        }
    }

    /** base64url without padding - the encoding JWE uses. */
    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function unb64(string $encoded): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($decoded === false) {
            throw new EncryptionFailed('An envelope field is not valid base64url.');
        }

        return $decoded;
    }
}
