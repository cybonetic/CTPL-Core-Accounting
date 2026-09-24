<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Http;

/**
 * The `X-Signature` header, for credentials that require signed requests.
 *
 * ---
 *
 * **What it proves, and what it does not.**
 *
 * The three application headers prove *who is calling*. The signature proves
 * *that this body is the body that caller sent* - it is what stops something
 * holding a stolen key from altering an invoice in transit. They are separate
 * questions and the platform asks both; the signature is on top of the
 * credential, never instead of it.
 *
 * ---
 *
 * **The rule that matters: sign the bytes you send.**
 *
 * The signed string is `"<unix timestamp>.<raw request body>"`, and the
 * platform recomputes it from `$request->getContent()` - the literal bytes that
 * arrived. So the body must be serialised ONCE, signed, and then transmitted
 * unchanged.
 *
 * Handing an array to an HTTP client and separately calling `json_encode()` on
 * the same array to sign it is the classic way to get this wrong: the two
 * encoders disagree about escaped slashes, unicode, or the order of keys, the
 * bytes differ by one character, and every request is rejected with "the
 * request signature does not match the payload" while the payload looks
 * perfectly correct in a log. `Connection` therefore encodes the body itself
 * and sends that exact string.
 *
 * A GET has no body, so the signed string is `"<timestamp>."` - the separator
 * stays.
 */
final class Signature
{
    public const HEADER = 'X-Signature';

    /**
     * How far out of step with the platform a clock may be.
     *
     * The platform's own tolerance, stated here so a caller can report a
     * refusal in terms of the thing that actually caused it. Mirrored rather
     * than negotiated: the value is not discoverable over the wire, and a
     * client that guessed a wider one would simply have its requests refused.
     */
    public const TOLERANCE_SECONDS = 300;

    /**
     * The header value for a body, at a moment.
     *
     * `$timestamp` is injectable so a test can pin it. Nothing in ordinary use
     * passes it - a signature timestamp that is not the current time is a
     * signature that will be refused.
     */
    public static function header(string $body, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf('t=%d,v1=%s', $timestamp, self::digest($body, $secret, $timestamp));
    }

    /** HMAC-SHA256 over "<timestamp>.<body>", hex. */
    public static function digest(string $body, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
