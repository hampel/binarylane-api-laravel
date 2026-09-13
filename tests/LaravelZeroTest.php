<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Tests;

use Hampel\BinaryLane\Api\Laravel\BinaryLaneManager;
use Hampel\BinaryLane\Api\Laravel\BinaryLaneServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\Test;

/**
 * The package on a Laravel Zero application, which is not the same container as a Laravel one.
 *
 * Laravel binds Illuminate\Http\Client\Factory as a singleton in FoundationServiceProvider.
 * Laravel Zero does not register that provider - its own set is Build, Cache, Collision,
 * CommandRecorder, Composer, Filesystem, GitVersion and NullLogger - so unless this package
 * binds one, the container builds a fresh Factory on every make() and the instance this
 * package holds is not the instance the Http facade configures.
 *
 * The consequence is the failure the package exists to prevent, and it is silent: the fake does
 * not intercept and the request goes to the real API. Testbench boots a full application, so
 * nothing in the rest of the suite can see it.
 *
 * WHAT THIS DOES NOT PROVE: that a Laravel Zero application registers the provider. It does not -
 * Laravel Zero empties the package manifest, so auto-discovery never runs and a consumer lists
 * the provider in config/app.php. The application below registers it by hand, so these tests
 * cover what the provider binds, not whether it is loaded.
 *
 * Laravel Zero is also a likely home for this package. A tool that builds servers or reconciles
 * DNS from a file is a command rather than a web request - and a command is where the core
 * package's blocking Actions::await() is the right choice.
 */
final class LaravelZeroTest extends \PHPUnit\Framework\TestCase
{
    #[Test]
    public function a_fake_registered_after_the_client_was_resolved_still_intercepts(): void
    {
        // The realistic ordering: a command resolves its client, and the test fakes after.
        // Without the singleton below this reached the network.
        $app = $this->laravelZeroApplication();

        $client = $app->make(BinaryLaneManager::class)->client('main');

        Http::fake([
            'api.binarylane.com.au/*' => Http::response(['server' => [
                'id' => 1234,
                'name' => 'vps01.example.test',
                'status' => 'active',
            ]]),
        ]);

        try {
            $this->assertSame('vps01.example.test', $client->servers()->get(1234)->name);
        } finally {
            $this->tearDownFacades();
        }
    }

    #[Test]
    public function the_http_factory_is_bound_as_a_singleton_when_nothing_else_binds_one(): void
    {
        $app = $this->laravelZeroApplication();

        try {
            $this->assertTrue($app->bound(HttpClientFactory::class));
            $this->assertSame($app->make(HttpClientFactory::class), $app->make(HttpClientFactory::class));
            $this->assertSame($app->make(HttpClientFactory::class), Http::getFacadeRoot());
        } finally {
            $this->tearDownFacades();
        }
    }

    #[Test]
    public function an_application_that_already_binds_a_factory_keeps_its_own(): void
    {
        // singletonIf, not singleton: a full Laravel application registers
        // FoundationServiceProvider, and this package must not displace what it bound.
        $app = $this->laravelZeroApplication(function (Application $app): void {
            $app->instance(HttpClientFactory::class, new HttpClientFactory());
        });

        try {
            $existing = $app->make(HttpClientFactory::class);

            $this->assertSame($existing, $app->make(HttpClientFactory::class));
        } finally {
            $this->tearDownFacades();
        }
    }

    private function laravelZeroApplication(?callable $before = null): Application
    {
        $app = new Application(__DIR__ . '/..');
        $app->instance('config', new ConfigRepository());
        $app->register(new EventServiceProvider($app));
        $app->register(new LogServiceProvider($app));
        $app->make('config')->set('logging.default', 'null');
        $app->make('config')->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]);

        if ($before !== null) {
            $before($app);
        }

        (new BinaryLaneServiceProvider($app))->register();

        $app->make('config')->set('binarylane.accounts.main', ['token' => 'token-under-test']);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        return $app;
    }

    private function tearDownFacades(): void
    {
        // A facade left pointing at a dead application fails a later test for a reason that has
        // nothing to do with it.
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }
}
