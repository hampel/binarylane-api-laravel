# BinaryLane API for Laravel

[![Tests](https://github.com/hampel/binarylane-api-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/hampel/binarylane-api-laravel/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hampel/binarylane-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/binarylane-api-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/hampel/binarylane-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/binarylane-api-laravel)
[![Open Issues](https://img.shields.io/github/issues-raw/hampel/binarylane-api-laravel.svg?style=flat-square)](https://github.com/hampel/binarylane-api-laravel/issues)
[![License](https://img.shields.io/packagist/l/hampel/binarylane-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/binarylane-api-laravel)

By [Simon Hampel](mailto:simon@hampelgroup.com)

Laravel integration for [`hampel/binarylane-api`][core] — a service provider, a manager for
named accounts, a facade, and a queued job that waits for BinaryLane's actions to finish.

Three things it adds that an application would otherwise write for itself:

- **`Http::fake()` sees the API client's traffic.** The core package carries its own PSR-18
  client, so by default Laravel's HTTP fakes know nothing about it. Here every request goes
  through Laravel's own handler stack, so `Http::fake()`, `Http::assertSent()` and
  `Http::preventStrayRequests()` all work.
- **Named accounts.** A token per account, a default, and `BinaryLane::client('name')` to reach
  one — the shape Laravel's own database and mail managers take.
- **Waiting for an action without blocking a request.** Nearly every change on this API answers
  with an action to poll rather than a result. `AwaitAction` polls it on the queue and fires an
  event saying how it ended.

The request building, status mapping and exception hierarchy are the core package's, untouched:
a 401 and a 404 stay different exceptions rather than both becoming an unsuccessful response.

## Requirements

PHP 8.3 or later, and Laravel 12 or 13.

Laravel Zero works too, with the HTTP component installed (`php <app> app:install http`). Laravel
binds `Illuminate\Http\Client\Factory` as a singleton in `FoundationServiceProvider`, which a
Laravel Zero application does not register; unbound, `Http::fake()` silently fails to intercept
and the request reaches the real API. This package binds one when nothing else has, so the
behaviour is the same on both. `AwaitAction` additionally needs the queue component.

## Installation

```bash
composer require hampel/binarylane-api-laravel
```

In a Laravel application the provider and the `BinaryLane` alias are discovered automatically.
Publish the config file if you want to edit it:

```bash
php artisan vendor:publish --tag=binarylane-config
```

**Laravel Zero does not run package discovery**, so there the provider has to be listed by hand, in
`config/app.php`:

```php
'providers' => [
    Hampel\BinaryLane\Api\Laravel\BinaryLaneServiceProvider::class,
],
```

The global `BinaryLane` alias is not registered either. Import the facade class —
`use Hampel\BinaryLane\Api\Laravel\Facades\BinaryLane;` — or inject `BinaryLaneManager`.

Laravel Zero has no `vendor:publish` either. The environment variables below cover the shipped
configuration; to change its structure — to add a second account, say — copy
`vendor/hampel/binarylane-api-laravel/config/binarylane.php` to `config/binarylane.php`.

## Configuration

An account needs a token and nothing else — there is one BinaryLane, so there is no URL to
configure.

```dotenv
BINARYLANE_API_TOKEN=your-api-token
```

**A BinaryLane token can do everything its account can do.** There is one kind of token: no
scopes, no expiry, no read-only variant. A token configured for a dashboard that only lists
servers can also cancel them. Keep it out of anything that does not need it.

The shipped `config/binarylane.php` defines one account called `main`. Add more by naming them:

```php
'default' => 'production',

'accounts' => [
    'production' => [
        'token' => env('BINARYLANE_API_TOKEN'),
    ],

    'reseller' => [
        'token' => env('RESELLER_BINARYLANE_API_TOKEN'),
    ],
],
```

**An account with no token is refused when its client is built**, rather than allowed to reach
the API and come back 401 — which would read as a revoked token rather than as an unset
environment variable. An empty string counts as no token. It raises
`Hampel\BinaryLane\Api\Laravel\Exception\InvalidConfiguration`, which extends the core package's
`BinaryLaneException`.

### Page size and base URI describe the API, not an account

```php
'per_page' => env('BINARYLANE_PER_PAGE'),   // 1 to 200; null uses the API's default of 20
'base_uri' => env('BINARYLANE_API_URL'),    // null uses BinaryLane's own host
```

Both are shared by every account's client and validated when the first client is built.
**A `per_page` of 0 is refused**, although the API accepts it: it asks for a total and no items,
which as a default would make every list come back empty. `base_uri` exists for a recorded
fixture served locally and for an outbound proxy that terminates the connection.

### Transport

```php
'timeout' => 10,
'connect_timeout' => 5,
```

Applied to every request, alongside any `Http::globalOptions()` and
`Http::globalRequestMiddleware()` the application has configured. These bound one request, not
the work it starts — how long to wait for an action is `AwaitAction`'s timeout.

## Usage

The facade reaches the default account directly:

```php
use Hampel\BinaryLane\Api\Laravel\Facades\BinaryLane;

$account = BinaryLane::verify();               // does this token work, and is the account usable?

$servers = BinaryLane::servers()->list();      // the first page - 20 unless configured otherwise
$server = BinaryLane::servers()->get(1234);
```

Name an account to reach another:

```php
$servers = BinaryLane::client('reseller')->servers()->list();
```

**It is `client()` and not `account()`.** `BinaryLane::account()` is the core package's account
endpoint — `GET /v2/account` — and the manager forwards unknown calls to the default client, so a
method on both would mean different things depending on which class you thought you were calling.

Everything past that point is the core package — see [its documentation][core] for the endpoints,
entities, pagination, server creation and the complete-or-error pattern of question-shaped
actions.

Inject the manager where a facade is not wanted:

```php
use Hampel\BinaryLane\Api\Laravel\BinaryLaneManager;

public function __construct(private readonly BinaryLaneManager $binarylane) {}

$this->binarylane->client('reseller')->servers()->list();
```

## Waiting for an action happens on the queue, not in the request

**A server action returns a receipt, not a result.** Powering a server on, resizing it,
rebuilding it and creating it all answer with an action that has not finished yet. The core
package's `actions()->await()` polls until it does, which blocks — right in a console command,
wrong in a web request, where a server build would hold the request open for minutes.

`AwaitAction` is that wait on the queue:

```php
use Hampel\BinaryLane\Api\Laravel\Jobs\AwaitAction;

$action = BinaryLane::serverActions()->powerOn(1234);

if ($action !== null) {
    dispatch(new AwaitAction($action));
}
```

**Check for `null`.** Every server action may answer with a bodiless 202 and no action to wait
on. The job takes an action or an id and nothing else, and an id below 1 is refused when it is
constructed — in the request that dispatched it, rather than later in a worker's log.

A server build names its actions rather than returning one:

```php
$created = BinaryLane::servers()->create($request);

foreach ($created->actionIds() as $id) {
    dispatch(new AwaitAction($id, timeout: 7200));
}
```

The constructor takes the action or its id, then optionally the account name, a timeout and a
polling interval:

```php
new AwaitAction($action, account: 'reseller', timeout: 3600, interval: 10);
```

| argument | default | meaning |
|---|---|---|
| `account` | the default account | which configured account the action belongs to |
| `timeout` | 3600 | seconds from dispatch to give up, time in the queue included |
| `interval` | 10 | seconds between polls; at most 900 on Amazon SQS |

### How it ends is an event

| when the action | the job fires | and |
|---|---|---|
| completed | `ActionCompleted` | succeeds |
| errored | `ActionFailed` | succeeds — an errored action stays errored, so there is nothing to retry |
| is waiting on a question or an unpaid invoice | `ActionBlocked` | succeeds — neither resolves by waiting |
| was still running at the deadline | `ActionTimedOut` | succeeds — nothing was cancelled |

All four live in `Hampel\BinaryLane\Api\Laravel\Events`, and each carries the `account` name and
the `action` as last seen; `ActionTimedOut` also carries `waited`, in seconds. Listen for the ones
you care about:

```php
use Hampel\BinaryLane\Api\Laravel\Events\ActionBlocked;
use Hampel\BinaryLane\Api\Laravel\Events\ActionCompleted;
use Hampel\BinaryLane\Api\Laravel\Events\ActionFailed;
use Illuminate\Support\Facades\Event;

Event::listen(function (ActionCompleted $event) {
    // $event->action->resourceId is the server; $event->action->type is what was done to it
});

Event::listen(function (ActionFailed $event) {
    // $event->action->failureReason() when BinaryLane gave one - often it did not
});

Event::listen(function (ActionBlocked $event) {
    // answer with actions()->proceed(), or pay invoice $event->action->blockingInvoiceId,
    // then dispatch a new AwaitAction if the outcome still matters
});
```

**The events share no parent class, deliberately.** Laravel matches a listener by class and by
interface, never by parent class, so a listener on a common base would hear nothing.

### When it cannot find out, it retries or fails

**A poll that failed in a way the next one may not repeat is retried until the deadline** — a 5xx,
a 429, a transport failure, a malformed response, or an answer about some other action. A 429's
`Retry-After` is honoured when it is longer than the interval. Past the deadline, the job fails.

**Anything else fails the job at once**, because asking again will not change it: a token that is
not valid, an action id that does not exist on the account, an account name no longer in the
configuration. A failed job is reported and lands in `failed_jobs` like any other.

### Three things it needs from the queue

- **A real queue connection.** The job polls by releasing itself with a delay, which the `sync`
  driver cannot do, so it raises `QueueRequired` there — on every run, including one where the
  action happened to be finished already, so it cannot appear to work in development and then
  fail silently in production.
- **Nothing about `--tries`.** The job sets `$tries = 0`, which is unlimited: every release counts
  as an attempt, and `queue:work`'s own default of one try would otherwise fail the second poll.
  The deadline is the limit instead.
- **No credential in the payload.** The job carries the account *name* and resolves it when it
  runs. It never serialises a client or a token.

It is tagged `binarylane` and `binarylane:action:<id>` for Horizon.

## Errors

The core package's exceptions arrive untouched:

```php
use Hampel\BinaryLane\Api\Exception\NotAuthenticatedException;
use Hampel\BinaryLane\Api\Exception\NotFoundException;
use Hampel\BinaryLane\Api\Exception\ValidationException;

try {
    $server = BinaryLane::servers()->get($id);
} catch (NotFoundException $e) {
    // no such server on this account
} catch (NotAuthenticatedException $e) {
    // the token is no good. A configuration error, not an empty result.
}
```

`UnknownAccount`, `InvalidConfiguration` and `QueueRequired` extend the core package's
`BinaryLaneException`, so an application already catching that catches these too.

## Testing

Fake the API with the vocabulary the rest of your suite already uses:

```php
use Illuminate\Support\Facades\Http;

Http::preventStrayRequests();

Http::fake([
    'api.binarylane.com.au/*' => Http::response([
        'server' => ['id' => 1234, 'name' => 'vps01.example.com'],
    ]),
]);

$server = BinaryLane::servers()->get(1234);

Http::assertSent(fn ($request) => $request->hasHeader('Authorization'));
```

The package's real code path runs; only the socket is replaced. So a faked 404 still arrives as
`NotFoundException`, and a faked 200 whose body is HTML still arrives as
`MalformedResponseException`.

- **Give every fake a body.** `Http::fake()` with no arguments answers every request with an empty
  200, which raises `MalformedResponseException` — only a 202 and a 204 are successes with no body
  on this API. A body without the expected envelope key raises it too.
- **Anything that awaits needs one response per poll.** Queue them with `Http::fakeSequence()`,
  and pass `await()` a `wait:` callable so the test does not sleep.
- **Server actions are told apart by their body.** They are all one `POST` to
  `servers/{id}/actions`, so assert on `$request['type']`, which works because the core package
  writes `application/json`.
- **Order does not matter.** Faking after the client has been resolved works, because the
  transport reads Laravel's HTTP factory at the moment of sending.

To test your own handling of `AwaitAction`, call `handle()` on a job with fake queue interactions,
which records releases and failures rather than ignoring them:

```php
$job = (new AwaitAction(5001))->withFakeQueueInteractions();

$job->handle(app(BinaryLaneManager::class), app('events'), app('log'));

$job->assertReleased(10);
```

Replace the transport entirely by binding `Psr\Http\Client\ClientInterface` — how an application
with its own outbound HTTP policy makes this package use it.

### What Laravel's HTTP events see

**`RequestSending` fires; `ResponseReceived` and `ConnectionFailed` do not.** Laravel raises the
first from inside the handler stack this package sends through, and the other two from a layer
above it. So Telescope's HTTP client watcher, which listens for `ResponseReceived`, will not show
this traffic. The core package logs every request at `debug` and every failure at `error` through
PSR-3, which reaches the application log. The token is never logged.

## Versioning

`hampel/binarylane-api` is 0.x, so its public API can change in a minor release; this package
constrains it at `^0.2` and expects to bump.

## License

MIT. See [LICENSE.md](LICENSE.md).

[core]: https://github.com/hampel/binarylane-api
