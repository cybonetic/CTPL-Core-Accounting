# Core Accounting SDK for Laravel

A typed client for the Core Accounting platform. Customers, invoices, credit and debit notes,
receipts, and what is owed.

```php
$invoice = CoreAccounting::invoices()->create(
    customerId: $customer['id'],
    lines: [
        InvoiceLine::make('Payroll processing, September 2026', '25000.00',
            hsnSac: '998311', taxCodeId: 7, costCentreId: 1),
    ],
    idempotencyKey: IdempotencyKey::for('hrms', $payrollRun->id),
    sourceSystem: 'hrms',
    sourceId: (string) $payrollRun->id,
);

$invoice['document_number'];   // "HRM-INV-26-0003"
$invoice['status'];            // "posted" - the ledger already has it
$invoice['grand_total'];       // "29500.0000" - a string, always
```

---

## Install

```bash
composer require ctpl/core-accounting-sdk
php artisan vendor:publish --tag=core-accounting-config
```

```ini
CORE_ACCOUNTING_URL=https://accounts.example.com
CORE_ACCOUNTING_APP_ID=CTPL-HRMS-PROD
CORE_ACCOUNTING_KEY=ak_...
CORE_ACCOUNTING_SECRET=...
CORE_ACCOUNTING_COMPANY_ID=1
```

The three credentials come from **Set up → Cloud applications** in the back office. The secret is
displayed once at registration and stored only as a hash — a lost one means a new key, not a lookup.

A missing credential fails when the client is resolved, with a message saying which environment
variable is blank. It does not fail as a 401 on whatever call happens to run first, which reads as
"the ledger is rejecting us" and sends people to check the wrong machine.

---

## Five things to know before you write any code

### 1. Money is a string. A float will be refused.

```php
InvoiceLine::make('Consulting', '1119.40');     // ✅
InvoiceLine::make('Consulting', 1119.40);       // ✗ refused, with an explanation
```

PHP stores `1119.40` as `1119.40000000000009094947`. Multiply it three times and the invoice is four
paise out against the sum of its own lines — silently, unrecoverably, and nobody notices until a
reconciliation months later when there is no way to tell which of ten thousand invoices is wrong.

Keep the amount as a string all the way from its source. A `DECIMAL` column already reads back as
one.

`Money` has **no arithmetic** — no `plus()`, no `times()`. Every total a ledger cares about is
computed by the ledger. A caller computing one creates a second opinion about the number on the
invoice, and there is no good way to resolve a disagreement between them.

### 2. The idempotency key must be derived from your own record

```php
IdempotencyKey::for('hrms', $payrollRun->id);   // ✅ same key tomorrow
IdempotencyKey::random();                       // ✗ refused, with an explanation
```

Its only job is to be **identical when the same write is retried**. A random value on the retry
creates a second invoice with a second statutory number. The SDK will not generate one for you, and
`random()` exists purely to throw.

The rule of thumb: *if you retried this business event tomorrow, would you want a second invoice?*
If no, the key must be the same tomorrow.

### 3. There is no `update()` on an invoice

A posted invoice has already hit the general ledger and already carries a statutory number.

| Situation | Do this |
|---|---|
| Raised by mistake, nothing sent, period open | `invoices()->cancel($id, reason: '...')` |
| Customer has a copy, or the period closed | `notes()->credit(...)` |

`invoices()->update()` exists and throws, with that table in the message. "Call to undefined method"
teaches nothing, and this is the moment an integrator is paying attention.

### 4. One call creates *and* posts

`status: posted` and a `journal_entry_id` in the response mean the ledger already has it. There is
no separate post step to forget — and no window in which a half-made invoice exists.

### 5. Your first invoice will probably be refused for a cost centre

```
Line 1 posts to 4200 Service Revenue, which requires a Cost Centre.
```

A freshly seeded company makes a cost centre mandatory on revenue accounts and seeds none. This is
an administrator's job, in the back office, once — not yours.

```php
} catch (AccountingRuleViolation $e) {
    if ($e->needsCostCentre()) {
        return back()->withErrors('Ask your accounts team to set up a cost centre.');
    }
    return back()->withErrors($e->getMessage());
}
```

### 6. If your price already includes the tax, say so

`unit_price` is the price **before** tax unless told otherwise. Send the figure the customer
actually paid without saying so and nothing fails — the tax is added on top, revenue is overstated
and GSTR-1 reports tax that was never collected.

```php
$ledger->invoices()->create(
    customerId: 4,
    lines: [InvoiceLine::make('Consultation', '990.00', hsnSac: '998311')],
    idempotencyKey: $key,
    pricesIncludeTax: true,   // 990 contains the GST; it is carved out, not added
);
```

Per line instead of per document, and either way:

```php
InvoiceLine::make('Consultation', '990.00', priceIncludesTax: true);

// One reimbursed expense opting out of an inclusive invoice.
InvoiceLine::make('Travel', '1000.00')->withPriceIncludingTax(false);
```

Omit it entirely and the tax code decides, which is what every existing caller does — upgrading
changes nothing until you pass the flag.

### 7. If the customer has already paid, send the payment id

One field. The id your gateway gave you — nothing else.

```php
$ledger->invoices()->create(
    customerId: 4,
    lines: [InvoiceLine::make('Consultation', '11800.00', hsnSac: '998311')],
    idempotencyKey: $key,
    sourceSystem: 'hrms',
    paymentId: $booking->razorpay_payment_id,
);
```

