# hampel/binarylane-api-laravel

Laravel integration for `hampel/binarylane-api`. A service provider, a manager for named accounts,
a facade, one adapter that is the reason the package exists — and a queued job, which is the one
thing here that is not wiring.

## Commands

```bash
composer check          # lint, analyse, test - what CI runs
composer test           # phpunit
composer analyse        # phpstan, level 10 with larastan, PHP 8.3-8.5 in one pass
composer format         # pint
```

## Layout

| path | what it is |
|---|---|
| `src/Http/PendingRequestClient.php` | the PSR-18 adapter over Laravel's HTTP client |
| `src/BinaryLaneManager.php` | one client per configured account, memoised |
| `src/BinaryLaneServiceProvider.php` | the bindings, the merged config, the publish tag |
| `src/Facades/BinaryLane.php` | the facade, and the `@method` block that types it |
| `src/Jobs/AwaitAction.php` | polls an action on the queue, one request per attempt |
| `src/Events/` | the four ways an awaited action ends |
| `src/Exception/` | configuration and queue failures, in the core package's hierarchy |
| `config/binarylane.php` | the published config |

## The transport lives under `binarylane.http_client`, never the shared PSR-18 key

**The provider binds `PendingRequestClient` under `BinaryLaneServiceProvider::HTTP_CLIENT` with
`singletonIf()`, and builds the manager from that key alone.** `singletonIf()` because Laravel Zero
lists `AppServiceProvider` before a package provider, so `singleton()` would silently replace an
application's override there; `HttpClientOverrideTest` fails with `singleton()`.

It does not bind `Psr\Http\Client\ClientInterface`, and does not read it. That key is shared:
every API wrapper that bound it with `singleton()` replaced the one before, so in an application
with several wrappers installed the last provider registered supplied the transport — and the
timeouts — for all of them. Measured in a real application with three wrappers installed, where
one package's `timeout` governed another's requests.

**Do not add a fallback to `ClientInterface` when it is bound.** It reads as a kindness to an
application with its own client, and brings the collision straight back: an older wrapper, or an
unrelated library, may be what bound it. `SharedPsr18BindingTest` registers a stub provider that
binds the shared key, before and after this one, and asserts the manager keeps its own adapter and
timeout; it was red against the old binding in the "after" order. The same
`<config key>.http_client` convention was agreed for the sibling Cloudflare, Linode and XenForo
wrappers.

## `Http::fake()` reaching the core package's traffic is the claim everything rests on

The core package holds its own PSR-18 client, so nothing it sends is visible to `Http::fake()`.
`PendingRequestClient` replaces that client with one that sends through Laravel's handler stack,
while the core package's request building, status mapping and exception hierarchy stay untouched.
`tests/HttpFakeTest.php` is that claim, asserted.

Four decisions in the adapter are load-bearing, and its docblock carries the reasoning for each:
the pending request is **rebuilt on every send** (`Factory::fake()` replaces the stub collection,
so a cached request holds a snapshot), from a factory **resolved from the container on every
send** (`Http::swap()` rebinds it there, and a held factory sent past the new fakes and the new
`preventStrayRequests()` for real); **one Guzzle handler is shared** across those rebuilds so
keep-alive survives; and it calls **`send()` with four options set by hand**, because Laravel 12
reads `laravel_data` and `on_stats` without a default and `http_errors` must stay off or a 404
arrives as a transport failure. **Laravel 13 reads the first two defensively and 12 does not**, so
the Laravel 12 CI job is the one that catches their removal.

And **the pending request's own options are passed on by hand, from an allowlist.** `send()` on the
built client never reads them — `PendingRequest` merges them only inside its own `sendRequest()` —
so without this the configured `timeout` and `connect_timeout`, and every `Http::globalOptions()`
setting, silently never reach a request. `transportOptions()` passes timeouts, TLS, proxy,
protocol version and curl settings, each only when its value has the type Guzzle declares, and
never `headers`, `auth`, `query` or a body, which would rewrite the core package's request.
`TransportTest` pins both halves; each was probed by breaking it — nothing passed fails the two
"reaches the request" tests, everything passed fails the "not applied" one.

## AwaitAction polls by releasing itself, and every part of that is load-bearing

Read the class docblock before changing it. The short form:

