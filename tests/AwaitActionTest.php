<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Tests;

use Hampel\BinaryLane\Api\Entity\Action;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Exception\NotAuthenticatedException;
use Hampel\BinaryLane\Api\Exception\NotFoundException;
use Hampel\BinaryLane\Api\Exception\RuntimeException;
use Hampel\BinaryLane\Api\Exception\ServerException;
use Hampel\BinaryLane\Api\Laravel\BinaryLaneManager;
use Hampel\BinaryLane\Api\Laravel\Events\ActionBlocked;
use Hampel\BinaryLane\Api\Laravel\Events\ActionCompleted;
use Hampel\BinaryLane\Api\Laravel\Events\ActionFailed;
use Hampel\BinaryLane\Api\Laravel\Events\ActionTimedOut;
use Hampel\BinaryLane\Api\Laravel\Exception\QueueRequired;
use Hampel\BinaryLane\Api\Laravel\Exception\UnknownAccount;
use Hampel\BinaryLane\Api\Laravel\Jobs\AwaitAction;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The job, one attempt at a time.
 *
 * Each test builds the job, gives it a fake queue job that records releases and failures, and
 * calls handle() once - which is exactly what a worker does per attempt. What happens across
 * attempts on a real queue is QueueWorkerTest's business.
 */
final class AwaitActionTest extends TestCase
{
    /**
     * Faked by name, not wholesale: Laravel dispatches its own log and HTTP client events on
     * the same dispatcher, and assertNothingDispatched() would count those.
     */
    private const OUTCOMES = [ActionCompleted::class, ActionFailed::class, ActionBlocked::class, ActionTimedOut::class];

    #[Test]
    public function a_completed_action_fires_completed_and_is_not_polled_again(): void
    {
        Event::fake(self::OUTCOMES);
        Http::fake(['api.binarylane.com.au/*' => Http::response(self::action(5001, 'completed'))]);

        $job = $this->handle(new AwaitAction(5001));

        $job->assertNotReleased();
        $job->assertNotFailed();

        Event::assertDispatched(ActionCompleted::class, fn (ActionCompleted $event): bool => $event->account === 'main'
            && $event->action->id === 5001
            && $event->action->isSuccessful());
        Event::assertNotDispatched(ActionFailed::class);
    }

    #[Test]
    public function one_attempt_is_one_request(): void
    {
        Http::fake(['api.binarylane.com.au/*' => Http::response(self::action(5001, 'in-progress'))]);

        $this->handle(new AwaitAction(5001));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.binarylane.com.au/v2/actions/5001');
    }

    #[Test]
    public function a_running_action_is_released_to_be_polled_after_the_interval(): void
    {
        Event::fake(self::OUTCOMES);
        Http::fake(['api.binarylane.com.au/*' => Http::response(self::action(5001, 'in-progress'))]);

        $job = $this->handle(new AwaitAction(5001, interval: 20));

        $job->assertReleased(20);
        $job->assertNotFailed();
        Event::assertNothingDispatched();
    }

    #[Test]
    public function the_last_poll_lands_on_the_deadline_rather_than_an_interval_past_it(): void
    {
        Http::fake(['api.binarylane.com.au/*' => Http::response(self::action(5001, 'in-progress'))]);

        $job = new AwaitAction(5001, timeout: 100, interval: 30);

        $this->travel(90)->seconds();

        $this->handle($job)->assertReleased(10);
    }

    #[Test]
    public function a_running_action_past_the_deadline_fires_timed_out_and_stops(): void
    {
        Event::fake(self::OUTCOMES);
        Http::fake(['api.binarylane.com.au/*' => Http::response(self::action(5001, 'in-progress'))]);

        $job = new AwaitAction(5001, timeout: 60);

        $this->travel(61)->seconds();

        $handled = $this->handle($job);

        $handled->assertNotReleased();
        $handled->assertNotFailed();
        Event::assertDispatched(ActionTimedOut::class, fn (ActionTimedOut $event): bool => $event->action->id === 5001
            && $event->waited === 61
            && $event->action->progress?->percentComplete === 50);
    }

