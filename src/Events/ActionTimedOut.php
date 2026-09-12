<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Events;

use Hampel\BinaryLane\Api\Entity\Action;

/**
 * AwaitAction reached its deadline. The action is still running.
 *
 * NOTHING HAS BEEN CANCELLED - there is no way to cancel an action through the API. This is a
 * statement about how long the job was prepared to wait, not about the work, and a new
 * AwaitAction for the same id will find it finished eventually.
 *
 * `$action` is the action as last seen, so `$action->progress` says how far it had got.
 */
final class ActionTimedOut
{
    /**
     * @param  string  $account  the configured account the action belongs to
     * @param  int  $waited  seconds from dispatch to giving up, queue time included
     */
    public function __construct(
        public readonly string $account,
        public readonly Action $action,
        public readonly int $waited,
    ) {
    }
}