- **One request per attempt, never a sleep.** `await()` with a timeout of 0 classifies the action;
  still running means `release()` with a delay. A worker is never blocked, so a long build cannot
  hit a worker timeout. Do not reintroduce a blocking wait.
- **`$tries = 0` is unlimited, and removing it breaks the job on the second poll.** Every release
  counts as an attempt and `queue:work` defaults to `--tries=1`. The job's own deadline is the
  limit — not `retryUntil()`, which fails a job still waiting in the queue without running it.
  `QueueWorkerTest` drives the real worker with `--tries=1` on a database queue; deleting the
  property turns it red, which was probed rather than assumed.
- **The deadline is fixed at dispatch**, so time in the queue counts against it.
- **The sync driver is refused on every run** (`QueueRequired`), even when the action is already
  finished, because a release there does nothing and the job would work only for fast actions.
- **Outcomes are events, and the job succeeds for all four.** An errored action, a blocked one and
  one past the deadline are observations, not job failures — a failed job would be retried by
  `queue:retry` to get the same answer.
- **Those three are also logged at `warning`, by the job.** From core 0.3 the core logs nothing
  above `debug` — the catcher decides whether an exception is a failure — and this job catches
  `ActionFailedException` and `ActionBlockedException`. Without its own line, an application
  listening for no events would never hear that a rebuild errored. The context carries names and
  ids only. `AwaitActionTest::the_outcomes_that_need_a_person_are_logged_at_warning` was probed by
  downgrading the three calls to `debug`.
- **Failures to find out are split by exception type.** 5xx, 429, transport and malformed
  responses release until the deadline, honouring `Retry-After`; everything else calls
  `$this->fail()` and rethrows. Both halves matter: `fail()` stops the worker releasing it forever
  under unlimited tries, and the rethrow keeps the exception reaching the application's handler.
- **An answer about a different action is retried like a malformed response, whatever its
  status.** The core package proves a body is shaped like an action, not that it is the one asked
  for, so the id check runs before every outcome rather than only a running one — otherwise a
  misrouted or cached answer would fire `ActionCompleted` for somebody else's action. It is
  probed: disabling the check fails both `an_answer_about_a_different_action_*` tests.
- **The payload holds an account name, never a client or token.** BinaryLane tokens are unscoped
  and do not expire.
- **The events share no parent class.** Laravel's dispatcher matches listeners by class and by
  interface only (`Dispatcher::addInterfaceListeners`), so a base class would invite a listener
  that never fires.

## Facts worth not rediscovering

- **The core constraint is `^0.3`, and both of the core's recent behaviours are load-bearing.**
  From 0.3 the core logs nothing above `debug`, which is why `AwaitAction` logs its own outcomes;
  on 0.2 those lines would be doubled.
- **An empty or envelope-less success raises, and a bodiless 202 does not** — the core package's
  line since 0.2. The two raising
  cases arrive by different paths with different messages, so `ExceptionPassthroughTest` pins each
  on its own; `HttpFakeTest::a_bodiless_202_is_an_action_that_is_not_there` pins the arm that must
  stay silent.
- **A `per_page` of 0 is refused by the manager although the core `Config` accepts it.** It is the
  API's count-only request, meaningful once and never as a default.
- **The named-account accessor is `client()` because `account()` and `connection()` are taken** by
  `Client`. `FacadeConformanceTest::the_manager_does_not_shadow_a_client_method` keeps any name
  from being reintroduced. The config key is still `accounts`.
- **`RequestSending` fires for this package's requests; `ResponseReceived` does not.** Laravel
  raises the first from a before-sending callback inside the handler stack and the second from
  `PendingRequest::send()`, which the adapter never calls. Telescope's HTTP watcher listens for
  `ResponseReceived`, so it shows nothing. The test is
  `TransportTest::request_sending_fires_and_response_received_does_not`.
- **`failOnDeprecation` is inert in a Testbench package without help.**
  `withoutDeprecationHandling()` in `setUp()` covers test-executed paths, and
  `ConfigurationTest::registering_the_provider_binds_the_manager_and_merges_the_config` registers
  the provider against an application built in the test body to cover `register()`. Both paths
  were probed with `trigger_error(..., E_USER_DEPRECATED)`; both exit 2.
- **Laravel Zero does not bind the HTTP client factory**, so the provider binds a singleton with
  `singletonIf`. `tests/LaravelZeroTest.php` builds that container by hand, because Testbench
  always boots a full application.
