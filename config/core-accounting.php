<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where the ledger is
    |--------------------------------------------------------------------------
    |
    | The production installation, compiled in. An application that installs
    | this package and sets its three credentials is pointed at the real ledger
    | without anyone having to know the address, and a blank or mistyped
    | CORE_ACCOUNTING_URL in somebody's .env cannot quietly send invoices
    | nowhere.
    |
    | The environment variable still overrides it, for staging and for local
    | work. Set it deliberately; leaving it unset is the correct default.
    |
    */

    'base_url' => env('CORE_ACCOUNTING_URL') ?: 'https://cacc.cybonetic.com',

    'prefix' => env('CORE_ACCOUNTING_PREFIX', 'api/v1'),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | The three values an administrator gave you when they registered this
    | application. The secret is shown ONCE at registration and stored only as
    | a hash, so if it is lost the answer is a new key rather than a lookup.
    |
    | These belong in .env and nowhere else - not in this file, not in a seeder,
    | not in version control.
    |
    */

    'app_id' => env('CORE_ACCOUNTING_APP_ID'),

    'key' => env('CORE_ACCOUNTING_KEY'),

    'secret' => env('CORE_ACCOUNTING_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Which legal entity
    |--------------------------------------------------------------------------
    |
    | Every accounting call names the company it applies to, and the platform
    | will not guess - an application may act for several. Set the usual one
    | here; override per call with `->forCompany($id)` where an application
    | genuinely serves more than one.
    |
    */

    'company_id' => env('CORE_ACCOUNTING_COMPANY_ID'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | `timeout` is deliberately not generous. An invoice that takes longer than
    | this is one the ledger is struggling with, and holding a web request open
    | waiting for it makes your application slow in sympathy. Let it fail, let
    | the retry or the queue deal with it.
    |
    */

    'timeout' => (int) env('CORE_ACCOUNTING_TIMEOUT', 15),

    'connect_timeout' => (int) env('CORE_ACCOUNTING_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | Only failures that might go away are retried: a timeout, a refused
    | connection, a 5xx, a 429. A 422 repeated is the same 422, and a 403
    | repeated is the same 403 - retrying those spends round trips to be told
    | the same thing.
    |
    | The delay doubles each time and carries jitter. Without the jitter every
    | application that failed against the same restart comes back in lockstep
    | and arrives together, which is how a ledger that just came up goes down
    | again.
    |
    */

    'retry' => [
        'times' => (int) env('CORE_ACCOUNTING_RETRY_TIMES', 3),
        'base_delay_ms' => (int) env('CORE_ACCOUNTING_RETRY_DELAY_MS', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deferring a write that could not be delivered
    |--------------------------------------------------------------------------
    |
    | With this on, a write that exhausts its retries is handed to a queued job
    | carrying the SAME idempotency key, so your application never loses an
    | invoice because the ledger was being restarted.
    |
    | `LedgerUnavailable` is still thrown - your request cannot be given an
    | invoice number that does not exist yet - but `wasQueued()` on it is true,
    | which is what decides whether you tell the person "it will appear shortly"
    | or "nothing was recorded". When the queued write finally lands, a
    | `DocumentRecorded` event fires with the document and your original key.
    |
    | Requires a queue worker. On the `sync` driver this is pointless: the job
    | runs immediately, in the same process, against the same unreachable
    | ledger.
    |
    */

    'defer_writes' => (bool) env('CORE_ACCOUNTING_DEFER_WRITES', true),

    'queue' => [
        'connection' => env('CORE_ACCOUNTING_QUEUE_CONNECTION'),
        'queue' => env('CORE_ACCOUNTING_QUEUE', 'default'),
        // How long the job keeps trying before giving up and failing loudly.
        // A day, because the usual cause is a deployment or a restart and the
        // usual duration of those is minutes.
        'retry_hours' => (int) env('CORE_ACCOUNTING_QUEUE_RETRY_HOURS', 24),
    ],

];
