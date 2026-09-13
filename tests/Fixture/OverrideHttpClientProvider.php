<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Tests\Fixture;

use GuzzleHttp\Psr7\Response;
use Hampel\BinaryLane\Api\Laravel\BinaryLaneServiceProvider;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * An application's own provider overriding this package's transport - AppServiceProvider, as a
 * Laravel Zero application lists it: before the package provider, because Laravel Zero runs no
 * package discovery and config/app.php is the whole order.
 */
final class OverrideHttpClientProvider extends ServiceProvider
{
    public static ?OverrideHttpClient $client = null;

    public function register(): void
    {
        self::$client = new OverrideHttpClient();

        $this->app->singleton(BinaryLaneServiceProvider::HTTP_CLIENT, static fn (): ClientInterface => self::$client ?? new OverrideHttpClient());
    }
}

/**
 * @internal
 */
final class OverrideHttpClient implements ClientInterface
{
    /** @var list<string> */
    public array $sent = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = (string) $request->getUri();

        return new Response(200, ['Content-Type' => 'application/json'], '{"server":{"id":1234,"name":"from-the-override.example.test","status":"active"}}');
    }
}
