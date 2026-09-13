<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Jobs;

use Hampel\BinaryLane\Api\Endpoint\Actions;
use Hampel\BinaryLane\Api\Entity\Action;
use Hampel\BinaryLane\Api\Exception\ActionBlockedException;
use Hampel\BinaryLane\Api\Exception\ActionFailedException;
use Hampel\BinaryLane\Api\Exception\ActionTimedOutException;
use Hampel\BinaryLane\Api\Exception\ApiException;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Exception\MalformedResponseException;
use Hampel\BinaryLane\Api\Exception\RequestException;
use Hampel\BinaryLane\Api\Exception\RuntimeException;
use Hampel\BinaryLane\Api\Exception\ServerException;
use Hampel\BinaryLane\Api\Exception\TooManyRequestsException;
use Hampel\BinaryLane\Api\Laravel\BinaryLaneManager;
use Hampel\BinaryLane\Api\Laravel\Events\ActionBlocked;
use Hampel\BinaryLane\Api\Laravel\Events\ActionCompleted;
use Hampel\BinaryLane\Api\Laravel\Events\ActionFailed;
use Hampel\BinaryLane\Api\Laravel\Events\ActionTimedOut;
use Hampel\BinaryLane\Api\Laravel\Exception\QueueRequired;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\InteractsWithTime;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wait for a BinaryLane action on the queue, and say how it ended with an event.
 *
 *     $action = BinaryLane::serverActions()->powerOn(1234);
 *
 *     if ($action !== null) {
 *         dispatch(new AwaitAction($action));
 *     }
 *
 * Nearly every mutation on this API answers with an action rather than a result, and
 * `Actions::await()` - the core package's loop - blocks while it waits. That is right in a
 * console command and wrong in a web request, where a server build would hold the request open
 * for minutes. This is the same wait, spread across a queue.
 *
 * ONE REQUEST PER ATTEMPT, NEVER A SLEEP. Each attempt asks once - `await()` with a timeout of
 * 0 - and either finishes or releases itself back onto the queue to ask again in `$interval`
 * seconds. A worker is never blocked waiting, so there is no worker timeout for a long build
 * to run into, and a thousand pending actions cost a thousand delayed jobs rather than a
 * thousand sleeping processes. The classification is the core package's own: completed,
 * errored, blocked and still running are decided by `await()`, not re-derived here.
 *
 * HOW IT ENDS, and what happens to the job each time:
 *
 *  - completed          ActionCompleted.   The job succeeds.
 *  - errored            ActionFailed.      The job succeeds - it observed the action, which
 *                                          is its whole task. Retrying would ask the same
 *                                          question and get the same answer.
 *  - blocked            ActionBlocked.     The job succeeds. A question or an unpaid invoice
 *                                          does not resolve by waiting; answer it, then
 *                                          dispatch a new job.
 *  - past the deadline  ActionTimedOut.    The job succeeds. NOTHING WAS CANCELLED - the
 *                                          action is still running, and a new job will find it.
 *
 * AND WHEN IT CANNOT FIND OUT. A request that failed in a way the next poll may not repeat - a
 * 5xx, a 429, a transport failure, a malformed body, or an answer about some other action - is
 * logged and polled again after `$interval`, or after the API's `Retry-After` when that is
 * longer, until the deadline; past it, the job fails. Anything else fails the job at once, because asking again will not
 * change it: a token that is not valid, an action id that does not exist on this account, a
 * configuration that names no such account. A failed job is reported and lands in the failed
 * jobs table like any other.
 *
 * `$tries` IS 0, WHICH IS UNLIMITED, AND IT HAS TO BE. Every release counts as an attempt, and
 * `queue:work` defaults to `--tries=1` - so without this the second poll would fail with
 * MaxAttemptsExceededException before it asked anything. The deadline is the limit instead,
 * and it is this job's own rather than `retryUntil()`, which would fail a job still waiting in
 * the queue without ever running it.
 *
 * THE SYNC DRIVER CANNOT RUN THIS, and is refused rather than allowed to half-work: a release
 * on the sync queue does nothing, so an action still running on the first poll would never be
 * polled again and no event would ever fire. See QueueRequired.
 *
 * NO CREDENTIAL IS SERIALISED. The payload holds an account NAME, resolved against the
 * configuration when the job runs, never a client or a token - BinaryLane tokens are unscoped
 * and do not expire, and a queue payload is stored in plain text by most drivers.
 */
final class AwaitAction implements ShouldQueue
{
    use InteractsWithQueue;
    use InteractsWithTime;
    use Queueable;

    /**
     * How long to keep polling, when not told: an hour.
     *
     * Longer than the core package's ten minutes because waiting costs nothing here - no
     * process is held open - and a rebuild, a region change or a Windows install can outlast
     * ten minutes comfortably. Pass a longer one for work known to take longer still.
     */
    public const DEFAULT_TIMEOUT = 3600;

    /**
     * How long between polls, when not told.
     *
     * Each poll is a queue round trip as well as a request, so this is coarser than the core
     * package's five seconds. On Amazon SQS it cannot exceed 900, the longest delay SQS accepts.
     */
    public const DEFAULT_INTERVAL = 10;

    /**
     * Unlimited attempts. See the class note: the deadline is the limit.
     */
    public int $tries = 0;

