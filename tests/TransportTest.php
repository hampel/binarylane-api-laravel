<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Tests;

use Hampel\BinaryLane\Api\Laravel\Facades\BinaryLane;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class TransportTest extends TestCase
{
    #[Test]
    public function a_replacement_transport_is_used_by_every_account(): void
    {
        // The reason ClientInterface is bound by interface rather than constructed inside the
        // manager: an application with its own outbound HTTP policy - a proxy-aware,
        // SSRF-guarded client everything is required to go through - binds it here and this
        // package uses it, instead of the application writing a second API client.
        $recorder = new class () implements ClientInterface {
            /** @var list<array{string, string}> */
            public array $sent = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = [(string) $request->getUri(), $request->getHeaderLine('Authorization')];

                return new \GuzzleHttp\Psr7\Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    '{"server":{"id":1,"name":"vps01.example.test","status":"active"}}'
                );
            }
        };

        $this->container()->instance(ClientInterface::class, $recorder);

        BinaryLane::servers()->get(1);
        BinaryLane::client('reseller')->servers()->get(2);

        $this->assertSame([
            ['https://api.binarylane.com.au/v2/servers/1', 'Bearer token-under-test'],
            ['https://api.binarylane.com.au/v2/servers/2', 'Bearer reseller-token'],
        ], $recorder->sent);
    }

    #[Test]
    public function global_request_middleware_reaches_this_packages_requests(): void
    {
        // Rebuilding the pending request per send is what buys this: an application's own Http::
        // configuration applies to the package's traffic without the package knowing anything
        // about it.
        Http::globalRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Application', 'under-test'));

        Http::fake(['api.binarylane.com.au/*' => Http::response(['server' => self::server()])]);

        BinaryLane::servers()->get(1234);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Application', 'under-test'));
    }

    #[Test]
    public function request_sending_fires_and_response_received_does_not(): void
    {
        // Measured rather than reasoned, because the reasoning is easy to get half right.
        // RequestSending is dispatched from a before-sending callback INSIDE the handler stack
        // this adapter drives, so it fires. ResponseReceived is dispatched from
        // PendingRequest::send(), a layer above that stack, which the adapter never calls - so
        // it does not, and nothing listening for it (Telescope's HTTP client watcher among
        // them) sees this package's traffic.
        $sending = 0;
        $received = 0;

        Event::listen(RequestSending::class, function () use (&$sending): void {
            $sending++;
        });
        Event::listen(ResponseReceived::class, function () use (&$received): void {
            $received++;
        });

        Http::fake(['api.binarylane.com.au/*' => Http::response(['server' => self::server()])]);

        BinaryLane::servers()->get(1234);

        $this->assertSame(1, $sending);
        $this->assertSame(0, $received);
    }

    #[Test]
    public function the_configured_base_uri_is_where_the_requests_go(): void
    {
        // The setting exists for a recorded fixture served locally and for a proxy that
        // terminates the connection. Asserted through the transport rather than only on the
        // Config object, because a base URI that reached Config and not the wire would look
        // configured and change nothing.
        $this->container()->make(Config::class)->set('binarylane.base_uri', 'http://localhost:8080');

        Http::fake(['localhost:8080/*' => Http::response(['server' => self::server()])]);

        BinaryLane::servers()->get(1234);

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'http://localhost:8080/v2/servers/1234');
    }

    #[Test]
    public function the_configured_page_size_is_asked_for_on_every_list(): void
    {
        $this->container()->make(Config::class)->set('binarylane.per_page', 150);

        Http::fake(['api.binarylane.com.au/*' => Http::response(self::collection('servers', [self::server()]))]);

        BinaryLane::servers()->list();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'per_page=150'));
    }
}
