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
    | Request signing
    |--------------------------------------------------------------------------
    |
    | A FOURTH secret, separate from the three above, and only issued to
    | credentials registered with signing switched on. Leave it blank if yours
    | was not: the SDK then sends no signature, which is correct rather than
    | merely permitted.
    |
    | What it buys is different from what the credentials buy. The credentials
    | prove who is calling. The signature proves that the body is the body that
    | caller sent - so something holding a stolen key cannot alter an invoice on
    | its way through a proxy.
    |
    | If a request is refused with "This credential requires a signed request",
    | this is the blank line. An administrator can show it again from the
    | application's screen in the back office.
    |
    */

    'signing_secret' => env('CORE_ACCOUNTING_SIGNING_SECRET'),

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
    | Payload encryption
    |--------------------------------------------------------------------------
    |
    | Encrypts the request body on top of TLS, so that nothing between this
    | application and Core Accounting can read an invoice - including anything
    | that terminates TLS in the middle.
    |
    | Off by default. Turning it on needs two things in place: a Core Accounting
    | key pair for THIS application, which an administrator generates on its
    | screen in the back office, and a key pair of your own whose public half
    | you upload there so replies can be encrypted back to you.
    |
    | The two are easy to confuse and they are not interchangeable:
    |
    |   public_key       Core Accounting's. You encrypt TO it.
    |   private_key      Yours. You decrypt replies WITH it. It never leaves here.
    |
    */

    'encryption' => [

        'enabled' => (bool) env('CORE_ACCOUNTING_ENCRYPT', false),

        /*
        | Core Accounting's public key for THIS application - the key you
        | encrypt to.
        |
        | An administrator generates the key pair on this application's screen
        | in the back office and hands you the public half. Either the bare key
        | or the self-signed certificate over it will do; both are in the export
        | archive from that screen, and the SDK takes the key out of the
        | certificate if that is what it is given.
        |
        | Nothing is fetched at runtime. The administrator handing you this file
        | is the out-of-band step, so there is no thumbprint to pin against it -
        | a key already in your hand cannot usefully be checked against itself.
        */
        'public_key' => env('CORE_ACCOUNTING_PLATFORM_KEY'),

        'public_key_path' => env('CORE_ACCOUNTING_PLATFORM_KEY_PATH'),

        /*
        | Normally left alone.
        |
        | The `kid` in every envelope is the SHA-256 thumbprint of the key
        | above, which the SDK works out for itself. Set this only if Core
        | Accounting ever publishes an identifier that is not that - in which
        | case an envelope naming the wrong one still decrypts, by falling back
        | to whatever key is current, right up until a rotation makes the
        | fallback the wrong key.
        */
        'kid' => env('CORE_ACCOUNTING_PLATFORM_KEY_ID'),

        /*
        | THIS application's private key, which opens the replies.
        |
        | A file at 0400 outside the document root, or injected inline where a
        | platform has nowhere to put a file. Core Accounting holds only the
        | matching public half, which you uploaded on the same screen.
        */
        'private_key_path' => env('CORE_ACCOUNTING_PRIVATE_KEY_PATH'),

        'private_key' => env('CORE_ACCOUNTING_PRIVATE_KEY'),

    ],

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
