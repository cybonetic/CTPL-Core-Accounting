# SDK delta — tax-inclusive prices, and one corrected error message

Four files. No config change, no new dependency, nothing to migrate.

Apply after `sdk-encryption-delta.zip`. Then:

```bash
cd /var/www/<your-app>
php artisan config:clear
```

---

## 1. Prices that already contain the tax

Needs the matching platform delta (`inclusive-tax-delta.zip`) — the fields are ignored by an older
Core Accounting, which would leave you with the wrong invoice and no error.

`unit_price` is the price **before** tax unless you say otherwise. Sending the figure the customer
actually paid without saying so is silent: the tax goes on top, the invoice is for more than was
collected, and it is found when the receipt will not clear the receivable.

```php
$ledger->invoices()->create(
    customerId: 4,
    lines: [InvoiceLine::make('Consultation', '990.00', hsnSac: '998311')],
    idempotencyKey: $key,
    pricesIncludeTax: true,
);
```

```
taxable 838.98   tax 151.02   grand 990.00
```

Per line instead, or as well:

```php
InvoiceLine::make('Consultation', '990.00', priceIncludesTax: true);

// One reimbursed expense opting out of an otherwise inclusive invoice.
InvoiceLine::make('Travel', '1000.00')->withPriceIncludingTax(false);
```

`false` is a statement and not an omission — it beats a document that said `true`. Omit the field
entirely and the ledger's tax code decides, which is what your code does today: **upgrading changes
nothing until you pass the flag.**

`pricesIncludeTax` is the **last** parameter of `create()`, after `$attributes`, so it cannot shift a
positional argument in code you have already written.

## 2. The "signature does not match the payload" message

`src/Http/Envelope.php` only. The old text asserted that your signing secret was being accepted and
that the bytes had been altered in transit. The platform gives no evidence for that — it computes one
HMAC and compares, and a wrong secret and altered bytes fail identically. The message now names both,
with the likelier first: a rotated secret, or a `.env` changed without `php artisan config:clear`.

---

## Verified

**61 tests** (up from 56). The new ones assert what goes **on the wire**, since the SDK computes
nothing here and the only thing it can get wrong is failing to say what it was told:

- an explicit `false` survives the null-pruning that strips absent fields — written on truthiness it
  would not, and the line opting *out* would arrive saying nothing and be treated as inclusive
- `withCostCentre()` and `withTaxCode()` rebuild a line positionally, so both were proved to carry
  the new property through by removing it and watching the test fail
- an invoice that says nothing sends **no key at all**, so the platform's third state is preserved
