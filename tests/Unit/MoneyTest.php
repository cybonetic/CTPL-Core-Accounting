<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Tests\Unit;

use Ctpl\CoreAccounting\Values\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Amounts, and the float that must never become one.
 *
 * ---
 *
 * These are the most important tests in the package. Every other failure here
 * is loud - a 422, an exception, a missing method. A float that slipped into an
 * amount is silent: the invoice posts, the customer pays it, and the four paise
 * turn up months later as a reconciliation break nobody can attribute.
 */
class MoneyTest extends TestCase
{
    #[Test]
    public function a_decimal_string_survives_exactly(): void
    {
        // Not `assertEquals`: that would pass on "25000.0" for "25000.00", and
        // the trailing zero is the difference between what was quoted and what
        // was sent.
        $this->assertSame('25000.00', Money::of('25000.00')->value());
        $this->assertSame('1119.4050', Money::of('1119.4050')->value());
        $this->assertSame('0', Money::of('0')->value());
    }

    #[Test]
    public function a_whole_number_is_exact_and_therefore_allowed(): void
    {
        $this->assertSame('25000', Money::of(25000)->value());
    }

    /**
     * The one that matters.
     *
     * PHP coerces a float to a string at the call site, so `Money::of(1119.40)`
     * would otherwise arrive as "1119.4" - which passes every pattern, looks
     * right, and is already not the number that was meant.
     */
    #[Test]
    public function a_float_is_refused_and_the_message_shows_what_php_actually_stored(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be built from a float/');

        Money::fromFloat(1119.40);
    }

    #[Test]
    public function the_refusal_names_the_real_stored_value(): void
    {
        try {
            Money::fromFloat(1119.40);
            $this->fail('A float was accepted.');
        } catch (InvalidArgumentException $e) {
            // 1119.40 is not representable in binary floating point. The
            // message shows the digits PHP is really holding, because the
            // argument "but it prints as 1119.4" is what makes people override
            // this check.
            $this->assertStringContainsString('1119.40000000000009094947', $e->getMessage());
            $this->assertStringContainsString('Money::of($row->unit_price)', $e->getMessage());
        }
    }

    #[Test]
    public function more_than_four_decimal_places_is_refused_rather_than_truncated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // The ledger stores four. A fifth would be dropped somewhere, and
        // silently is the problem - the caller should decide what to round.
        Money::of('10.00005');
    }

    #[Test]
    public function a_float_rendered_in_scientific_notation_is_caught_and_named(): void
    {
        try {
            Money::of((string) 1.0E-7);
            $this->fail('Scientific notation was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('scientific notation', $e->getMessage());
        }
    }

    #[Test]
    public function rubbish_is_refused_with_an_example_of_what_to_send(): void
    {
        try {
            Money::of('twenty five thousand');
            $this->fail('A non-amount was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('"25000.00"', $e->getMessage());
        }
    }

    #[Test]
    public function it_serialises_as_a_string_in_json_not_as_a_number(): void
    {
        // The wire format. `json_encode` on a float would emit 25000 and the
        // platform would refuse it, because its rule is a regex over a string.
        $this->assertSame('{"amount":"25000.00"}', json_encode(['amount' => Money::of('25000.00')]));
    }

    #[Test]
    public function it_offers_no_arithmetic(): void
    {
        // Deliberate. Every total a ledger cares about is computed BY the
        // ledger; a caller computing one creates a second opinion about the
        // number on the invoice, and there is no good way to resolve a
        // disagreement between them.
        $this->assertFalse(method_exists(Money::class, 'plus'));
        $this->assertFalse(method_exists(Money::class, 'times'));
        $this->assertFalse(method_exists(Money::class, 'multiply'));
    }
}
