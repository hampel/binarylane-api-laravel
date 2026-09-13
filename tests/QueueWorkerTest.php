<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Tests;

use Hampel\BinaryLane\Api\Laravel\Events\ActionCompleted;
use Hampel\BinaryLane\Api\Laravel\Jobs\AwaitAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\Attributes\WithMigration;
use PHPUnit\Framework\Attributes\Test;

/**
 * AwaitAction on a real queue, driven by the real worker.
 *
 * AwaitActionTest calls handle() once per test with a fake queue job, which proves what one
 * attempt decides and nothing about what the WORKER then does with that decision. The claims
 * this job's design rests on are all about the worker:
 *
 *  - that a released job comes back and is polled again rather than lost;
 *  - that `$tries = 0` survives into the payload and beats `queue:work`'s own `--tries=1`, so
 *    the second poll is not failed with MaxAttemptsExceededException before it asks anything;
 *  - that a job which completes leaves nothing in failed_jobs.
 *
 * A database queue on Testbench's in-memory SQLite, and `queue:work --once` run once per poll.
 */
#[WithMigration('queue')]
final class QueueWorkerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.default', 'database');
    }

    #[Test]
    public function a_released_job_is_polled_again_by_a_worker_with_one_try_and_completes(): void
    {
        Event::fake([ActionCompleted::class]);

        Http::fakeSequence('api.binarylane.com.au/*')
            ->push(self::action(5001, 'in-progress'))
            ->push(self::action(5001, 'in-progress'))
            ->push(self::action(5001, 'completed'));

        dispatch(new AwaitAction(5001, interval: 5));

        $this->work();
        $this->assertSame(1, DB::table('jobs')->count(), 'The first poll should have released the job, not dropped it.');
        $this->assertSame(1, DB::table('jobs')->value('attempts'));

        // Not yet available: the release carried the interval as a delay.
        $this->work();
        Http::assertSentCount(1);

        $this->travel(5)->seconds();
        $this->work();
        Http::assertSentCount(2);

        $this->travel(5)->seconds();
        $this->work();
        Http::assertSentCount(3);

        Event::assertDispatched(ActionCompleted::class, fn (ActionCompleted $event): bool => $event->action->id === 5001);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'A third attempt on --tries=1 must not be a failed job.');
    }

    #[Test]
    public function the_payload_carries_unlimited_tries_a_backoff_and_no_expiry(): void
    {
        dispatch(new AwaitAction(5001, interval: 25));

        $raw = DB::table('jobs')->value('payload');
        $this->assertIsString($raw);

        $payload = json_decode($raw, true);
        $this->assertIsArray($payload);

        $this->assertSame(0, $payload['maxTries'] ?? null);
        $this->assertSame('25', $payload['backoff'] ?? null);
        $this->assertArrayHasKey('retryUntil', $payload);
        $this->assertNull($payload['retryUntil']);
    }

    private function work(): void
    {
        // --tries=1 is queue:work's own default, spelled out because it is the setting under
        // test: the job's own maxTries has to win over it.
        $command = $this->artisan('queue:work', ['--once' => true, '--tries' => 1, '--sleep' => 0]);

        $this->assertInstanceOf(PendingCommand::class, $command);

        // run() rather than leaving it to the destructor, so each poll happens on this line and
        // not whenever the temporary is collected.
        $command->assertSuccessful()->run();
    }
}
