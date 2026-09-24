# SDK delta — request signing

Seven files, on top of the version that had `https://cacc.cybonetic.com` compiled in. No new
dependency, no interface change to anything you already call.

```
src/Http/Signature.php              new
src/Http/Connection.php             changed  — serialise the body once, sign it, send that string
src/Http/Envelope.php               changed  — names the fix for each kind of 403
src/Exceptions/PermissionDenied.php changed  — needsSignature / signatureExpired / signatureDidNotMatch
config/core-accounting.php          changed  — signing_secret
README.md                           changed
tests/Feature/SigningTest.php       new
```

Copy them over your installed copy, or bump the package if you have it in a VCS repository.

---

## Using it

One line in the consuming application's `.env`:

```ini
CORE_ACCOUNTING_SIGNING_SECRET=...
```

That is all. The SDK signs every request from then on — there is nothing to call and no argument to
pass. With the variable unset it sends no signature, which is correct for a credential that does not
require one.

If you publish the config file, re-publish it or add the `signing_secret` key by hand:

```bash
php artisan vendor:publish --tag=core-accounting-config --force
```

The secret is a **fourth** value, separate from the three credentials. An administrator issues it
from **Set up → Cloud applications → \[the app\] → Request signing** in the back office, and it is
shown once.

---

## What changed inside, and why it matters

`Connection` now serialises the request body **once** and sends that exact string with
`withBody()`, rather than handing an array to the HTTP client.

That is not tidying. The platform recomputes the signature from the bytes that actually arrived, so
a body encoded twice — once to sign, once to send — produces a signature that is wrong by one
escaped slash or one unicode character. Every request is then refused with *"the request signature
does not match the payload"* while the body in your log looks perfectly correct. People lose days
to it.

A GET carries no body and signs `"<timestamp>."` — the separator stays. Getting that wrong gives
you a client whose writes all succeed and whose reads are all refused.

Retries re-sign, because the timestamp is inside the signed string and the platform allows ±300
seconds. The idempotency key does **not** change — that is what makes a retry replay the first
write rather than raise a second invoice.

---

## Telling three 403s apart

```php
} catch (PermissionDenied $e) {
    $e->needsSignature();        // no secret configured — set the env var
    $e->signatureExpired();      // clock drift, not a wrong secret; check NTP
    $e->signatureDidNotMatch();  // the bytes disagree, not the secret
}
```

All three arrive as HTTP 403 and are fixed by different people. Without the distinction an
application shows "ask an administrator for a permission" when the answer is a missing line in its
own configuration.

The messages now carry the instruction too, so a log line is enough on its own.

---

## Verified

41 tests pass, up from 33. The one that matters is
`the_signature_covers_the_exact_bytes_sent`: it recomputes the HMAC from `$request->body()` — what
actually went on the wire — over a payload containing `09/2026` and `₹`, which are precisely the
characters two JSON encoders disagree about.

Proved by reverting: letting the HTTP client encode the body separately, and dropping the `.`
separator. Both go red.

The platform side has a matching test
(`CredentialSecurityTest::the_signature_the_sdk_computes_is_the_one_this_platform_accepts`) that
builds the header this way and sends it as raw bytes through the real middleware, so the two sides
are checked against each other rather than each against its own idea of the format.
