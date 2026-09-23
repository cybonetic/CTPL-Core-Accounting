<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Exceptions;

/**
 * A failure this SDK does not have a name for.
 *
 * Reached when the platform returns an `error.code` newer than this package
 * knows about and a status that maps nowhere in particular. It carries the code
 * and the message through unchanged rather than guessing at a meaning, so an
 * application can still log something useful and a reader can still see what
 * the platform actually said.
 */
class GenericFailure extends CoreAccountingException
{
}
