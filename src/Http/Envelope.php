<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Http;

use Ctpl\CoreAccounting\Exceptions\AccountingRuleViolation;
use Ctpl\CoreAccounting\Exceptions\AuthenticationFailed;
use Ctpl\CoreAccounting\Exceptions\CompanyContextMissing;
use Ctpl\CoreAccounting\Exceptions\Conflict;
use Ctpl\CoreAccounting\Exceptions\CoreAccountingException;
use Ctpl\CoreAccounting\Exceptions\DocumentNotFound;
use Ctpl\CoreAccounting\Exceptions\GenericFailure;
use Ctpl\CoreAccounting\Exceptions\ImmutableDocument;
use Ctpl\CoreAccounting\Exceptions\LedgerUnavailable;
use Ctpl\CoreAccounting\Exceptions\PermissionDenied;
use Ctpl\CoreAccounting\Exceptions\ValidationFailed;
use Illuminate\Http\Client\Response;

/**
 * Turns one HTTP response into either data or the right exception.
 *
 * ---
 *
 * **Branching on `error.code`, never on the message or on the status alone.**
 *
 * The platform documents the code as the contract and the sentence as prose
 * that may be reworded. Two of the codes share HTTP 422 -
 * `validation_failed` and `accounting_rule_violation` - and they mean opposite
 * things to a caller: one is the integrator's payload, the other is a rule of
 * accounting that the payload obeyed the shape of. Mapping on status alone
 * would collapse them, and collapsing them is how "Line 1 requires a Cost
 * Centre" ends up rendered as "please check your input".
 *
 * An unrecognised code falls back to the status, and an unrecognised status
 * falls back to a generic exception rather than to success. Nothing here
 * returns data it did not understand.
 */
final class Envelope
{
    /**
     * @return array{data: mixed, meta: array<string,mixed>, request_id: ?string}
     */
    public static function open(Response $response, ?string $requestedUrl = null): array
    {
        if ($response->status() >= 300 && $response->status() < 400) {
            throw self::redirected($response, $requestedUrl);
        }

        $body = $response->json();

        if (! is_array($body)) {
            /*
             * Not JSON at all. Usually a proxy or a web server answering
             * instead of the application - an HTML 502, a login page from a
             * WAF, a truncated body. Treated as unavailability rather than as a
             * client error, because retrying is exactly the right response.
             */
            throw new LedgerUnavailable(
                sprintf(
                    'The ledger answered %d with a body that is not JSON (%s). Something between this '
                    .'application and Core Accounting answered instead of it.',
                    $response->status(),
                    substr(trim((string) $response->body()), 0, 120) ?: 'empty'
                ),
                status: $response->status(),
            );
        }

        $requestId = isset($body['request_id']) ? (string) $body['request_id'] : null;

        if ($response->successful() && ! isset($body['error'])) {
            return [
                'data' => $body['data'] ?? null,
                'meta' => is_array($body['meta'] ?? null) ? $body['meta'] : [],
                'request_id' => $requestId,
            ];
        }

        throw self::toException($body, $response->status(), $requestId);
    }

