<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Exceptions;

/**
 * The payload could not be encrypted, or a reply could not be decrypted.
 *
 * ---
 *
 * **Not a `CoreAccountingException`**, and the distinction is the point: those
 * describe something the ledger said. This one means the request never reached
 * it, or its answer never got back - a key that is missing, a thumbprint that
 * does not match what was pinned, padding that would not decode.
 *
 * It is also **never retried**. Retrying a wrong key produces the same wrong
 * key, and on a write it would spend the idempotency window doing so.
 */
class EncryptionFailed extends \RuntimeException
{
}
