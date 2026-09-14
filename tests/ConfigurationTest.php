<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Tests;

use Hampel\BinaryLane\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\BinaryLane\Api\Laravel\Http\PendingRequestClient;
use Hampel\BinaryLane\Api\Laravel\BinaryLaneManager;
use Hampel\BinaryLane\Api\Laravel\BinaryLaneServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionProperty;

final class ConfigurationTest extends TestCase
{
    #[Test]
    public function the_package_config_is_merged_into_the_application(): void
    {
        $config = $this->container()->make(Config::class);

        foreach (['default', 'accounts', 'per_page', 'base_uri', 'timeout', 'connect_timeout'] as $key) {
            $this->assertTrue($config->has('binarylane.' . $key), sprintf('binarylane.%s is not merged', $key));
        }
    }

    #[Test]
    public function the_config_file_is_publishable_under_its_own_tag(): void
    {
        $published = ServiceProvider::pathsToPublish(BinaryLaneServiceProvider::class, 'binarylane-config');

        $this->assertSame([config_path('binarylane.php')], array_values($published));
    }

    #[Test]
    public function the_shipped_config_names_one_account_and_no_credential(): void
    {
        // Read straight from the file rather than the merged config, which the test case has
        // already overridden. An unset environment must leave the account unusable rather than
        // pointing somewhere with something.
        $defaults = require __DIR__ . '/../config/binarylane.php';
        $this->assertIsArray($defaults);

        $accounts = $defaults['accounts'] ?? null;
        $this->assertIsArray($accounts);

        $this->assertSame('main', $defaults['default'] ?? null);
        $this->assertSame(['main'], array_keys($accounts));
        $this->assertSame(['token' => null], $accounts['main'] ?? null);
        // array_key_exists rather than ??, which cannot tell an absent key from a null one -
        // and null is the value under test here.
        $this->assertArrayHasKey('per_page', $defaults);
        $this->assertNull($defaults['per_page']);
        $this->assertArrayHasKey('base_uri', $defaults);
        $this->assertNull($defaults['base_uri']);
        $this->assertSame(10, $defaults['timeout'] ?? null);
        $this->assertSame(5, $defaults['connect_timeout'] ?? null);
    }

    #[Test]
    public function the_transport_is_bound_under_this_packages_own_key_so_it_can_be_replaced(): void
    {
        $this->assertInstanceOf(PendingRequestClient::class, $this->container()->make(BinaryLaneServiceProvider::HTTP_CLIENT));
    }

    #[Test]
    public function the_shared_psr18_key_is_left_for_the_application(): void
    {
        // Other API wrappers bind Psr\Http\Client\ClientInterface too; claiming it made the
        // last provider registered the transport for all of them. See SharedPsr18BindingTest.
        $this->assertFalse($this->container()->bound(ClientInterface::class));
    }

    #[Test]
    public function a_transport_binding_that_is_not_a_psr18_client_is_named_as_configuration(): void
    {
        $this->container()->instance(BinaryLaneServiceProvider::HTTP_CLIENT, new \stdClass());

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('binarylane.http_client must be a Psr\Http\Client\ClientInterface; it resolved to stdClass');

        $this->container()->make(BinaryLaneManager::class);
    }

    #[Test]
    public function psr17_factories_are_bound_so_the_cores_discovery_never_has_to_run(): void
    {
        $this->assertInstanceOf(RequestFactoryInterface::class, $this->container()->make(RequestFactoryInterface::class));
        $this->assertInstanceOf(StreamFactoryInterface::class, $this->container()->make(StreamFactoryInterface::class));
    }

    #[Test]
    public function registering_the_provider_binds_the_manager_and_merges_the_config(): void
    {
        // Registered here, against an application built in the test body, rather than relying
        // on the registration Testbench already did in setUp. Two reasons, and the second is
        // the important one:
        //
        // The bindings are asserted against an application that did not have them, so the
        // assertions depend on this call rather than on setUp's.
        //
        // And register() only runs under PHPUnit's error handler if it runs from here.
        // Laravel's HandleExceptions bootstrapper replaces that handler while the application
        // boots, which in a Testbench suite is during parent::setUp() - before
        // withoutDeprecationHandling() puts it back. So a deprecation raised by the provider's
        // own registration during setUp is discarded, and phpunit.xml's failOnDeprecation
        // never sees it.
        $app = new Application(__DIR__ . '/..');
        $app->instance('config', new ConfigRepository());

        (new BinaryLaneServiceProvider($app))->register();

        $this->assertTrue($app->bound(BinaryLaneManager::class));
        $this->assertTrue($app->bound(BinaryLaneServiceProvider::HTTP_CLIENT));
        $this->assertFalse($app->bound(ClientInterface::class));
        $this->assertTrue($app->bound(RequestFactoryInterface::class));
        $this->assertTrue($app->bound(StreamFactoryInterface::class));
        $this->assertSame('main', $app->make(Config::class)->get('binarylane.default'));
    }

    #[Test]
    public function booting_the_provider_publishes_this_packages_config(): void
    {
        // Booted here, against an application built in the test body, for the same reason as
        // the register() test above - and boot() needs it separately. Testbench boots every
        // provider inside parent::setUp(), under Laravel's error handler, which discards
        // deprecations; publishes() and configPath() run there and nowhere else, so a
        // deprecation in either would reach every application and never this suite.
        //
        // The publish registry is static and keyed by provider class, and Testbench's own boot
        // has already filled it for this class. Emptied first, or this assertion passes on
        // Testbench's entry even if the boot below registered nothing - and restored after, or
        // the publish-tag test above depends on execution order.
        $publishes = new ReflectionProperty(ServiceProvider::class, 'publishes');
        $groups = new ReflectionProperty(ServiceProvider::class, 'publishGroups');
        $savedPublishes = $publishes->getValue();
        $savedGroups = $groups->getValue();

        try {
            $publishes->setValue(null, []);
            $groups->setValue(null, []);

            $app = new Application(__DIR__ . '/..');
            $app->instance('config', new ConfigRepository());

            $provider = new BinaryLaneServiceProvider($app);
            $provider->register();
            $provider->boot();

            // The source path, not the destination: the destination is this throwaway
            // application's config directory and says nothing about the package.
            $this->assertSame(
                [realpath(__DIR__ . '/../config/binarylane.php')],
                array_map(realpath(...), array_keys(
                    ServiceProvider::pathsToPublish(BinaryLaneServiceProvider::class, 'binarylane-config')
                )),
            );
        } finally {
            $publishes->setValue(null, $savedPublishes);
            $groups->setValue(null, $savedGroups);
        }
    }
}