    #[Test]
    public function a_timeout_of_zero_polls_once(): void
    {
        Event::fake(self::OUTCOMES);
        Http::fake(['api.binarylane.com.au/*' => Http::response(self::action(5001, 'in-progress'))]);

        $this->handle(new AwaitAction(5001, timeout: 0))->assertNotReleased();

        Event::assertDispatched(ActionTimedOut::class);
    }

    #[Test]
    public function the_deadline_counts_time_spent_waiting_in_the_queue(): void
    {
        // Fixed at dispatch, not at the first attempt. A queue that is an hour behind has used
        // the hour, and a job that started its clock on pickup would report a two-hour wait as
        // a one-hour timeout.
        $job = new AwaitAction(5001, timeout: 600);

        $this->travel(30)->minutes();

        Event::fake(self::OUTCOMES);
        Http::fake(['api.binarylane.com.au/*' => Http::response(self::action(5001, 'in-progress'))]);

        $this->handle($job)->assertNotReleased();

        Event::assertDispatched(ActionTimedOut::class, fn (ActionTimedOut $event): bool => $event->waited === 1800);
    }

    #[Test]
    public function an_errored_action_fires_failed_and_the_job_does_not_fail(): void
    {
        // The job's task is to observe the action, and it did. Failing the job would put an
        // entry in failed_jobs that queue:retry would re-run to get the same answer.
        Event::fake(self::OUTCOMES);
        Http::fake(['api.binarylane.com.au/*' => Http::response(self::action(5001, 'errored', [
            'error_message' => 'The server could not be started.',
        ]))]);

        $job = $this->handle(new AwaitAction(5001));

        $job->assertNotReleased();
        $job->assertNotFailed();
        Event::assertDispatched(ActionFailed::class, fn (ActionFailed $event): bool => $event->action->failureReason()
            === 'The server could not be started.');
    }

    #[Test]
    public function an_action_waiting_on_a_question_fires_blocked_and_stops(): void
    {
        Event::fake(self::OUTCOMES);
        Http::fake(['api.binarylane.com.au/*' => Http::response(self::action(5001, 'in-progress', [
            'user_interaction_required' => ['interaction_type' => 'allow-unclean-power-off'],
        ]))]);

        $job = $this->handle(new AwaitAction(5001));

        $job->assertNotReleased();
        $job->assertNotFailed();
        Event::assertDispatched(ActionBlocked::class, fn (ActionBlocked $event): bool => $event->action->needsInteraction());
    }

    #[Test]
    public function an_action_blocked_by_an_invoice_fires_blocked_and_stops(): void
    {
        // Still `in-progress`, and would stay that way for as long as the job kept asking.
        Event::fake(self::OUTCOMES);
        Http::fake(['api.binarylane.com.au/*' => Http::response(self::action(5001, 'in-progress', [
            'blocking_invoice_id' => 9182,
        ]))]);

        $this->handle(new AwaitAction(5001))->assertNotReleased();

        Event::assertDispatched(ActionBlocked::class, fn (ActionBlocked $event): bool => $event->action->blockingInvoiceId === 9182);
    }

    #[Test]
    public function a_server_error_is_polled_again_rather_than_failing_the_job(): void
    {
        Event::fake(self::OUTCOMES);
        Http::fake(['api.binarylane.com.au/*' => Http::response(['title' => 'Internal Server Error', 'status' => 500], 500)]);

        $job = $this->handle(new AwaitAction(5001, interval: 15));

        $job->assertReleased(15);
        $job->assertNotFailed();
        Event::assertNothingDispatched();
    }

    #[Test]
    public function a_retry_after_longer_than_the_interval_is_honoured(): void
    {
        Http::fake(['api.binarylane.com.au/*' => Http::response(['title' => 'Too many requests'], 429, ['Retry-After' => '90'])]);

        $this->handle(new AwaitAction(5001, interval: 15))->assertReleased(90);
    }

    #[Test]
    public function a_retry_after_shorter_than_the_interval_does_not_shorten_it(): void
    {
        Http::fake(['api.binarylane.com.au/*' => Http::response(['title' => 'Too many requests'], 429, ['Retry-After' => '2'])]);

        $this->handle(new AwaitAction(5001, interval: 15))->assertReleased(15);
    }