Core Accounting then calls **your** payment-status endpoint with that id, stores whatever comes
back, and posts the payment into the gateway's clearing account if — and only if — the reply says
the money was **captured**. `authorized` is not captured: the gateway is holding a block on the card
and has not taken the money.

**Do not send the amount, the status or the method.** They are established from your endpoint. Two
sources for one fact disagree the first time a webhook arrives late.

Omit `paymentId` and nothing happens: no endpoint is called, no payment is recorded.

A failed lookup **never fails the invoice**. The document is already numbered and posted by the time
it runs; an endpoint that is down leaves a row to retry, visible on the invoice screen.

**Paid afterwards**, on a reminder or a payment link:

```php
$ledger->payments()->attach($invoiceId, $paymentId, $key);

// Your endpoint was down when the invoice was raised:
$ledger->payments()->refresh($paymentRecordId);

$ledger->payments()->forDocument($invoiceId);
```

The same id twice is the same payment and returns the record already held. The same id against a
different document is refused — if the customer paid for two, the gateway issued two ids.

Your endpoint is registered against your application by an administrator: URL, the header the token
goes in, and the token. It must be **https**, must not point at a private address, and redirects are
not followed.

---

## Errors

Each error code from the platform is its own class, so a `catch` can be specific.

| Exception | When | Retry? |
|---|---|---|
| `ValidationFailed` | your payload; `errors()` is keyed by field | never |
| `AccountingRuleViolation` | a rule of accounting; **show the message** | never |
| `PermissionDenied` | scope not granted; the message names it | never |
| `AuthenticationFailed` | wrong or revoked credentials | never |
| `CompanyContextMissing` | no company named | never |
| `DocumentNotFound` | wrong id, or another company's | never |
| `Conflict` | already exists or already in that state | usually fine |
| `ImmutableDocument` | you tried to edit a posted document | never |
| `LedgerUnavailable` | unreachable, timed out, 5xx, 429 | **yes** |

`ValidationFailed` and `AccountingRuleViolation` share HTTP 422 and mean opposite things — one is
your payload, the other is a rule your payload obeyed the shape of. The SDK branches on the error
code, never on the status alone.

Every exception carries `requestId()`. Log it: it turns "the invoice failed sometime on Tuesday"
into one line in the platform's log.

---

## When the ledger is down

Retries happen automatically on timeouts, refused connections, 5xx and 429 — exponential with
jitter, carrying the **same** idempotency key, so the retry replays the write rather than repeating
it. Nothing else is retried.

If every retry fails and `defer_writes` is on, the write is handed to a queued job with that same
key, and `LedgerUnavailable` is still thrown — your request cannot be given a number that does not
exist yet.

```php
} catch (LedgerUnavailable $e) {
    return $e->wasQueued()
        ? back()->with('notice', 'Billing is catching up; the invoice will appear shortly.')
        : back()->withErrors('Could not reach accounting. Nothing was recorded.');
}
```

`wasQueued()` is the question that decides what you tell the person at the screen. If the queue is
also down, it is **false** — the SDK will not claim a write was kept when it was not.

When the queued write lands, `DocumentRecorded` fires with the document and your original key:

```php
Event::listen(DocumentRecorded::class, function (DocumentRecorded $e) {
    PayrollRun::where('ledger_key', $e->idempotencyKey)->update([
        'invoice_number' => $e->document['document_number'],
    ]);
});
```

Store the key when you make the call, not only when it fails. Code on the uncommon path that depends
on something the common path did not save is code that has never run.

Deferral needs a queue worker. On the `sync` driver it is pointless — the job runs immediately, in
the same process, against the same unreachable ledger.

---

## Testing

```php
use Ctpl\CoreAccounting\Testing\FakeLedger;

FakeLedger::start()->invoiceNumbered('HRM-INV-26-0003');

$this->post('/payroll/88/bill')->assertOk();

FakeLedger::assertInvoiceRaised(fn (array $body) => $body['lines'][0]['unit_price'] === '25000.00');
FakeLedger::assertIdempotencyKeyStable();
```

That last one is the assertion worth writing above all the others. Two calls for the same business
event must carry the same key, or the retry that happens one day in production raises a second
invoice. Nothing else in a test suite catches it, because in a test nothing is ever retried.

`FakeLedger::refusesWithoutCostCentre()` reproduces the refusal every first integration hits, so you
can prove your error handling before meeting it in staging.

Anything not explicitly stubbed answers 404 rather than an empty 200 — a fake that succeeds for an
unexpected call lets a test pass over an endpoint nobody meant to hit.

---

## Several companies

```php
$ledger = app(CoreAccounting::class)->forCompany(7);
```

Returns a new instance rather than mutating, so a request handling two entities cannot leak one into
the other. Every call names its company; the platform will not guess.

---

## What it covers

| | |
|---|---|
| `customers()` | create, find, list, **update** (a master record, so this one really is updatable) |
| `invoices()` | create, find, list, cancel |
| `notes()` | credit, debit, find, list, cancel, `headroom()`, `outsideTaxWindow()` |
| `receipts()` | record, find, list, apply |
| `receivables()` | open items, ageing, statement |

**Quotations are deliberately absent.** A quotation has no ledger effect until it is accepted, so it
belongs in your application. Turn an accepted one into an invoice with `invoices()->create()`.

`notes()->headroom($invoiceId)` before promising a refund: credit notes against one invoice cannot
exceed it, and finding that out by being refused — after telling the customer — is the wrong order.
