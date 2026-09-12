<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Exception;

use Hampel\BinaryLane\Api\Exception\BinaryLaneException;

/**
 * AwaitAction was run somewhere it cannot release itself back onto a queue.
 *
 * It polls by releasing itself with a delay, and on the sync driver - or when handle() is
 * called directly - a release does nothing. Allowed through, an action still running on the
 * first poll would never be polled again and no event would ever fire, which fails silently
 * in exactly the case the job exists for. So it is refused on every run, including the ones
 * where the action happened to be finished already: a job that works in development only for
 * fast actions is worse than one that does not work there at all.
 *
 * In a test, `$job->withFakeQueueInteractions()` before `handle()` gives it a job that records
 * releases rather than ignoring them.
 */
final class QueueRequired extends BinaryLaneException
{
    public static function toPoll(int $actionId): self
    {
        return new self(sprintf(
            'AwaitAction for BinaryLane action #%d needs a queue that can release a job with a '
                . 'delay, and was run synchronously. Dispatch it to a real queue connection - '
                . 'database, redis, sqs - rather than sync, or call Actions::await() directly '
                . 'where blocking is acceptable, as in a console command.',
            $actionId
        ));
    }
}
