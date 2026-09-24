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
CORE_ACCOUNTING_APP_ID=CTPL-HRMS-PROD
CORE_ACCOUNTING_KEY=ak_...
CORE_ACCOUNTING_SECRET=...
CORE_ACCOUNTING_COMPANY_ID=1

# Only if your credential was registered with signing switched on
CORE_ACCOUNTING_SIGNING_SECRET=...
```

**There is no URL to set.** `https://cacc.cybonetic.com` is compiled into the package, so an
application that sets its three credentials is talking to the real ledger. Nobody has to be told the
address, and a blank or mistyped one cannot quietly send invoices nowhere.

`CORE_ACCOUNTING_URL` still overrides it for staging and local work. Set it deliberately when you
mean to; leaving it unset is the correct default, including in production.

The three credentials come from **Set up → Cloud applications** in the back office. The secret is
displayed once at registration and stored only as a hash — a lost one means a new key, not a lookup.

A missing credential fails when the client is resolved, with a message saying which environment
variable is blank. It does not fail as a 401 on whatever call happens to run first, which reads as
"the ledger is rejecting us" and sends people to check the wrong machine.

### Redirects are refused, not followed

If the ledger answers a redirect, the SDK stops and explains rather than following it. Guzzle's
default is to follow a 301 and, in doing so, turn a `POST` into a `GET` — so an invoice create would
arrive as a *list*, answer 200 with a page of invoices, and your application would record a number
for a document that was never raised. A write that silently does nothing is worse than a write that
fails.

The message names the likely cause when the redirect points back at the address just requested,
which is the signature of a proxy terminating TLS and reaching the application over plain HTTP while
the application insists on HTTPS. That is a deployment fix, not a code one.

### Request signing

Some credentials are registered to require it. If yours is, every call is refused until
`CORE_ACCOUNTING_SIGNING_SECRET` is set:

```
This credential requires a signed request. Send X-Signature: t=<unix>,v1=<hmac>.
```

Set it and the SDK signs automatically — there is nothing to call. The secret is a **fourth**
value, separate from the three credentials, and an administrator shows it from the application's
screen in the back office. The three credentials prove *who is calling*; the signature proves *this
body is the body that caller sent*, which is what stops something holding a stolen key from altering
an invoice in transit.

If you are implementing this yourself rather than using the SDK, the one rule that matters:

> **Sign the bytes you send.** The signed string is `"<unix timestamp>.<raw body>"`, HMAC-SHA256,
> hex, and the platform recomputes it from the bytes that actually arrived. Serialise the body once,
> sign that string, send that string. Handing an array to an HTTP client and separately
> `json_encode`-ing it to sign is how you get a signature that is wrong by one escaped slash while
> the body in your log looks perfect.

A GET has no body, so it signs `"<timestamp>."` — the separator stays.

Three different problems come back as HTTP 403, so `PermissionDenied` can tell them apart:

```php
} catch (PermissionDenied $e) {
    $e->needsSignature();        // no secret configured — set the env var
    $e->signatureExpired();      // clock drift; ±300s is the window
    $e->signatureDidNotMatch();  // the bytes disagree, not the secret
}
```

---

## Payload encryption

Encrypts the request body on top of TLS, so nothing in between can read an invoice — including
anything that terminates TLS in the middle. Off by default.

```ini
CORE_ACCOUNTING_ENCRYPT=true
CORE_ACCOUNTING_PLATFORM_KEY_PATH=/var/lib/hrms/keys/core-accounting.pem
CORE_ACCOUNTING_PRIVATE_KEY_PATH=/var/lib/hrms/keys/app.key
```

**Two key pairs, and they are not interchangeable.**

| | |
|---|---|
| Core Accounting's | one **per application**. An administrator generates it on your application's screen and hands you the public half. You encrypt **to** it. |
| Yours | you generate it; its public half is uploaded on that same screen. Replies come back encrypted to it, and your private half never leaves your server. |

```bash
openssl genrsa -out app.key 4096 && chmod 400 app.key
openssl rsa -in app.key -pubout -out app.pub     # upload app.pub, keep app.key
```

For Core Accounting's key, put the file you were given in `CORE_ACCOUNTING_PLATFORM_KEY_PATH` (or
the PEM itself in `CORE_ACCOUNTING_PLATFORM_KEY`). **A bare public key or the self-signed
certificate over it both work** — the SDK takes the key out of the certificate. Both are in the
export archive from that screen.

Nothing is fetched at runtime. The administrator handing you that file is the out-of-band step, so
there is no thumbprint to pin against it: a key already in your hand cannot usefully be checked
against itself.

### What it does and does not prove

Encryption gives **confidentiality and integrity**, not identity. The platform's public key is
public — anybody can encrypt to it. Who is calling is proved by the three credential headers, which
stay *outside* the envelope so a request can be attributed and refused before anything is decrypted.

The signature, if you use one, covers the **ciphertext** — the SDK encrypts and then signs, in that
order, because the platform verifies before it decrypts.

Reads are not encrypted. A GET has no body, and its parameters travel in the query string; the
platform offers no way to hide those and this SDK does not pretend otherwise.

### When the key is rotated

Generating a new pair on your application's screen publishes it and marks the old one *superseded* —
still able to decrypt, so nothing breaks the moment it happens. Replace the file, and the old key is
retired once you have moved.

Every envelope carries a `kid`, the SHA-256 thumbprint of the key it was encrypted to, which is how
the platform picks the right one during that overlap. The SDK derives it from the key you configured
— including when you configured a certificate, because hashing the certificate instead would name a
key the platform cannot find and would fail *only* during a rotation.

### The awkward bit, if you are writing another client

PHP's `openssl_public_encrypt()` is hard-wired to **SHA-1** OAEP and the platform requires SHA-512,
so this SDK implements RFC 8017 padding by hand — and checks it against the `openssl` command line
in both directions rather than against its own inverse. That is not caution for its own sake: a
deliberately broken MGF1 counter round-trips through itself perfectly and is caught only by the
interop test.

`docs/payload-encryption.md` in the platform repo has the full wire contract, including the
per-language OAEP incantations. Java's is the one that catches people.

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
| `GenericFailure` | anything else — including a redirect, refused rather than followed | never |
| `EncryptionFailed` | a key is missing, a pinned thumbprint did not match, a reply would not open | never |

`EncryptionFailed` is deliberately **not** a `CoreAccountingException`: those describe something the
ledger said, and this one means the request never reached it or its answer never got back.

`PermissionDenied` also answers `needsSignature()`, `signatureExpired()` and
`signatureDidNotMatch()` — see **Request signing** above, since all three arrive as 403 and are
fixed by different people.

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