    /**
     * A redirect, reported rather than followed.
     *
     * The SDK turns redirect-following off, because the default behaviour of
     * almost every HTTP client - Guzzle included - is to convert a redirected
     * POST into a GET. An invoice create would then arrive at the ledger as a
     * *list* request, answer 200 with a page of somebody else's invoices, and
     * the calling application would carry on believing it had raised one. A
     * write that silently does nothing is the worst failure this SDK can have,
     * so a redirect is a hard, explained stop.
     *
     * It is also not retryable. A redirect is a deployment fact, not a blip.
     */
    private static function redirected(Response $response, ?string $requestedUrl): CoreAccountingException
    {
        $location = (string) $response->header('Location');

        $message = sprintf(
            'Core Accounting answered %d with a redirect to "%s"%s. The SDK does not follow redirects: '
            .'most HTTP clients turn a redirected POST into a GET, which would discard a write instead '
            .'of performing it.',
            $response->status(),
            $location !== '' ? $location : '(no Location header)',
            $requestedUrl !== null ? sprintf(' when asked for "%s"', $requestedUrl) : '',
        );

        if ($requestedUrl !== null && $location !== '' && rtrim($location, '/') === rtrim($requestedUrl, '/')) {
            /*
             * Redirecting to the address that was just requested is a loop, and
             * it has one overwhelmingly common cause. Naming it here saves the
             * afternoon that is otherwise spent reading the application's
             * routes, which are innocent.
             */
            $message .= ' That address is the one just requested, so the ledger is redirecting to itself. '
                .'This is almost always TLS termination: something in front of the application - commonly '
                .'Cloudflare with SSL/TLS set to "Flexible" - reaches it over plain HTTP while the '
                .'application insists on HTTPS, so it redirects for ever. Set the proxy to "Full", and '
                .'have the application trust the proxy so X-Forwarded-Proto is believed.';
        }

        return new GenericFailure($message, errorCode: 'redirected', status: $response->status());
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private static function toException(array $body, int $status, ?string $requestId): CoreAccountingException
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        $code = isset($error['code']) ? (string) $error['code'] : null;
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        $message = isset($error['message'])
            ? (string) $error['message']
            : sprintf('Core Accounting answered %d with no message.', $status);

        $class = match ($code) {
            'unauthenticated' => AuthenticationFailed::class,
            'forbidden' => PermissionDenied::class,
            'company_context_missing' => CompanyContextMissing::class,
            'not_found' => DocumentNotFound::class,
            'conflict' => Conflict::class,
            'validation_failed' => ValidationFailed::class,
            'accounting_rule_violation' => AccountingRuleViolation::class,
            'immutable_record' => ImmutableDocument::class,
            // An unrecognised code - a version of the platform newer than this
            // SDK - still maps somewhere sensible by status.
            default => match (true) {
                $status === 401 => AuthenticationFailed::class,
                $status === 403 => PermissionDenied::class,
                $status === 404 => DocumentNotFound::class,
                $status === 409 => Conflict::class,
                $status === 422 => ValidationFailed::class,
                $status === 429, $status >= 500 => LedgerUnavailable::class,
                default => GenericFailure::class,
            },
        };

        if ($class === PermissionDenied::class && isset($details['permission'])) {
            // The platform names the scope it wanted. Putting it in the message
            // saves the round trip of asking an administrator "which one?".
            $message .= sprintf(' Grant "%s" to this application.', (string) $details['permission']);
        }

        /*
         * A 403 about signing is not a permissions problem, and saying so here
         * saves somebody going to an administrator to ask for a scope they
         * already have.
         *
         * Three different causes wear the same status code, so each gets the
         * instruction that actually fixes it. The env var is named because that
         * is where the answer goes.
         */
        if ($class === PermissionDenied::class) {
            if (str_contains($message, 'X-Signature') || str_contains($message, 'signed request')) {
                $message .= ' Set CORE_ACCOUNTING_SIGNING_SECRET to the signing secret for this '
                    .'application; the SDK signs every request once it is present. An administrator '
                    .'can show it again from the application\'s screen in the back office.';
            } elseif (str_contains($message, 'outside the accepted window')) {
                $message .= sprintf(
                    ' The signature carries a timestamp and the platform allows %d seconds either '
                    .'way, so this is a clock that has drifted rather than a wrong secret. Check NTP '
                    .'on the machine making the call.',
                    Signature::TOLERANCE_SECONDS
                );
            } elseif (str_contains($message, 'does not match the payload')) {
                $message .= ' The secret is being accepted but the bytes disagree. This is almost '
                    .'always a body serialised twice - once to sign and once to send - rather than a '
                    .'wrong secret. The SDK sends the exact string it signed; something re-encoding '
                    .'the body in between, such as a proxy that reformats JSON, will break it.';
            }
        }

        return new $class($message, $code, $details, $requestId, $status);
    }
}
