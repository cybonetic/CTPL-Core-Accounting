<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Crypto;

use Ctpl\CoreAccounting\Exceptions\EncryptionFailed;

/**
 * RSA-OAEP padding with a chosen hash, because PHP will not let you choose one.
 *
 * ---
 *
 * **Why this file exists at all, stated before anything else.**
 *
 * Hand-writing cryptography is normally a bad idea and this is the argument for
 * why it is not one here.
 *
 * `openssl_public_encrypt()` offers exactly three paddings, and its OAEP is
 * hard-wired to SHA-1 for both the label hash and MGF1. There is no parameter.
 * `phpseclib`, which does expose the choice, cannot be installed in this
 * environment - `codeload.github.com` is denied by the egress proxy.
 *
 * What is written here is **only the padding**: a deterministic byte shuffle
 * specified in RFC 8017 §7.1, built out of a hash function. The actual
 * primitive - modular exponentiation on a 4096-bit modulus - is still
 * OpenSSL's, reached through `OPENSSL_NO_PADDING`. No key material is
 * generated, stored or compared here.
 *
 * And it is **verified against OpenSSL's own implementation, in both
 * directions**: `tools/verify-core.php` encrypts with this code and decrypts
 * with the `openssl` command line, then encrypts with the command line and
 * decrypts with this code. A padding implementation that only round-trips
 * against itself proves nothing at all - it would be equally happy if the
 * encoding were wrong in a way it reversed consistently.
 *
 * ---
 *
 * **On the choice of hash.**
 *
 * OAEP's security does not rest on collision resistance, so SHA-1 inside OAEP
 * is not the weakness it would be in a signature. The reason to move off it is
 * interoperability and the ability to say plainly which algorithm is in use:
 * a .NET or Java integration configures `RSA-OAEP-256` or `RSA-OAEP-512` from
 * its own standard library, and neither of those is what PHP would have sent.
 *
 * ---
 *
 * **This is a deliberate copy of the platform's own implementation**, not a
 * shared dependency, because an SDK that pulled the platform in as a package
 * would drag a Laravel application's entire module tree with it for the sake of
 * three functions.
 *
 * A copy is a thing that can drift, so it is pinned by test vectors rather than
 * by hope: `OaepInteropTest` encrypts with this code and decrypts with the
 * `openssl` command line, and decrypts fixtures the platform produced. A
 * padding tested only against its own inverse passes every time while being
 * wrong - three deliberate breaks were introduced against the platform's copy
 * and all three round-tripped perfectly against themselves.
 */
final class Oaep
{
    public const SHA256 = 'sha256';

    public const SHA512 = 'sha512';

    /**
     * OAEP-encode a message, then raw-RSA it with the public key.
     *
     * @param  string  $publicKeyPem  PEM SubjectPublicKeyInfo
     */
    public static function encrypt(string $message, string $publicKeyPem, string $hash = self::SHA512): string
    {
        $key = openssl_pkey_get_public($publicKeyPem);

        if ($key === false) {
            throw new EncryptionFailed('That is not a readable public key.');
        }

        $details = openssl_pkey_get_details($key);
        $modulusLength = (int) ($details['bits'] ?? 0) / 8;

        $encoded = self::encode($message, $modulusLength, $hash);

        // NO_PADDING: the padding is what this class just did. OpenSSL is doing
        // the modular exponentiation and nothing else.
        if (! openssl_public_encrypt($encoded, $cipher, $key, OPENSSL_NO_PADDING)) {
            throw new EncryptionFailed('The key encapsulation failed: '.openssl_error_string());
        }

        return $cipher;
    }

