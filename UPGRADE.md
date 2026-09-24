# SDK delta — payload encryption

Eight files, on top of the version with request signing. No new composer dependency, and
encryption is **off by default**, so dropping these in changes nothing until you switch it on.

```
src/Crypto/Oaep.php                 new   RFC 8017 padding, because PHP's is SHA-1 only
src/Crypto/Envelope.php             new   seal and open the hybrid envelope
src/Crypto/PlatformKey.php          new   reads the key you were given
src/Exceptions/EncryptionFailed.php new
src/Http/Connection.php             changed  encrypt, then sign; decrypt the reply
config/core-accounting.php          changed  the encryption block
README.md                           changed
tests/Feature/EncryptionTest.php    new
```

---

## Switching it on

```ini
CORE_ACCOUNTING_ENCRYPT=true
CORE_ACCOUNTING_PLATFORM_KEY_PATH=/var/lib/yourapp/keys/core-accounting.pem
CORE_ACCOUNTING_PRIVATE_KEY_PATH=/var/lib/yourapp/keys/app.key
```

Two key pairs, and they are not interchangeable:

**Core Accounting's** — one per application. An administrator generates it on your application's
screen in the back office and gives you the public half. Put that file in
`CORE_ACCOUNTING_PLATFORM_KEY_PATH`, or the PEM itself in `CORE_ACCOUNTING_PLATFORM_KEY`. A bare
public key or the self-signed certificate over it both work; the SDK takes the key out of the
certificate.

**Yours** — you generate it, and its public half is uploaded on that same screen so replies can be
encrypted back to you.

```bash
openssl genrsa -out app.key 4096 && chmod 400 app.key
openssl rsa -in app.key -pubout -out app.pub
```

Republish the config, or add the `encryption` block by hand:

```bash
php artisan vendor:publish --tag=core-accounting-config --force
```

---

## Nothing is fetched

The SDK does not call `/api/v1/crypto/public-key`. The administrator handing you the key file is
the out-of-band step, so there is no thumbprint to pin against it — a key already in your hand
cannot usefully be checked against itself.

There is a test asserting the endpoint is never called, because an earlier draft of this SDK did
fetch the key and the endpoint still exists. A reintroduced fetch would pass every other test — the
key would arrive, the payload would encrypt — while quietly putting the trust decision back on the
wire.

---

## Order of operations

Encrypted **first**, signed **second**. The platform verifies the signature before it decrypts, so
the signature covers the ciphertext. Signing the plaintext and then encrypting produces a signature
over bytes the platform never sees, and every request is refused with a message about the payload
rather than about the order.

Reads are not encrypted — a GET has no body, and its query string is not something the platform
offers a way to hide.

---

## Rotation

Generating a new pair on your application's screen publishes it and marks the old one *superseded* —
still able to decrypt, so nothing breaks at the moment it happens. Swap the file; the old key is
retired once you have moved.

Every envelope carries a `kid`, the SHA-256 thumbprint of the **public key** it was encrypted to,
and that is how the platform picks the right one during the overlap. The SDK derives it from the key
you configured, taking the key out of a certificate first if that is what you gave it.

That last detail matters more than it reads. Hashing the certificate instead produces a different
digest, so the platform would not find the key named — and it would still work, by falling back to
whichever key is current, right up until a rotation makes that fallback the wrong key. The failure
would surface weeks later, during the one operation the overlap exists to make safe. There is a test
pinning it, proved by making the mistake deliberately.

---

## Verified

56 tests, up from 41.

`the_padding_agrees_with_openssl_in_both_directions` is the one worth knowing about. PHP cannot do
OAEP-SHA512 through `openssl_public_encrypt()`, so the padding is written by hand — and hand-written
padding tested against its own inverse passes every time while being wrong. Proved by breaking the
MGF1 counter to little-endian: it still round-trips through itself perfectly, and only the OpenSSL
interop test catches it.

Also proved by reverting: signing the plaintext instead of the ciphertext, and deriving the `kid`
from a certificate rather than from the key inside it.
