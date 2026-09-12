<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Tests;

use Hampel\BinaryLane\Api\Laravel\BinaryLaneServiceProvider;
use Hampel\BinaryLane\Api\Laravel\Facades\BinaryLane;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Testbench boots a minimal Laravel application from inside this package, so the Laravel
 * version under test comes from Composer resolution rather than from an installed framework.
 * Never install a framework to test a package.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BinaryLaneServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['BinaryLane' => BinaryLane::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('binarylane.default', 'main');
        $app['config']->set('binarylane.accounts', [
            'main' => ['token' => 'token-under-test'],
            'reseller' => ['token' => 'reseller-token'],
        ]);
        $app['config']->set('binarylane.base_uri', null);
        $app['config']->set('binarylane.per_page', null);
    }

    /**
     * A server as BinaryLane answers with one, trimmed to the fields the tests read.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function server(int $id = 1234, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'name' => 'vps01.example.test',
            'memory' => 4096,
            'vcpus' => 2,
            'disk' => 80,
            'created_at' => '2026-01-05T03:04:05Z',
            'status' => 'active',
            'size_slug' => 'std-2vcpu',
            'region' => ['slug' => 'syd', 'name' => 'Sydney', 'sizes' => ['std-2vcpu'], 'available' => true],
        ];
    }

    /**
     * An action, in the envelope `GET /v2/actions/{id}` and every mutation answer with.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function action(int $id = 5001, string $status = 'completed', array $overrides = []): array
    {
        return ['action' => self::actionRow($id, $status, $overrides)];
    }

    /**
     * An action's own fields, without the envelope - what Action::fromArray() takes.
     *
     * Kept here rather than in each test because these fields are what the core package parses,
     * and a hand-trimmed copy per test is how a fixture stops resembling the API.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function actionRow(int $id = 5001, string $status = 'completed', array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'status' => $status,
            'type' => 'power_on',
            'started_at' => '2026-09-12T01:00:00Z',
            'completed_at' => $status === 'in-progress' ? null : '2026-09-12T01:00:30Z',
            'resource_type' => 'server',
            'resource_id' => 1234,
            'region_slug' => 'syd',
            'title' => 'Power On',
            'reason' => 'Requested by user',
            'progress' => [
                'percent_complete' => $status === 'in-progress' ? 50 : 100,
                'current_step' => $status === 'in-progress' ? 'Starting server' : null,
                'current_step_detail' => null,
                'completed_steps' => [],
            ],
        ];
    }

    /**
     * The collection envelope every list endpoint on this API answers in. `links` is omitted
     * when there is no next page, which is what the API does.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected static function collection(string $key, array $items, ?int $total = null): array
    {
        return [
            $key => $items,
            'meta' => ['total' => $total ?? count($items)],
        ];
    }

    /**
     * The application, narrowed.
     *
     * Testbench declares $app as nullable because it does not exist before setUp, so every use
     * of it in a test is otherwise a call on Application|null.
     */
    protected function container(): Application
    {
        $app = $this->app;

        $this->assertNotNull($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel's HandleExceptions bootstrapper replaces PHPUnit's error handler when the
        // app boots, and shouldIgnoreDeprecationErrors() discards deprecations outright while
        // running tests - so phpunit.xml's failOnDeprecation never sees one and is inert in
        // any Testbench-based package. This throws on them instead, which is the whole point
        // of the flag: a library should hear about a deprecation before its users do.
        $this->withoutDeprecationHandling();
    }
}
