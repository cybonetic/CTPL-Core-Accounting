<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Support;

use InvalidArgumentException;

/**
 * The key that makes a retry replay a write instead of repeating it.
 *
 * ---
 *
 * **Why this class exists rather than a `Str::uuid()` somewhere.**
 *
 * An idempotency key only works if it is the **same** on the retry. A key
 * generated inside the SDK at the moment of sending is a different key on every
 * attempt, which turns the one mechanism that prevents duplicate invoices into
 * decoration - and the failure is invisible until the day the network is slow,
 * at which point a customer has two invoices with two statutory numbers, both
 * posted, and unpicking it means a credit note and a phone call.
 *
 * So the key is derived from something in *your* system that is already stable:
 * the payroll run, the appointment, the order. Not from the clock, not from
 * `random_bytes`, not from a hash of the payload - a payload hash changes when
 * somebody corrects a typo in a description, and then the "same" invoice is a
 * new one.
 *
 * ---
 *
 * **The rule of thumb.** If you retried this exact business event tomorrow,
 * would you want a second invoice? If no, the key must be the same tomorrow.
 */
final class IdempotencyKey
{
    private function __construct(private readonly string $key) {}

    /**
     * From your own record: the system and the identifier it already has.
     *
     *     IdempotencyKey::for('hrms', 'payroll-run-88')   → hrms-payroll-run-88
     *
     * The same payroll run produces the same key for ever, which is exactly the
     * property wanted.
     */
    public static function for(string $sourceSystem, string|int $sourceId, ?string $action = null): self
    {
        $parts = array_filter([
            self::slug($sourceSystem),
            $action === null ? null : self::slug($action),
            self::slug((string) $sourceId),
        ]);

        if (count($parts) < 2) {
            throw new InvalidArgumentException(
                'An idempotency key needs a source system and an identifier from your own records, '
                .'so that a retry tomorrow produces the same key as the attempt today.'
            );
        }

        return new self(substr(implode('-', $parts), 0, 128));
    }

    /**
     * A key you have already composed.
     *
     * The checks are not pedantry. A key that varies per attempt is worse than
     * no key at all: without one, a duplicate is at least obvious.
     */
    public static function of(string $key): self
    {
        $trimmed = trim($key);

        if (strlen($trimmed) < 8) {
            throw new InvalidArgumentException(sprintf(
                'The idempotency key "%s" is too short to be distinctive. Derive it from your own '
                .'record - IdempotencyKey::for("hrms", $run->id) - so the same business event always '
                .'produces the same key.',
                $trimmed
            ));
        }

        if (strlen($trimmed) > 128) {
            throw new InvalidArgumentException('An idempotency key may be at most 128 characters.');
        }

        return new self($trimmed);
    }

    /**
     * Refuses the mistake this class was written to prevent.
     *
     * Named rather than simply absent, because "there is no random constructor"
     * teaches nothing, and somebody reaching for one is about to introduce
     * duplicate invoices they will not see for months.
     */
    public static function random(): never
    {
        throw new InvalidArgumentException(
            'A random idempotency key does nothing. Its only job is to be identical when the same '
            .'write is retried, and a fresh random value on the retry creates a SECOND invoice with a '
            .'second statutory number. Derive it from your own record instead: '
            .'IdempotencyKey::for("hrms", $payrollRun->id).'
        );
    }

    public function value(): string
    {
        return $this->key;
    }

    public function __toString(): string
    {
        return $this->key;
    }

    private static function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', trim($value)));

        return trim($slug, '-');
    }
}
