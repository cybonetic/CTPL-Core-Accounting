<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Values;

use InvalidArgumentException;

/**
 * An amount, as a string, from your code to the ledger and back.
 *
 * ---
 *
 * **Why this refuses a float, loudly, instead of quietly accepting one.**
 *
 * Core Accounting carries every amount as a string and stores it as an integer
 * at 10⁻⁴. Nothing in that path is ever a float, and this class is the reason a
 * float in *your* application cannot become one in the ledger.
 *
 * The failure it prevents is not theoretical and not recoverable:
 *
 *     $line = 1119.40;          // stored as 1119.4000000000000909494701772928
 *     $total = $line * 3;       // 3358.199999999999590727384202182
 *     (string) $total           // "3358.2"        ← looks fine
 *     round($total, 2)          // 3358.2          ← still looks fine
 *
 * That invoice is out by four paise against the sum of its own lines. Nobody
 * notices at the time. It surfaces months later as a reconciliation break, and
 * by then there is no way to tell which of ten thousand invoices is wrong -
 * because every one of them *looks* right.
 *
 * So a float is rejected with a message that says what to write instead. The
 * one-line fix is to keep the amount as a string from wherever it came from,
 * which is almost always a database column that was a DECIMAL to begin with.
 *
 * ---
 *
 * **Arithmetic is deliberately absent.**
 *
 * There is no `plus()`, no `times()`, no `percentage()`. That is not an
 * oversight and it is not laziness.
 *
 * Every amount a ledger cares about is computed BY the ledger: line totals,
 * tax, rounding to the invoice's currency, the grand total. Giving this class
 * arithmetic would invite a caller to compute a total, send it, and have the
 * platform compute a different one - and the interesting question then becomes
 * which of the two is on the printed invoice. There is no good answer.
 *
 * Send the quantities and unit prices. Read the totals back.
 */
final class Money implements \JsonSerializable, \Stringable
{
    private function __construct(private readonly string $amount) {}

    /**
     * @param  self|string|int  $amount  a decimal string, or a whole number of units
     */
    public static function of(self|string|int $amount): self
    {
        if ($amount instanceof self) {
            return $amount;
        }

        if (is_int($amount)) {
            // An int is exact, so it is safe - unlike a float, which is the
            // thing this class exists to keep out.
            return new self((string) $amount);
        }

        $trimmed = trim($amount);

        if ($trimmed === '') {
            throw new InvalidArgumentException('An amount cannot be empty.');
        }

        /*
         * Up to four decimal places, because that is the scale the ledger
         * stores. A fifth would be silently dropped somewhere, and silently is
         * the problem.
         */
        if (preg_match('/^-?\d+(\.\d{1,4})?$/', $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not an amount this platform accepts. It must be digits with at most four '
                .'decimal places, as a string - for example "25000.00" or "1119.4050". %s',
                $trimmed,
                str_contains($trimmed, 'E') || str_contains($trimmed, 'e')
                    ? 'This looks like a float that PHP rendered in scientific notation; see Money::of().'
                    : ''
            ));
        }

        return new self($trimmed);
    }

    /**
     * The guard rail. A float never gets past here.
     *
     * PHP will happily coerce a float to a string at the call site, so the type
     * declaration on `of()` alone would not catch it: `Money::of(1119.40)` would
     * arrive as `"1119.4"`, pass the pattern, and be four paise wrong on the
     * third multiplication somebody does with it later. This overload exists
     * purely so the mistake has a name.
     */
    public static function fromFloat(float $amount): never
    {
        throw new InvalidArgumentException(sprintf(
            'Money cannot be built from a float. PHP stored %s as %s, which is already not the '
            .'number you meant, and no amount of rounding afterwards recovers it. Keep the value as '
            .'a string all the way from its source - a DECIMAL column reads back as a string '
            .'already - and pass that: Money::of($row->unit_price).',
            var_export($amount, true),
            var_export(sprintf('%.20F', $amount), true)
        ));
    }

    /** Zero, for the cases that genuinely mean it. */
    public static function zero(): self
    {
        return new self('0');
    }

    public function isZero(): bool
    {
        return (float) $this->amount === 0.0;
    }

    public function value(): string
    {
        return $this->amount;
    }

    public function __toString(): string
    {
        return $this->amount;
    }

    public function jsonSerialize(): string
    {
        return $this->amount;
    }
}
