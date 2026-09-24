# SDK delta — sending the gateway payment id

Five files. No config change, no new dependency, nothing to migrate.

Apply after `sdk-inclusive-delta.zip`. Needs the platform's `payments-and-numbers-delta.zip`: an
older Core Accounting ignores the field, which would leave you sending a payment id that nothing
records — and no error to tell you.

```bash
cd /var/www/<your-app>
php artisan config:clear
```

---

## The answer to "was this in the SDK?" — it was not, until now

The platform has accepted `payment_id` on `POST /api/v1/invoices` since the last delta. The SDK had
no parameter for it. It was reachable through the `$attributes` bag, but undiscoverable and
unchecked, which is not an integration anybody should be asked to find.

---

## Sending it

```php
$ledger->invoices()->create(
    customerId: 4,
    lines: [InvoiceLine::make('Consultation', '11800.00', hsnSac: '998311')],
    idempotencyKey: $key,
    sourceSystem: 'hrms',
    paymentId: $booking->razorpay_payment_id,
);
```

**One field. The id your gateway gave you, and nothing else.**

Core Accounting calls **your** payment-status endpoint with that id, stores whatever comes back, and
posts the payment into the gateway's clearing account if — and only if — the reply says the money
was **captured**.

**Do not also send the amount, the status or the method.** They are established from your endpoint.
Two sources for one fact will disagree the first time a webhook arrives late, and the one in the
ledger is the one that is wrong. There is a test asserting the SDK sends the id and nothing else.

`authorized` is **not** captured — the gateway is holding a block on the card and has not taken the
money, and posting it would put an amount in the clearing account the gateway does not owe you.

Omit `paymentId` and nothing happens: no endpoint is called, no payment recorded.

**A failed lookup never fails the invoice.** The document is numbered and posted before the lookup
runs. An endpoint that is down leaves a row to retry, visible on the invoice screen.

## Paid after the invoice was raised

On a reminder, or a payment link days later:

```php
$ledger->payments()->attach($invoiceId, $paymentId, $key);
$ledger->payments()->refresh($paymentRecordId);   // your endpoint was down earlier
$ledger->payments()->forDocument($invoiceId);
```

The same id twice is the same payment and returns the record already held, so a retried webhook is
harmless. The same id against a *different* document is refused — if the customer paid for two, the
gateway issued two ids.

## `paymentId` is last in the signature

After `$attributes` and after `$pricesIncludeTax`, so it cannot shift a positional argument in code
you have already written. A new parameter inserted before `$attributes` would have made `$sourceId`
land in `$attributes`, and the first symptom would be invoices attributed to the wrong application.
There is a test for that too.

## One new method on `Connection`

`act()` — a POST that creates nothing and therefore carries **no** idempotency key. Refreshing a
lookup is the only caller today. Giving it a key would be actively wrong: a key makes a retry replay
the first answer, and a lookup is being retried precisely because the first answer was a failure.

It is a separate method rather than a nullable argument on `post()`, so that omitting a key on a real
write stays impossible.

---

## Verified

**69 tests** (up from 61). Both new guards proved by reverting them:

- the `payment_id` line removed from the payload — the test failed
- `refresh()` given a fixed idempotency key — the test asserting it sends none failed
