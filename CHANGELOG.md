# CHANGELOG

## Unreleased

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
- `AwaitAction` retries 5xx, 429, transport and malformed responses until its deadline, honouring
  `Retry-After`, and fails at once on any other exception
- `QueueRequired` is raised when `AwaitAction` runs on the `sync` driver or without a queue job
- `Illuminate\Http\Client\Factory` is bound as a singleton when the application has not bound one,
  as a Laravel Zero application does not
- `Psr\Http\Client\ClientInterface` is bound separately: rebind it to route the package's requests
  through an application's own HTTP client
- `per_page` and `base_uri` are top-level settings shared by every account's client; a `per_page`
  below 1 is refused
- `UnknownAccount`, `InvalidConfiguration` and `QueueRequired` extend the core package's
  `BinaryLaneException`
- Requires `hampel/binarylane-api` `^0.1`, PHP 8.3, and Laravel 12 or 13
