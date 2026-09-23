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
        );
    }

    /** The same line against a different cost centre, for the common bulk case. */
    public function withCostCentre(int $costCentreId): self
    {
        return new self(
            $this->description, $this->unitPrice, $this->quantity, $this->unit, $this->hsnSac,
            $this->taxCodeId, $costCentreId, $this->discount, $this->revenueAccountId,
            $this->revenueAccountCode,
        );
    }

    public function withTaxCode(int $taxCodeId): self
    {
        return new self(
            $this->description, $this->unitPrice, $this->quantity, $this->unit, $this->hsnSac,
            $taxCodeId, $this->costCentreId, $this->discount, $this->revenueAccountId,
            $this->revenueAccountCode,
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
        ], static fn ($value) => $value !== null);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
