<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Values;

use InvalidArgumentException;

/**
 * One line of an invoice or a note.
 *
 * ---
 *
 * **What a line does not carry, and why.**
 *
 * There is no line total and no tax amount. Both are computed by the ledger
 * from the quantity, the unit price and the tax code, and sending a figure
 * alongside would create a second opinion about the same number. When the two
 * disagree - and they will, the first time a discount meets a three-way GST
 * split - the interesting question becomes which one is on the invoice the
 * customer received. There is no good answer to that, so there is no second
 * opinion.
 *
 * Send what you know. Read the totals back from the response.
 */
final class InvoiceLine implements \JsonSerializable
{
    private function __construct(
        private readonly string $description,
        private readonly Money $unitPrice,
        private readonly Money $quantity,
        private readonly ?string $unit,
        private readonly ?string $hsnSac,
        private readonly ?int $taxCodeId,
        private readonly ?int $costCentreId,
        private readonly ?Money $discount,
        private readonly ?int $revenueAccountId,
        private readonly ?string $revenueAccountCode,
        private readonly ?bool $priceIncludesTax = null,
    ) {}

    /**
     * @param  Money|string|int  $unitPrice  a string, never a float - see Money
     * @param  Money|string|int  $quantity
     */
    public static function make(
        string $description,
        Money|string|int $unitPrice,
        Money|string|int $quantity = '1',
        ?string $unit = null,
        ?string $hsnSac = null,
        ?int $taxCodeId = null,
        ?int $costCentreId = null,
        Money|string|int|null $discount = null,
        ?int $revenueAccountId = null,
        ?string $revenueAccountCode = null,
        /**
         * Whether `$unitPrice` already contains the tax.
         *
         * Null - the default - means the ledger decides from the tax code,
         * which is how every line behaved before this existed.
         *
         * Set it to TRUE when the figure you hold is what the customer was
         * actually charged. Sending a tax-inclusive 990 without saying so is
         * not an error anywhere: the ledger adds 18% on top, raises an invoice
         * for 1,168.20, overstates revenue by 151.02 and reports output tax
         * that was never collected. Nothing fails until the receivable will
         * not clear.
         */
        ?bool $priceIncludesTax = null,
    ): self {
        $description = trim($description);

        if ($description === '') {
            throw new InvalidArgumentException(
                'Every invoice line needs a description. It is what the customer reads, and under '
                .'Rule 46 it is what the document has to say was supplied.'
            );
        }

        if ($hsnSac !== null && preg_match('/^\d{4,8}$/', $hsnSac) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not an HSN or SAC code. They are four to eight digits - 998311 for payroll '
                .'processing, for example.',
                $hsnSac
            ));
        }

        return new self(
            description: $description,
            unitPrice: Money::of($unitPrice),
            quantity: Money::of($quantity),
            unit: $unit,
            hsnSac: $hsnSac,
            taxCodeId: $taxCodeId,
            costCentreId: $costCentreId,
            discount: $discount === null ? null : Money::of($discount),
            revenueAccountId: $revenueAccountId,
            revenueAccountCode: $revenueAccountCode,
            priceIncludesTax: $priceIncludesTax,
        );
    }

    /**
     * The same line, declared tax-inclusive.
     *
     * `false` is a statement and not an omission: it overrides a document that
     * said its prices were inclusive, which is what a reimbursed expense on an
     * otherwise inclusive invoice needs.
     */
    public function withPriceIncludingTax(bool $includes = true): self
    {
        return new self(
            $this->description, $this->unitPrice, $this->quantity, $this->unit, $this->hsnSac,
            $this->taxCodeId, $this->costCentreId, $this->discount, $this->revenueAccountId,
            $this->revenueAccountCode, $includes,
        );
    }

    /** The same line against a different cost centre, for the common bulk case. */
    public function withCostCentre(int $costCentreId): self
    {
        return new self(
            $this->description, $this->unitPrice, $this->quantity, $this->unit, $this->hsnSac,
            $this->taxCodeId, $costCentreId, $this->discount, $this->revenueAccountId,
            $this->revenueAccountCode, $this->priceIncludesTax,
        );
    }

    public function withTaxCode(int $taxCodeId): self
    {
        return new self(
            $this->description, $this->unitPrice, $this->quantity, $this->unit, $this->hsnSac,
            $taxCodeId, $this->costCentreId, $this->discount, $this->revenueAccountId,
            $this->revenueAccountCode, $this->priceIncludesTax,
        );
    }

    public function hasCostCentre(): bool
    {
        return $this->costCentreId !== null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'description' => $this->description,
            'quantity' => $this->quantity->value(),
            'unit_price' => $this->unitPrice->value(),
            'unit' => $this->unit,
            'hsn_sac' => $this->hsnSac,
            'tax_code_id' => $this->taxCodeId,
            'cost_center_id' => $this->costCentreId,
            'discount_amount' => $this->discount?->value(),
            'revenue_account_id' => $this->revenueAccountId,
            'revenue_account_code' => $this->revenueAccountCode,
            // Filtered on `!== null`, not on truthiness, so an explicit FALSE
            // survives. It has to: false is how one line opts out of a document
            // that declared its prices inclusive, and dropping it would turn
            // that line back into an inclusive one without saying so.
            'price_includes_tax' => $this->priceIncludesTax,
        ], static fn ($value) => $value !== null);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
