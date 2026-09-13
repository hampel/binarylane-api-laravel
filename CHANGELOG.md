# CHANGELOG

## 0.4.0 (2026-09-14)

**Breaking:** the transport override point moves from `Psr\Http\Client\ClientInterface` to
`binarylane.http_client`.

- The PSR-18 transport is bound under `BinaryLaneServiceProvider::HTTP_CLIENT`
  (`binarylane.http_client`), and `BinaryLaneManager` is built from that key alone
- The provider no longer binds `Psr\Http\Client\ClientInterface`, and a binding of it no longer
  replaces this package's transport
- `binarylane.http_client` is bound only when nothing has bound it already, so an application's
  override applies whichever order the providers register in
- `InvalidConfiguration` is raised when `binarylane.http_client` does not resolve to a PSR-18 client

## 0.3.0 (2026-09-14)

- Requires `hampel/binarylane-api` `^0.3`
- `AwaitAction` logs a failed, blocked or timed-out action at `warning`
- Requests are sent through the HTTP client factory the container holds at the time of sending, so
  `Http::swap()` in a test is followed, including its `preventStrayRequests()`
- The configured `timeout` and `connect_timeout`, and transport settings from
  `Http::globalOptions()`, are applied to requests; global headers, `auth`, `query` and body
  options are not
- README: an application's own `config/binarylane.php` overrides this package's keys of the same
  name

## 0.2.0 (2026-09-13)

- `illuminate/queue` and `illuminate/bus` are suggested rather than required; `AwaitAction` needs
  both installed
- README: Laravel Zero lists `BinaryLaneServiceProvider` in `config/app.php` and imports the facade
  by class name

## 0.1.0 (2026-09-13)

Initial release.

- `BinaryLaneServiceProvider` binds `BinaryLaneManager`, merges `config/binarylane.php` and
  publishes it under the `binarylane-config` tag
- `BinaryLaneManager` builds one `Hampel\BinaryLane\Api\Client` per configured account, memoised by
  name; a call naming no account is forwarded to the default
- `BinaryLane` facade, with a `@method` annotation for each accessor on `Client`
- `Http::fake()`, `Http::assertSent()` and `Http::preventStrayRequests()` apply to requests the API
  client makes. Request building, status mapping and the exception hierarchy are the core
  package's throughout, and its exceptions reach the caller unchanged
- `AwaitAction` queued job polls an action one request per attempt, releasing itself between
  polls, until it completes, errors, is blocked or reaches its deadline
- `ActionCompleted`, `ActionFailed`, `ActionBlocked` and `ActionTimedOut` events report how an
  awaited action ended
- `AwaitAction` retries 5xx, 429, transport and malformed responses, and answers about a different
  action, until its deadline, honouring `Retry-After`; it fails at once on any other exception
- `QueueRequired` is raised when `AwaitAction` runs on the `sync` driver or without a queue job
- `Illuminate\Http\Client\Factory` is bound as a singleton when the application has not bound one
- `Psr\Http\Client\ClientInterface` is bound separately: rebind it to route the package's requests
  through an application's own HTTP client
- `per_page` and `base_uri` are top-level settings shared by every account's client; a `per_page`
  below 1 is refused
- `UnknownAccount`, `InvalidConfiguration` and `QueueRequired` extend the core package's
  `BinaryLaneException`
- Requires `hampel/binarylane-api` `^0.2`, PHP 8.3, and Laravel 12 or 13