    #[Test]
    public function a_server_error_past_the_deadline_fails_the_job(): void
    {
        Http::fake(['api.binarylane.com.au/*' => Http::response(['title' => 'Internal Server Error', 'status' => 500], 500)]);

        $job = (new AwaitAction(5001, timeout: 60))->withFakeQueueInteractions();

        $this->travel(61)->seconds();

        try {
            $this->runHandle($job);
            $this->fail('Expected the ServerException to be rethrown.');
        } catch (ServerException) {
            $job->assertFailedWith(ServerException::class);
            $job->assertNotReleased();
        }
    }

    #[Test]
    public function an_action_that_does_not_exist_fails_the_job_at_once(): void
    {
        // Asking again will not create it. With unlimited tries, a job that let this propagate
        // unfailed would be released by the worker and ask forever.
        $job = (new AwaitAction(5001))->withFakeQueueInteractions();

        Http::fake(['api.binarylane.com.au/*' => Http::response(['title' => 'Not Found', 'status' => 404], 404)]);

        try {
            $this->runHandle($job);
            $this->fail('Expected the NotFoundException to be rethrown.');
        } catch (NotFoundException) {
            $job->assertFailedWith(NotFoundException::class);
            $job->assertNotReleased();
        }
    }

    #[Test]
    public function a_token_that_is_not_valid_fails_the_job_at_once(): void
    {
        $job = (new AwaitAction(5001))->withFakeQueueInteractions();

        Http::fake(['api.binarylane.com.au/*' => Http::response('', 401)]);

        try {
            $this->runHandle($job);
            $this->fail('Expected the NotAuthenticatedException to be rethrown.');
        } catch (NotAuthenticatedException) {
            $job->assertFailedWith(NotAuthenticatedException::class);
        }
    }

    #[Test]
    public function an_account_that_is_no_longer_configured_fails_the_job_at_once(): void
    {
        // The name was valid when the job was dispatched and a deploy removed it. A job that
        // polled nothing forever would be the worst reading of that.
        Http::preventStrayRequests();

        $job = (new AwaitAction(5001, account: 'departed'))->withFakeQueueInteractions();

        try {
            $this->runHandle($job);
            $this->fail('Expected UnknownAccount.');
        } catch (UnknownAccount) {
            $job->assertFailedWith(UnknownAccount::class);
        }
    }

    #[Test]
    public function a_response_that_is_not_an_action_is_polled_again_rather_than_read_as_running(): void
    {
        // An empty 2xx reaches the core package's await() as an action with an id of 0 and no
        // status - indistinguishable there from one still running. Read that way, a
        // maintenance page would be polled to the deadline and reported as a timed-out
        // action #0. Here it is an unreadable response: retried, and logged.
        Event::fake(self::OUTCOMES);
        Http::fake(['api.binarylane.com.au/*' => Http::response('', 200)]);

        $this->handle(new AwaitAction(5001, interval: 15))->assertReleased(15);

        Event::assertNothingDispatched();
    }

    #[Test]
    public function a_response_that_is_not_an_action_past_the_deadline_fails_the_job(): void
    {
        Http::fake(['api.binarylane.com.au/*' => Http::response(['unexpected' => true])]);

        $job = (new AwaitAction(5001, timeout: 60))->withFakeQueueInteractions();

        $this->travel(61)->seconds();

        try {
            $this->runHandle($job);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not that action', $e->getMessage());
            $job->assertFailedWith(RuntimeException::class);
        }
    }

    #[Test]
    public function a_named_account_polls_with_that_accounts_token_and_says_so_on_the_event(): void
    {
        Event::fake(self::OUTCOMES);
        Http::fake(['api.binarylane.com.au/*' => Http::response(self::action(5001, 'completed'))]);

        $this->handle(new AwaitAction(5001, account: 'reseller'));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer reseller-token'));
        Event::assertDispatched(ActionCompleted::class, fn (ActionCompleted $event): bool => $event->account === 'reseller');
    }

