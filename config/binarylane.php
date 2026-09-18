<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Account
    |--------------------------------------------------------------------------
    |
    | Which entry under "accounts" a call without a name uses: BinaryLane::servers()
    | is BinaryLane::client(<this>)->servers(). An application managing one account
    | never needs to name it.
    |
    */

    'default' => env('BINARYLANE_ACCOUNT', 'main'),

    /*
    |--------------------------------------------------------------------------
    | Accounts
    |--------------------------------------------------------------------------
    |
    | One entry per BinaryLane account, named however you like -- the name is what
    | BinaryLane::client('...') takes. Each needs an API token and nothing else:
    | there is one BinaryLane, so there is no URL to configure per account.
    |
    | A TOKEN CAN DO EVERYTHING ITS ACCOUNT CAN DO. BinaryLane issues one kind of
    | API token: no scopes, no expiry, and no read-only variant. A token placed here
    | for a dashboard that only lists servers is a token that can cancel every
    | server on the account, rebuild them, and change their DNS. There is no
    | narrower credential to use instead, so keep it out of anything that does not
    | need it -- and treat it like a root password, because it is closer to one than
    | to an API key.
    |
    | An account with no token is refused when its client is built rather than
    | allowed to 401 on first use. Every BinaryLane endpoint needs a credential.
    |
    */

    'accounts' => [

        'main' => [
            'token' => env('BINARYLANE_API_TOKEN'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Page Size
    |--------------------------------------------------------------------------
    |
    | How many items a list request asks for when the caller does not say, from 1
    | to 200. Null uses the API's own default, which is 20 -- smaller than most
    | people expect, so a list that looks complete is often the first page of
    | several. The endpoints' each() methods walk every page regardless.
    |
    */

    'per_page' => env('BINARYLANE_PER_PAGE'),

    /*
    |--------------------------------------------------------------------------
    | Base URI
    |--------------------------------------------------------------------------
    |
    | The API root WITHOUT the version segment. Null uses BinaryLane's own host,
    | which is what all but two situations want: a recorded fixture served locally,
    | and an outbound proxy that terminates the connection.
    |
    */

    'base_uri' => env('BINARYLANE_API_URL'),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Applied to Laravel's HTTP client on every request, alongside a consumer's own
    | Http::globalRequestMiddleware() and the transport settings from
    | Http::globalOptions() -- proxy, TLS, protocol version, curl options. Global
    | headers, auth, query and body options are not applied: they would change the
    | request the core package built.
    |
    | These bound ONE REQUEST, not the work it starts. A server build or a resize
    | answers within the timeout with an action to poll; how long to wait for that
    | action is AwaitAction's timeout, not this one.
    |
    | Creating a DNS zone is the exception: it does its work inside the request,
    | takes about a minute, and BinaryLane's gateway answers 504 at 60 seconds. No
    | timeout here sees it succeed, and the zone is created whichever error arrives.
    | Read it back, or create it again: a duplicate is refused with a 400.
    |
    */

    'timeout' => env('BINARYLANE_TIMEOUT', 10),

    'connect_timeout' => env('BINARYLANE_CONNECT_TIMEOUT', 5),

];