    /** Raw-RSA with the private key, then OAEP-decode. */
    public static function decrypt(string $cipher, string $privateKeyPem, string $hash = self::SHA512): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new EncryptionFailed('That is not a readable private key.');
        }

        if (! openssl_private_decrypt($cipher, $encoded, $key, OPENSSL_NO_PADDING)) {
            throw new EncryptionFailed('The key decapsulation failed.');
        }

        $details = openssl_pkey_get_details($key);

        return self::decode($encoded, (int) ($details['bits'] ?? 0) / 8, $hash);
    }

    /**
     * EME-OAEP encoding. RFC 8017 §7.1.1, steps 2(a) to 2(i).
     *
     * ```
     * EM = 0x00 || maskedSeed || maskedDB
     * DB = lHash || PS(zeros) || 0x01 || M
     * ```
     */
    public static function encode(string $message, int $modulusLength, string $hash): string
    {
        $hashLength = strlen(hash($hash, '', true));
        $maximum = $modulusLength - 2 * $hashLength - 2;

        if (strlen($message) > $maximum) {
            // The constraint that decides the whole design of anything built on
            // this: a 4096-bit key with SHA-512 OAEP carries 382 bytes. Not a
            // document - a key. Everything larger goes through a symmetric
            // cipher whose key is what this wraps.
            throw new EncryptionFailed(sprintf(
                'RSA-OAEP with a %d-bit key and %s carries at most %d bytes, and %d were offered. '.
                'A payload is encrypted with a symmetric key; only that key is wrapped here.',
                $modulusLength * 8,
                strtoupper($hash),
                $maximum,
                strlen($message)
            ));
        }

        // The label is empty, which is the universal default. lHash is the hash
        // OF the empty label, not an empty string.
        $labelHash = hash($hash, '', true);
        $padding = str_repeat("\x00", $maximum - strlen($message));

        $dataBlock = $labelHash.$padding."\x01".$message;
        $seed = random_bytes($hashLength);

        $maskedDataBlock = $dataBlock ^ self::mgf1($seed, strlen($dataBlock), $hash);
        $maskedSeed = $seed ^ self::mgf1($maskedDataBlock, $hashLength, $hash);

        return "\x00".$maskedSeed.$maskedDataBlock;
    }

    /**
     * EME-OAEP decoding, RFC 8017 §7.1.2.
     *
     * **Every failure is reported identically and only after the whole message
     * has been examined.** Distinguishing "the leading byte was not zero" from
     * "the padding separator was missing" is the shape of Manger's attack on
     * OAEP: each distinct answer is an oracle that recovers the plaintext one
     * query at a time. So the checks accumulate into one flag and the function
     * takes the same path either way.
     */
    public static function decode(string $encoded, int $modulusLength, string $hash): string
    {
        $hashLength = strlen(hash($hash, '', true));

        // A short ciphertext is left-padded rather than rejected early, so that
        // a wrong length is not itself distinguishable.
        $encoded = str_pad($encoded, $modulusLength, "\x00", STR_PAD_LEFT);

        $leadingByte = $encoded[0];
        $maskedSeed = substr($encoded, 1, $hashLength);
        $maskedDataBlock = substr($encoded, 1 + $hashLength);

        $seed = $maskedSeed ^ self::mgf1($maskedDataBlock, $hashLength, $hash);
        $dataBlock = $maskedDataBlock ^ self::mgf1($seed, strlen($maskedDataBlock), $hash);

        $labelHash = substr($dataBlock, 0, $hashLength);
        $rest = substr($dataBlock, $hashLength);

        $bad = ! hash_equals(hash($hash, '', true), $labelHash);
        $bad = $bad || $leadingByte !== "\x00";

        // Walk the whole remainder whatever is found, so the time taken does
        // not say where the separator was.
        $separatorAt = -1;

        for ($i = 0, $length = strlen($rest); $i < $length; $i++) {
            if ($rest[$i] === "\x01" && $separatorAt === -1) {
                $separatorAt = $i;

                continue;
            }

            if ($separatorAt === -1 && $rest[$i] !== "\x00") {
                $bad = true;
            }
        }

        if ($bad || $separatorAt === -1) {
            throw new EncryptionFailed('The encrypted key could not be decoded.');
        }

        return substr($rest, $separatorAt + 1);
    }

    /**
     * MGF1, RFC 8017 B.2.1: a hash stretched to any length by counter.
     *
     * @param  int  $length  bytes wanted
     */
    public static function mgf1(string $seed, int $length, string $hash): string
    {
        $output = '';

        for ($counter = 0; strlen($output) < $length; $counter++) {
            // The counter is four bytes, big-endian. Getting this wrong
            // produces a mask that is self-consistent and wrong, which is
            // exactly why this is checked against OpenSSL rather than itself.
            $output .= hash($hash, $seed.pack('N', $counter), true);
        }

        return substr($output, 0, $length);
    }

    /** How many bytes of message a key of this size carries. */
    public static function capacity(int $bits, string $hash): int
    {
        return (int) ($bits / 8) - 2 * strlen(hash($hash, '', true)) - 2;
    }
}