    #[Test]
    public function the_sync_driver_is_refused_before_anything_is_asked(): void
    {
        // Testbench's queue connection is sync, which is the realistic trap: a release there
        // does nothing, so an action still running on the first poll would never be polled
        // again and no event would fire. Refused even when the action is already finished, so
        // it cannot work in development for fast actions and fail silently for slow ones.
        Http::preventStrayRequests();
        Http::fake(['api.binarylane.com.au/*' => Http::response(self::action(5001, 'completed'))]);

        $this->assertSame('sync', $this->container()->make('config')->get('queue.default'));

        try {
            Bus::dispatch(new AwaitAction(5001));
            $this->fail('Expected QueueRequired.');
        } catch (QueueRequired $e) {
            $this->assertStringContainsString('#5001', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function handling_without_a_queue_job_is_refused(): void
    {
        // Bus::dispatchNow() and a direct handle() both run the job with nothing to release it.
        Http::preventStrayRequests();

        $this->expectException(QueueRequired::class);

        $this->runHandle(new AwaitAction(5001));
    }

    #[Test]
    public function an_action_entity_is_accepted_and_only_its_id_is_kept(): void
    {
        $job = new AwaitAction(Action::fromArray(self::actionRow(5001, 'in-progress')));

        $this->assertSame(5001, $job->actionId);
        $this->assertStringNotContainsString('Starting server', serialize($job));
    }

    #[Test]
    public function an_action_id_of_zero_is_refused_at_dispatch(): void
    {
        // What a bodiless 202 looks like when it is read as an action. Refused here, in the
        // request that dispatched it, rather than an attempt later in a worker's log.
        $this->expectException(InvalidArgumentException::class);

        new AwaitAction(Action::fromArray([]));
    }

    #[Test]
    public function a_negative_timeout_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AwaitAction(5001, timeout: -1);
    }

    #[Test]
    public function an_interval_below_a_second_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AwaitAction(5001, interval: 0);
    }

    #[Test]
    public function the_job_carries_an_account_name_and_never_a_token(): void
    {
        // BinaryLane tokens are unscoped and do not expire, and most queue drivers store a
        // payload in plain text.
        $serialised = serialize(new AwaitAction(5001, account: 'reseller'));

        $this->assertStringContainsString('reseller', $serialised);
        $this->assertStringNotContainsString('reseller-token', $serialised);
        $this->assertStringNotContainsString('token-under-test', $serialised);
    }

    #[Test]
    public function the_tags_name_the_action(): void
    {
        $this->assertSame(['binarylane', 'binarylane:action:5001'], (new AwaitAction(5001))->tags());
    }

    #[Test]
    public function the_outcome_events_survive_serialisation_for_a_queued_listener(): void
    {
        // A queued listener serialises the event it is handed. The action is a plain object of
        // readonly properties, enums and dates, which should round-trip - asserted because a
        // listener that cannot be queued is found in production, not here.
        $action = Action::fromArray(self::actionRow(5001, 'in-progress', ['blocking_invoice_id' => 9182]));

        $event = unserialize(serialize(new ActionTimedOut('main', $action, 61)));

        $this->assertInstanceOf(ActionTimedOut::class, $event);
        $this->assertSame(9182, $event->action->blockingInvoiceId);
        $this->assertSame('2026-09-12T01:00:00+00:00', $event->action->startedAt?->format(DATE_ATOM));
        $this->assertTrue($event->action->isBlocked());
    }

    /**
     * One attempt, as a worker makes it: a fake queue job that records what the job did with
     * itself, then handle().
     */
    private function handle(AwaitAction $job): AwaitAction
    {
        $job->withFakeQueueInteractions();

        $this->runHandle($job);

        return $job;
    }

    private function runHandle(AwaitAction $job): void
    {
        $job->handle(
            $this->container()->make(BinaryLaneManager::class),
            $this->container()->make(Dispatcher::class),
            $this->container()->bound(LoggerInterface::class) ? $this->container()->make(LoggerInterface::class) : new NullLogger(),
        );
    }
}
