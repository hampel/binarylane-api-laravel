<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Tests;

use Hampel\BinaryLane\Api\Laravel\BinaryLaneManager;
use Hampel\BinaryLane\Api\Laravel\BinaryLaneServiceProvider;
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
        // The reason the transport is bound under its own key rather than constructed inside the
        // manager: an application with its own outbound HTTP policy - a proxy-aware,
        // SSRF-guarded client everything is required to go through - binds it there and this
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

        $this->container()->instance(BinaryLaneServiceProvider::HTTP_CLIENT, $recorder);

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
    public function the_configured_timeouts_reach_the_request(): void
    {
        // Not automatic. PendingRequest merges its options only inside its own sendRequest(), and
        // the adapter sends through the built Guzzle client instead, so the timeouts set on the
        // pending request never reached a request until they were passed on by hand. Read at the
        // handler, which is where Guzzle acts on them.
        $this->container()->make(Config::class)->set('binarylane.timeout', 7);
        $this->container()->make(Config::class)->set('binarylane.connect_timeout', 3);
        $this->container()->forgetInstance(BinaryLaneServiceProvider::HTTP_CLIENT);
        $this->container()->forgetInstance(BinaryLaneManager::class);

        $options = $this->optionsSeenByTheHandler();

        Http::fake(['api.binarylane.com.au/*' => Http::response(['server' => self::server()])]);

        BinaryLane::servers()->get(1234);

        $this->assertSame(7.0, $options[0]['timeout'] ?? null);
        $this->assertSame(3.0, $options[0]['connect_timeout'] ?? null);
    }

    #[Test]
    public function a_global_transport_option_reaches_the_request(): void
    {
        // Http::globalOptions() is how an application sets a proxy or a CA bundle for all of
        // its outbound traffic, and the README says it applies here.
        Http::globalOptions([
            'verify' => '/etc/ssl/certs/corporate-ca.pem',
            'proxy' => ['https' => 'http://proxy.example.test:3128', 'no' => ['localhost']],
        ]);

        $options = $this->optionsSeenByTheHandler();

        Http::fake(['api.binarylane.com.au/*' => Http::response(['server' => self::server()])]);

        BinaryLane::servers()->get(1234);

        $this->assertSame('/etc/ssl/certs/corporate-ca.pem', $options[0]['verify'] ?? null);
        $this->assertSame(['https' => 'http://proxy.example.test:3128', 'no' => ['localhost']], $options[0]['proxy'] ?? null);
    }

    #[Test]
    public function a_global_option_that_would_change_the_request_is_not_applied(): void
    {
        // The core package builds the request; the transport sends it as built. A global
        // header, query or body would otherwise overwrite its Accept and Authorization headers
        // and replace its query string or body.
        Http::globalOptions([
            'headers' => ['Accept' => 'text/html', 'Authorization' => 'Bearer not-this-one'],
            'query' => ['injected' => '1'],
            'json' => ['injected' => true],
        ]);

        Http::fake(['api.binarylane.com.au/*' => Http::response(self::action(5001, 'in-progress'))]);

        BinaryLane::serverActions()->powerOn(1234);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.binarylane.com.au/v2/servers/1234/actions'
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Authorization', 'Bearer token-under-test')
            && $request['type'] === 'power_on'
            && ! isset($request['injected']));
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

    /**
     * Records the Guzzle options of every request, as the handler receives them.
     *
     * @return \ArrayObject<int, array<array-key, mixed>>
     */
    private function optionsSeenByTheHandler(): \ArrayObject
    {
        /** @var \ArrayObject<int, array<array-key, mixed>> $seen */
        $seen = new \ArrayObject();

        Http::globalMiddleware(static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler, $seen): mixed {
            $seen[] = $options;

            return $handler($request, $options);
        });

        return $seen;
    }
}
