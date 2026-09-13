<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Tests;

use Hampel\BinaryLane\Api\Exception\ClientException;
use Hampel\BinaryLane\Api\Exception\MalformedResponseException;
use Hampel\BinaryLane\Api\Exception\NotAuthenticatedException;
use Hampel\BinaryLane\Api\Exception\NotFoundException;
use Hampel\BinaryLane\Api\Exception\NotPermittedException;
use Hampel\BinaryLane\Api\Exception\ServerException;
use Hampel\BinaryLane\Api\Exception\TooManyRequestsException;
use Hampel\BinaryLane\Api\Exception\ValidationException;
use Hampel\BinaryLane\Api\Laravel\Facades\BinaryLane;
use Hampel\BinaryLane\Api\Request\CreateServer;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The status-to-exception mapping survives the trip through Laravel's HTTP client.
 *
 * This is the property to protect above ergonomics. What the core package is worth is not its
 * transport but its taxonomy: a client that reports every unsuccessful status the same way
 * cannot tell a server that does not exist from a token that is not valid, and only one of
 * them is a configuration error that should fail loudly.
 *
 * Laravel's own Http:: is the thing that flattens them, which is why an application reaching
 * for this package must not get that flattening back by the side door. AwaitAction depends on
 * it too: which failures it retries and which fail the job is decided by exception type.
 */
final class ExceptionPassthroughTest extends TestCase
{
    /**
     * @return array<string, array{int, class-string<\Throwable>}>
     */
    public static function statuses(): array
    {
        return [
            'a rejected value, with the field on it' => [400, ValidationException::class],
            'an unusable token is not an empty result' => [401, NotAuthenticatedException::class],
            'a forbidden action is its own failure' => [403, NotPermittedException::class],
            'a missing server' => [404, NotFoundException::class],
            'rate limiting is typed so a caller can retry' => [429, TooManyRequestsException::class],
            'BinaryLane broke, not the caller' => [500, ServerException::class],
            'anything else the caller got wrong' => [418, ClientException::class],
        ];
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    #[Test]
    #[DataProvider('statuses')]
    public function a_status_arrives_as_its_own_exception(int $status, string $expected): void
    {
        Http::fake([
            'api.binarylane.com.au/*' => Http::response(['title' => 'Something BinaryLane said.', 'status' => $status], $status),
        ]);

        $this->expectException($expected);

        BinaryLane::servers()->get(1234);
    }

    #[Test]
    public function a_rejected_field_is_still_readable_on_the_exception(): void
    {
        Http::fake([
            'api.binarylane.com.au/*' => Http::response([
                'type' => 'https://tools.ietf.org/html/rfc9110#section-15.5.1',
                'title' => 'One or more validation errors occurred.',
                'status' => 400,
                'errors' => ['size' => ['The size is not available in this region.']],
            ], 400),
        ]);

        try {
            BinaryLane::servers()->create(CreateServer::of('std-min', 'ubuntu-24-04-lts', 'syd'));
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertTrue($e->concerns('size'));
            $this->assertSame(['The size is not available in this region.'], $e->fieldErrors()['size']);
        }
    }

    #[Test]
    public function the_retry_after_header_survives_the_trip(): void
    {
        // AwaitAction waits at least this long before polling again after a 429, so the header
        // has to reach the exception through this transport rather than only through the core
        // package's own.
        Http::fake([
            'api.binarylane.com.au/*' => Http::response(['title' => 'Too many requests'], 429, ['Retry-After' => '30']),
        ]);

        try {
            BinaryLane::servers()->get(1234);
            $this->fail('Expected a TooManyRequestsException.');
        } catch (TooManyRequestsException $e) {
            $this->assertSame(30, $e->retryAfter);
        }
    }

    #[Test]
    public function a_success_whose_body_is_not_json_is_somebody_elses_answer(): void
    {
        // A maintenance page, a WAF challenge, a CDN interstitial and a truncated body are all
        // a 200 with something other than JSON in it. Returned as an empty result they would
        // read as "this account has no servers" everywhere downstream.
        Http::fake([
            'api.binarylane.com.au/*' => Http::response('<html><body>Down for maintenance</body></html>', 200),
        ]);

        $this->expectException(MalformedResponseException::class);

        BinaryLane::servers()->list();
    }

    #[Test]
    public function faking_with_no_arguments_fails_loudly_rather_than_reporting_an_empty_account(): void
    {
        // Http::fake() with no arguments answers every request with an empty 200 - the easiest
        // mistake to make in a consumer's suite. Only a 202 and a 204 are successes with no body
        // on this API, so a forgotten fixture raises rather than reporting an account with no
        // servers.
        Http::fake();

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage('the body was empty');

        BinaryLane::servers()->list();
    }

    #[Test]
    public function a_body_without_its_envelope_key_is_not_an_empty_account_either(): void
    {
        // The other half of the same failure, and a different message: a body that parsed but
        // is not the collection asked for. Pinned separately because the two arrive by
        // different paths in the core package and could regress independently.
        Http::fake(['api.binarylane.com.au/*' => Http::response(['unexpected' => true])]);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage('without the expected "servers" key');

        BinaryLane::servers()->list();
    }

}
