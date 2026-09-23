<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Exceptions;

/** HTTP 403 - The application lacks a permission this call needs. */
class PermissionDenied extends CoreAccountingException
{
}
