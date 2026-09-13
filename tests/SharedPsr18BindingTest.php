<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Tests;

use Hampel\BinaryLane\Api\Laravel\BinaryLaneServiceProvider;
use Hampel\BinaryLane\Api\Laravel\Facades\BinaryLane;
use Hampel\BinaryLane\Api\Laravel\Tests\Fixture\ForeignPsr18Provider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;

/**
 * This package installed beside another that binds the shared PSR-18 key.
 *
 * `Psr\Http\Client\ClientInterface` is one key in the container. When every API wrapper claimed
 * it with singleton(), the last provider registered supplied the transport for all of them, so
 * one package's timeouts governed another's traffic and a fix to one adapter reached an
 * application only if that package happened to register last. Measured in a real application
 * with three of these wrappers installed. No single-package suite could see it, which is why
 * the foreign provider here is a stub rather than a sibling.
 */
final class SharedPsr18BindingTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Registered FIRST, so this package's provider comes after it - the ordering the old
        // binding happened to survive.
        return [ForeignPsr18Provider::class, BinaryLaneServiceProvider::class];
    }

    #[Test]
    public function a_provider_registered_before_this_one_does_not_supply_its_transport(): void
    {
        $this->assertOwnTransportIsUsed();
    }

    #[Test]
    public function a_provider_registered_after_this_one_does_not_supply_its_transport(): void
    {
        // The ordering the old binding lost: another package's provider replacing the shared
        // key after this one had bound it.
        // force: the class is already registered above, and register() would otherwise hand back
        // that instance without running register() again.
        $this->container()->register(new ForeignPsr18Provider($this->container()), force: true);

        $this->assertOwnTransportIsUsed();
    }

    private function assertOwnTransportIsUsed(): void
    {
        $this->container()->make('config')->set('binarylane.timeout', 4);

        // An ArrayObject rather than a reference: the outer arrow function captures by value, so
        // a reference taken inside it would point at a copy.
        /** @var \ArrayObject<int, mixed> $timeouts */
        $timeouts = new \ArrayObject();

        Http::globalMiddleware(static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler, $timeouts): mixed {
            $timeouts[] = $options['timeout'] ?? null;

            return $handler($request, $options);
        });

        Http::fake(['api.binarylane.com.au/*' => Http::response(['server' => self::server()])]);

        $this->assertSame('vps01.example.test', BinaryLane::servers()->get(1234)->name);

        $this->assertSame([], ForeignPsr18Provider::$client->sent ?? []);
        $this->assertSame([4.0], $timeouts->getArrayCopy());
    }

    protected function tearDown(): void
    {
        ForeignPsr18Provider::$client = null;

        parent::tearDown();
    }
}