    public readonly int $actionId;

    /**
     * When polling stops, as a Unix timestamp. Fixed at dispatch, so time spent waiting in the
     * queue counts against it: the timeout is measured from when the wait was asked for.
     */
    public readonly int $deadline;

    public readonly int $dispatchedAt;

    /**
     * @param  Action|int  $action  the action, or its id. Only the id is kept
     * @param  string|null  $account  the configured account the action belongs to. Null is the
     *                                default account, as it is when the job runs
     * @param  int  $timeout  seconds from now to stop polling. 0 polls once
     * @param  int  $interval  seconds between polls
     */
    public function __construct(
        Action|int $action,
        public readonly ?string $account = null,
        int $timeout = self::DEFAULT_TIMEOUT,
        public readonly int $interval = self::DEFAULT_INTERVAL,
    ) {
        // Validated here, at dispatch, rather than in the worker: an action id of 0 is what a
        // 202 read as an action looks like, and finding that out an attempt later, in a log,
        // is finding it out somewhere nobody is looking.
        $this->actionId = Actions::idOf($action);

        if ($timeout < 0) {
            throw new InvalidArgumentException('A timeout cannot be negative; 0 means poll once.');
        }

        if ($interval < 1) {
            throw new InvalidArgumentException(
                'A polling interval of less than a second would spend requests without learning '
                    . 'anything; BinaryLane actions do not move that fast.'
            );
        }

        $this->dispatchedAt = $this->currentTime();
        $this->deadline = $this->dispatchedAt + $timeout;
    }

    public function handle(BinaryLaneManager $binarylane, Dispatcher $events, LoggerInterface $logger): void
    {
        if ($this->job === null || $this->job instanceof SyncJob) {
            throw QueueRequired::toPoll($this->actionId);
        }

        $account = $this->account ?? $binarylane->getDefaultAccount();

        try {
            $action = $binarylane->client($account)->actions()->await($this->actionId, 0, $this->interval);
            $outcome = new ActionCompleted($account, $action);
        } catch (ActionFailedException $e) {
            $action = $e->action;
            $outcome = new ActionFailed($account, $action);
        } catch (ActionBlockedException $e) {
            $action = $e->action;
            $outcome = new ActionBlocked($account, $action);
        } catch (ActionTimedOutException $e) {
            // A timeout of 0 is "check once", so this is the core package saying the action is
            // still running - not that anything has timed out yet.
            $action = $e->action;
            $outcome = null;
        } catch (ServerException | TooManyRequestsException | RequestException | MalformedResponseException $e) {
            $this->retryOrFail($e, $logger);

            return;
        } catch (Throwable $e) {
            // Marked failed BEFORE rethrowing, which is what stops the worker treating this
            // like any other exception: with unlimited tries it would release the job and ask
            // again forever. Rethrown so the application's exception handler still reports it.
            $this->fail($e);

            throw $e;
        }

        // Checked before ANY outcome, not only a running one. The core package proves the
        // response is shaped like an action; it does not prove it is the action that was asked
        // for, and an answer about a different one - a misrouted proxy, a cached response -
        // would otherwise fire an event reporting that action's outcome as this one's.
        if ($action->id !== $this->actionId) {
            $this->retryOrFail(new RuntimeException(sprintf(
                'BinaryLane answered a request for action #%d with action #%d.',
                $this->actionId,
                $action->id
            )), $logger);

            return;
        }

        if ($outcome === null) {
            $this->pollAgainOrGiveUp($account, $action, $events);

            return;
        }

        $events->dispatch($outcome);
    }

    /**
     * Horizon's tags, so a stuck poll can be found by the action it is waiting on.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return ['binarylane', 'binarylane:action:' . $this->actionId];
    }

    /**
     * The worker's delay after an exception this job did not handle.
     *
     * Only reachable for an exception raised outside handle() itself, since handle() catches
     * everything. Set so that nothing can release this job with the worker's default backoff of
     * zero, which with unlimited tries would poll BinaryLane in a tight loop.
     */
    public function backoff(): int
    {
        return $this->interval;
    }

    private function pollAgainOrGiveUp(string $account, Action $action, Dispatcher $events): void
    {
        $remaining = $this->deadline - $this->currentTime();

        if ($remaining <= 0) {
            $events->dispatch(new ActionTimedOut($account, $action, $this->currentTime() - $this->dispatchedAt));

            return;
        }

        // Never past the deadline: the last poll lands on it rather than an interval beyond,
        // so a timeout reported is the timeout that was asked for.
        $this->release(min($this->interval, $remaining));
    }

    private function retryOrFail(Throwable $e, LoggerInterface $logger): void
    {
        $remaining = $this->deadline - $this->currentTime();

        if ($remaining <= 0) {
            $this->fail($e);

            throw $e;
        }

        // Retry-After arrives on a 429 and may on a 503. Honoured when it asks for longer than
        // the interval; never allowed to shorten it.
        $delay = $e instanceof ApiException ? max($this->interval, $e->retryAfter ?? 0) : $this->interval;

        $logger->warning('Could not poll a BinaryLane action; polling again', [
            'action' => $this->actionId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'retry_in' => min($delay, $remaining),
        ]);

        $this->release(min($delay, $remaining));
    }
}
