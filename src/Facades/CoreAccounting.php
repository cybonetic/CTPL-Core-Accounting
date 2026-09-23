<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Facades;

use Ctpl\CoreAccounting\Resources\Customers;
use Ctpl\CoreAccounting\Resources\Invoices;
use Ctpl\CoreAccounting\Resources\Notes;
use Ctpl\CoreAccounting\Resources\Receipts;
use Ctpl\CoreAccounting\Resources\Receivables;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Customers customers()
 * @method static Invoices invoices()
 * @method static Notes notes()
 * @method static Receipts receipts()
 * @method static Receivables receivables()
 * @method static \Ctpl\CoreAccounting\CoreAccounting forCompany(int $companyId)
 *
 * @see \Ctpl\CoreAccounting\CoreAccounting
 */
class CoreAccounting extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ctpl\CoreAccounting\CoreAccounting::class;
    }
}
