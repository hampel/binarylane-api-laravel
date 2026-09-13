<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel;

use GuzzleHttp\Psr7\HttpFactory as Psr17Factory;
use Hampel\BinaryLane\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\BinaryLane\Api\Laravel\Http\PendingRequestClient;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Wires the BinaryLane API manager into the container.
 *
 * The transport is bound under this package's own key, HTTP_CLIENT, and the manager is built
 * from that key alone. Rebind or decorate it and every account's client picks the replacement
 * up. The default sends through Laravel's HTTP client, which is what makes the package's traffic
 * visible to Http::fake() - see PendingRequestClient.
 *
 * NOT `Psr\Http\Client\ClientInterface`, deliberately. That is one key in the container, and
 * other API wrappers bind it too; singleton() on a bound key replaces it, so the last provider
 * registered supplied the transport for every package - one package's timeouts governing
 * another's requests, and a fix to one adapter reaching an application only when that package
 * happened to register last. Measured in an application with three such wrappers installed.
 * Nor does the manager fall back to a ClientInterface binding when one exists: another package,
 * or an unrelated library, may be the one that bound it.
 */
final class BinaryLaneServiceProvider extends ServiceProvider
{
    /**
     * The container key this package's PSR-18 transport is bound under - the override point for
     * an application that must send this package's requests through a client of its own.
     */
    public const HTTP_CLIENT = 'binarylane.http_client';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/binarylane.php', 'binarylane');

        // Laravel binds the HTTP client factory as a singleton in FoundationServiceProvider,
        // which a full application registers and a Laravel Zero one does NOT - its provider
        // set is Build, Cache, Collision, CommandRecorder, Composer, Filesystem, GitVersion
        // and NullLogger, and nothing there binds it. `app:install http` does not either: it
        // runs `composer require illuminate/http` and stops. Unbound, the container builds a
        // fresh Factory on every make(), so the one this package holds is not the one the Http
        // facade configures, and Http::fake() silently fails to intercept: the request goes to
        // the real API.
        //
        // Http::fake() hides the ordering, which is what makes it dangerous. fake() calls
        // Facade::swap(), which binds its instance into the container - so faking BEFORE the
        // client is resolved happens to work, and faking after it does not. Binding a
        // singleton here removes the ordering question on both platforms.
        //
        // singletonIf, so a full Laravel application keeps the framework's own binding and
        // this is a no-op there.
        $this->app->singletonIf(HttpClientFactory::class, static fn (Container $app): HttpClientFactory => new HttpClientFactory(
            $app->bound(Dispatcher::class) ? $app->make(Dispatcher::class) : null,
        ));

        // bindIf, so an application that has already bound PSR-17 factories keeps its own.
        // Guzzle's fills both roles and laravel/framework requires it, so the fallback
        // resolves in every application this package can run in. The core package would find
        // one itself through Psr17Discovery; binding them means an application can say which,
        // and means the discovery never runs in the one place a wrong answer would be silent.
        $this->app->bindIf(RequestFactoryInterface::class, static fn (): RequestFactoryInterface => new Psr17Factory());
        $this->app->bindIf(StreamFactoryInterface::class, static fn (): StreamFactoryInterface => new Psr17Factory());

        $this->app->singleton(self::HTTP_CLIENT, function (): ClientInterface {
            $config = $this->app->make(Config::class);

            // The Factory the Http facade resolves, read on every send rather than once here:
            // that is what puts this package's requests among the ones Http::fake() and
            // Http::assertSent() see, including after Http::swap() replaces the factory.
            $app = $this->app;

            return new PendingRequestClient(
                static fn (): HttpClientFactory => $app->make(HttpClientFactory::class),
                $this->seconds($config->get('binarylane.timeout'), 10.0),
                $this->seconds($config->get('binarylane.connect_timeout'), 5.0),
            );
        });

        $this->app->singleton(BinaryLaneManager::class, function (): BinaryLaneManager {
            $client = $this->app->make(self::HTTP_CLIENT);

            if (! $client instanceof ClientInterface) {
                throw InvalidConfiguration::httpClient(self::HTTP_CLIENT, get_debug_type($client));
            }

            return new BinaryLaneManager(
                $this->app->make(Config::class),
                $client,
                $this->app->make(RequestFactoryInterface::class),
                $this->app->make(StreamFactoryInterface::class),
                $this->app->make(LoggerInterface::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // configPath() rather than the config_path() helper: the helper is defined by
            // illuminate/foundation, which this package does not require and should not.
            // Requiring only the components it uses is a claim that the package needs no full
            // application, and Testbench -- which boots one -- would never catch the helper
            // contradicting it.
            $this->publishes([
                __DIR__ . '/../config/binarylane.php' => $this->app->configPath('binarylane.php'),
            ], 'binarylane-config');
        }
    }

    private function seconds(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}
