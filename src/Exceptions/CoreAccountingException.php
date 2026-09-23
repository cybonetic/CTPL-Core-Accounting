<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Exceptions;

use RuntimeException;

/**
 * Everything this SDK throws.
 *
 * ---
 *
 * **Why the platform's error codes become separate exception classes.**
 *
 * The API answers with a stable machine-readable `error.code` and a sentence
 * written for a person. Both are worth keeping, and they are worth keeping
 * apart: the code is a contract and the sentence is not.
 *
 * A single exception carrying a string code would make every caller write
 * `if ($e->getCode() === 'forbidden')`, and the one thing certain about that
 * code is that somebody will eventually compare it against `'Forbidden'` and
 * never find out. A class per code turns that into a `catch` the compiler can
 * see.
 *
 * The split also encodes something the codes alone do not: **which of these are
 * worth retrying.** `LedgerUnavailable` is; `ValidationFailed` never is, because
 * repeating a request changes nothing about it. `Connection` uses exactly that
 * distinction, so it is stated once here rather than guessed at each call site.
 */
abstract class CoreAccountingException extends RuntimeException
{
    /**
     * @param  array<string,mixed>  $details
     */
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly array $details = [],
        public readonly ?string $requestId = null,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Would trying again, unchanged, possibly work?
     *
     * False for everything that is a property of the request rather than of the
     * moment: a wrong credential, a missing permission, a payload that does not
     * validate, a rule of accounting that was broken. Repeating those spends a
     * round trip to be told the same thing.
     */
    public function isRetryable(): bool
    {
        return false;
    }

    /**
     * The identifier the platform logged this under.
     *
     * Every response carries one, success or failure. It is the difference
     * between "the invoice failed sometime on Tuesday" and one line in a log.
     * Put it in your own exception report.
     */
    public function requestId(): ?string
    {
        return $this->requestId;
    }
}