- **Laravel Zero does not run package discovery either**, so a Laravel Zero consumer lists the
  provider in `config/app.php`, and the global `BinaryLane` alias never exists there.
  `LaravelZero\Framework\Application::registerBaseBindings()` empties the package manifest.
  **Nothing in the suite can catch a regression in the README's instructions for this**:
  `LaravelZeroTest` registers the provider itself, and testing discovery would need
  `laravel-zero/framework` as a dev dependency.
- **`composer-require-checker` carries the undeclared-dependency check, and is not in
  `composer check`.** `laravel/framework` `replace`s every `illuminate/*` component, so most
  whitelisted symbols in `.github/composer-require-checker.json` belong to a component that is in
  `require` and cannot be attributed to it. The three queue and bus symbols are the exception —
  see the next bullet. A *new* `Illuminate` symbol reported there is a prompt to check `require`,
  not to extend the list. Run it by hand after changing any `use` in `src/`:

  ```bash
  mkdir -p /tmp/crc && composer -d /tmp/crc require maglnet/composer-require-checker
  /tmp/crc/vendor/bin/composer-require-checker check \
      --config-file=.github/composer-require-checker.json composer.json
  ```

- **`illuminate/queue` and `illuminate/bus` are in `suggest` and `require-dev`, not `require`.**
  Only `AwaitAction` uses them, and `illuminate/queue` requires `illuminate/database`, so as hard
  requirements they put the queue and the whole database layer into every application — a Laravel
  Zero CLI that only reads DNS included. In an empty project, 0.1.0 installed 19 packages and
  4.9 MB of source that this arrangement does not. **`AwaitAction` cannot raise a friendly error
  when they are missing**: `Queueable` and `InteractsWithQueue` are traits, so the class fails to
  load before any of its code runs, and the worker only hands a job its queue handle when the class
  uses `InteractsWithQueue`, so the traits cannot be dropped. The dev-free PHPStan job does not
  notice their absence from `require` either — a `--no-dev` install from this repository's lock
  keeps `laravel/framework`, which supplies them.
- **`Illuminate\Foundation\Bus\Dispatchable` is deliberately not used on the job.** It lives in
  `Illuminate\Foundation`, which is published only inside `laravel/framework` — the
  `illuminate/foundation` package on Packagist stops at Laravel 4 — so there is no component to
  require for it. The README dispatches with `dispatch(new AwaitAction(...))`, which the
  application already has.

## The facade's annotations are the only types it has

`BinaryLane::servers()` goes through the manager's `__call()` and returns `mixed`; the
`@method static` block is what makes the chain analysable. `tests/FacadeConformanceTest.php`
compares that block with `Client`'s public methods in both directions and checks every annotated
type resolves, including through an aliased import. The manager gets the same coverage from one
`@mixin Client` line.

## No harness, because what the suite cannot see is the API, not the container

`AwaitAction` is a queue-worker interaction with its own retry policy, which is the usual case
for giving a Laravel package a harness. It does not get one, and the reason is where the gap
actually is.

**The worker is already seen.** `QueueWorkerTest` runs the real `queue:work` against a real
database queue, and removing `$tries = 0` turns it red. A harness would add nothing there.

**BinaryLane is not seen, and a harness is the wrong place to look.** Every fake in the suite
encodes an assumption about the API: how a real action moves from `in-progress` to finished,
whether a server action answers with its 202 or its 200, how long polling takes. A harness here
would need Laravel inside it, so it could no longer catch an undeclared dependency, and it would
reach the same API the core package's own harness already drives, through a thicker stack.

**So the live check is a scratch application, run by hand before a release that changes the
job.** A current Laravel install with this package required, a database queue and a real
worker, dispatching `AwaitAction` for a read-only question action on a real server and watching
which event arrives. Use `is_running` or `uptime` through `serverActions()->perform()` — they
change nothing. Two things to expect rather than discover:

- **A question action answers "no" by erroring.** `is_running` on a stopped server fires
  `ActionFailed`, which is the core package's complete-or-error pattern arriving intact, not a
  defect in the job — and, as the core package documents on `ServerActions::ask()`, an errored
  question cannot be told apart from a check that itself failed.
- **It needs a real token, and a BinaryLane token can do anything its account can.** Keep the
  scratch application away from anything that would dispatch a mutating action.
