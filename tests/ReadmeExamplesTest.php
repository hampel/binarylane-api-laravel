<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Tests;

use Hampel\BinaryLane\Api\Entity\Account;
use Hampel\BinaryLane\Api\Entity\Server;
use Hampel\BinaryLane\Api\Enum\AccountStatus;
use Hampel\BinaryLane\Api\Laravel\BinaryLaneManager;
use Hampel\BinaryLane\Api\Laravel\Events\ActionCompleted;
use Hampel\BinaryLane\Api\Laravel\Facades\BinaryLane;
use Hampel\BinaryLane\Api\Laravel\Jobs\AwaitAction;
use Hampel\BinaryLane\Api\Request\CreateServer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * The README's examples, run.
 *
 * A pasted snippet gets hand-edited when the code around it changes and quietly stops matching
 * what the package does. These are the ones with a signature in them, so a renamed accessor, a
 * changed argument name or a changed return type fails here rather than being discovered by
 * somebody following the documentation.
 */
final class ReadmeExamplesTest extends TestCase
{
    #[Test]
    public function the_usage_examples_return_what_the_readme_says_they_do(): void
    {
        Http::fake([
            'api.binarylane.com.au/v2/account' => Http::response(['account' => [
                'email' => 'someone@example.test',
                'email_verified' => true,
                'status' => 'active',
            ]]),
            'api.binarylane.com.au/v2/servers/1234' => Http::response(['server' => self::server()]),
            'api.binarylane.com.au/v2/servers*' => Http::response(self::collection('servers', [self::server()], total: 42)),
        ]);

        $account = BinaryLane::verify();
        $servers = BinaryLane::servers()->list();
        $server = BinaryLane::servers()->get(1234);

        $this->assertInstanceOf(Account::class, $account);
        $this->assertSame(AccountStatus::Active, $account->status);
        $this->assertCount(1, $servers);
        $this->assertSame(42, $servers->total);
        $this->assertInstanceOf(Server::class, $server);
    }

    #[Test]
    public function a_named_account_and_an_injected_manager_are_reached_the_way_the_readme_says(): void
    {
        Http::fake(['api.binarylane.com.au/*' => Http::response(self::collection('servers', [self::server()]))]);

        BinaryLane::client('reseller')->servers()->list();
        $this->container()->make(BinaryLaneManager::class)->client('reseller')->servers()->list();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer reseller-token'));
    }

    #[Test]
    public function the_await_examples_dispatch_what_the_readme_says_they_do(): void
    {
        Bus::fake();
        Http::fake([
            'api.binarylane.com.au/v2/servers/1234/actions' => Http::response(self::action(5001, 'in-progress')),
            'api.binarylane.com.au/v2/servers' => Http::response([
                'server' => self::server(1234, ['status' => 'new']),
                'links' => ['actions' => [
                    ['id' => 6001, 'rel' => 'create', 'href' => 'https://api.binarylane.com.au/v2/actions/6001'],
                    ['id' => 6002, 'rel' => 'install', 'href' => 'https://api.binarylane.com.au/v2/actions/6002'],
                ]],
            ]),
        ]);

        $action = BinaryLane::serverActions()->powerOn(1234);

        if ($action !== null) {
            dispatch(new AwaitAction($action));
        }

        $created = BinaryLane::servers()->create(CreateServer::of('std-2vcpu', 'ubuntu-24-04-lts', 'syd'));

        foreach ($created->actionIds() as $id) {
            dispatch(new AwaitAction($id, timeout: 7200));
        }

        dispatch(new AwaitAction(5001, account: 'reseller', timeout: 3600, interval: 10));

        Bus::assertDispatchedTimes(AwaitAction::class, 4);
        Bus::assertDispatched(AwaitAction::class, fn (AwaitAction $job): bool => $job->actionId === 6002
            && $job->deadline - $job->dispatchedAt === 7200);
        Bus::assertDispatched(AwaitAction::class, fn (AwaitAction $job): bool => $job->account === 'reseller'
            && $job->interval === 10);
    }

    #[Test]
    public function the_testing_example_for_await_action_runs_as_written(): void
    {
        Event::fake([ActionCompleted::class]);
        Http::fake(['api.binarylane.com.au/*' => Http::response(self::action(5001, 'in-progress'))]);

        $job = (new AwaitAction(5001))->withFakeQueueInteractions();

        $job->handle(app(BinaryLaneManager::class), app('events'), app('log'));

        $job->assertReleased(10);
    }
}
