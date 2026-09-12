<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Tests;

use Hampel\BinaryLane\Api\Authentication\ApiToken;
use Hampel\BinaryLane\Api\Entity\Server;
use Hampel\BinaryLane\Api\Exception\NotFoundException;
use Hampel\BinaryLane\Api\Laravel\Facades\BinaryLane;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * The reason the package exists.
 *
 * hampel/binarylane-api holds its own PSR-18 client, so by default nothing it sends is visible
 * to Http::fake() and an application testing against it has to fake at the transport library
 * instead - in a vocabulary the rest of its suite does not use. Everything below goes through
 * the package's real request building, status mapping and exception hierarchy; only the socket
 * is replaced.
 *
 * If this file does not pass, the package has no reason to exist.
 */
final class HttpFakeTest extends TestCase
{
    #[Test]
    public function a_faked_response_reaches_the_caller_as_a_typed_entity(): void
    {
        Http::fake([
            'api.binarylane.com.au/*' => Http::response(['server' => self::server()]),
        ]);

        $server = BinaryLane::servers()->get(1234);

        $this->assertInstanceOf(Server::class, $server);
        $this->assertSame(1234, $server->id);
        $this->assertSame('vps01.example.test', $server->name);
    }

    #[Test]
    public function the_request_is_recorded_for_assertion(): void
    {
        Http::fake([
            'api.binarylane.com.au/*' => Http::response(['server' => self::server()]),
        ]);

        BinaryLane::servers()->get(1234);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.binarylane.com.au/v2/servers/1234'
            && $request->hasHeader('Authorization', 'Bearer token-under-test')
            && $request->hasHeader('Accept', 'application/json'));
    }

    #[Test]
    public function a_json_body_is_readable_by_the_assertion(): void
    {
        // Illuminate\Http\Client\Request::isJson() is a substring test over the Content-Type,
        // which the core package's bare `application/json` satisfies - so data() parses the
        // body and $request['type'] works. That matters more on this API than most: every
        // server action is one POST to the same path, told apart only by `type` in the body,
        // so an assertion that a server was powered on rather than rebooted has nothing else
        // to match on.
        Http::fake([
            'api.binarylane.com.au/*' => Http::response(self::action(5001, 'in-progress')),
        ]);

        BinaryLane::serverActions()->powerOn(1234);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.binarylane.com.au/v2/servers/1234/actions'
            && $request->isJson()
            && $request['type'] === 'power_on');
    }

    #[Test]
    public function a_bodiless_202_is_an_action_that_is_not_there(): void
    {
        // The specification declares a bodiless 202 beside the 200 on every server action, so
        // "no action to wait on" is a real answer and ServerActions returns ?Action for it.
        // Asserted through the transport because it is decided by the response the transport
        // hands back - an adapter that turned an empty 202 into anything else would give
        // AwaitAction an id of 0.
        Http::fake([
            'api.binarylane.com.au/*' => Http::response('', 202),
        ]);

        $this->assertNull(BinaryLane::serverActions()->powerOn(1234));
    }

    #[Test]
    public function faking_after_the_client_was_resolved_still_intercepts(): void
    {
        // The ordering a cached transport gets wrong. Factory::fake() REPLACES the factory's
        // stub collection, and createPendingRequest() copies whatever is there when it is
        // called - so a Guzzle client built at resolution time holds a snapshot taken before
        // these stubs existed, and the request would go to the real API. PendingRequestClient
        // rebuilds per send, so it does not.
        $client = BinaryLane::client();

        Http::preventStrayRequests();
        Http::fake([
            'api.binarylane.com.au/*' => Http::response(['server' => self::server(4321, ['name' => 'late.example.test'])]),
        ]);

        $this->assertSame('late.example.test', $client->servers()->get(4321)->name);
    }

    #[Test]
    public function a_stray_request_is_reported_as_laravel_reports_it(): void
    {
        // Not disguised as the package's RequestException. Connection::dispatch() catches
        // ClientExceptionInterface, and StrayRequestException is a plain RuntimeException, so
        // it arrives with Laravel's own message and the URL still in it.
        Http::preventStrayRequests();
        Http::fake(['example.test/*' => Http::response([])]);

        $this->expectException(StrayRequestException::class);
        $this->expectExceptionMessage('https://api.binarylane.com.au/v2/servers/1234');

        BinaryLane::servers()->get(1234);
    }

    #[Test]
    public function an_error_status_still_maps_to_the_packages_exception_hierarchy(): void
    {
        // The layer supplies the transport and nothing else. A 404 has to arrive as
        // NotFoundException, not as an unsuccessful Response.
        Http::fake([
            'api.binarylane.com.au/*' => Http::response(['title' => 'Not Found', 'status' => 404], 404),
        ]);

        $this->expectException(NotFoundException::class);

        BinaryLane::servers()->get(1234);
    }

    #[Test]
    public function an_action_sequence_is_faked_one_response_per_poll(): void
    {
        // Anything that awaits needs several responses in order: one per poll. A test that
        // queues one response for a wait that takes three is a test that hangs or fails in a
        // way that looks like a bug in await(). fakeSequence() is the tool, and the core
        // package's `$wait` argument is what stops it taking real time.
        Http::fakeSequence('api.binarylane.com.au/*')
            ->push(self::action(5001, 'in-progress'))
            ->push(self::action(5001, 'in-progress'))
            ->push(self::action(5001, 'completed'));

        $slept = [];

        $action = BinaryLane::actions()->await(5001, wait: function (int $seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $this->assertTrue($action->isSuccessful());
        $this->assertSame([5, 5], $slept);
        Http::assertSentCount(3);
    }

    #[Test]
    public function a_derived_client_sends_through_the_same_faked_transport(): void
    {
        // withCredential() builds a new Client over the connection's existing transport. If it
        // did not, an application resolving a per-tenant token at request time would fake the
        // first call and reach the real API on every one after it.
        Http::fake([
            'api.binarylane.com.au/*' => Http::response(['server' => self::server(9)]),
        ]);

        BinaryLane::withCredential(new ApiToken('tenant-token'))->servers()->get(9);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader(
            'Authorization',
            'Bearer tenant-token'
        ));
    }
}
