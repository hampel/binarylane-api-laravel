<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Tests;

use Hampel\BinaryLane\Api\Laravel\BinaryLaneServiceProvider;
use Hampel\BinaryLane\Api\Laravel\Facades\BinaryLane;
use Hampel\BinaryLane\Api\Laravel\Tests\Fixture\OverrideHttpClientProvider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * An application's override of binarylane.http_client, registered before this package's provider.
 *
 * In a full Laravel application package providers register before the application's own, so an
 * override in AppServiceProvider comes last and would win whatever the package did. Laravel Zero
 * runs no discovery: the order is config/app.php, where AppServiceProvider is listed first and the
 * package provider is added after it. A provider that claimed the key with singleton() would
 * then replace the application's override without a word, so it uses singletonIf().
 */
final class HttpClientOverrideTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [OverrideHttpClientProvider::class, BinaryLaneServiceProvider::class];
    }

    #[Test]
    public function an_override_registered_before_this_provider_supplies_the_transport(): void
    {
        Http::preventStrayRequests();

        $this->assertSame('from-the-override.example.test', BinaryLane::servers()->get(1234)->name);
        $this->assertSame(['https://api.binarylane.com.au/v2/servers/1234'], OverrideHttpClientProvider::$client->sent ?? []);
    }

    protected function tearDown(): void
    {
        OverrideHttpClientProvider::$client = null;

        parent::tearDown();
    }
}
