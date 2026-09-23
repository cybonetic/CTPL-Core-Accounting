<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Exceptions;

/** HTTP 409 - It already exists, or is already in that state. */
class Conflict extends CoreAccountingException
{
}
