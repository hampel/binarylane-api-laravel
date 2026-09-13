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

## `Http::fake()` reaching the core package's traffic is the claim everything rests on

The core package holds its own PSR-18 client, so nothing it sends is visible to `Http::fake()`.
`PendingRequestClient` replaces that client with one that sends through Laravel's handler stack,
while the core package's request building, status mapping and exception hierarchy stay untouched.
`tests/HttpFakeTest.php` is that claim, asserted.

Three decisions in the adapter are load-bearing, and its docblock carries the reasoning for each:
the pending request is **rebuilt on every send** (`Factory::fake()` replaces the stub collection,
so a cached request holds a snapshot); **one Guzzle handler is shared** across those rebuilds so
keep-alive survives; and it calls **`send()` with four options set by hand**, because Laravel 12
reads `laravel_data` and `on_stats` without a default and `http_errors` must stay off or a 404
arrives as a transport failure. **Laravel 13 reads the first two defensively and 12 does not**, so
the Laravel 12 CI job is the one that catches their removal.

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

- **The constraint is `^0.2` because 0.1 read a malformed success as an empty one.** In 0.1 an
  empty-bodied 2xx became an empty response and a body without its envelope key a blank entity, so
  `Http::fake()` with no arguments reported an account with no servers. 0.2.0 raises
  `MalformedResponseException` for both, with different messages — "the body was empty" and
  `without the expected "servers" key`. `ExceptionPassthroughTest` pins each separately, and
  `HttpFakeTest::a_bodiless_202_is_an_action_that_is_not_there` pins the 202 that must still not
  raise.
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
- **`composer-require-checker` carries the undeclared-dependency check, and is not in
  `composer check`.** `laravel/framework` `replace`s every `illuminate/*` component, so every
  whitelisted symbol in `.github/composer-require-checker.json` belongs to a component that is in
  `require` and cannot be attributed to it. A *new* `Illuminate` symbol reported there is a prompt
  to check `require`, not to extend the list. Run it by hand after changing any `use` in `src/`:

  ```bash
  mkdir -p /tmp/crc && composer -d /tmp/crc require maglnet/composer-require-checker
  /tmp/crc/vendor/bin/composer-require-checker check \
      --config-file=.github/composer-require-checker.json composer.json
  ```

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

## A harness is not here yet, and this package is the case that could earn one

Testbench sees the container wiring, and `QueueWorkerTest` drives the real worker against faked
HTTP. What neither can see is the job against a real account — whether a real action's polling
ends the way the fakes say it does. The core package's harness drives live API calls; a live
check of this package belongs in an application with a real queue rather than in a harness that
would need Laravel inside it.
