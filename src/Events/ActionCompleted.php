<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Events;

use Hampel\BinaryLane\Api\Entity\Action;

/**
 * An awaited action finished and worked - status `completed`.
 *
 * `$action->resourceId` and `$action->type` say what it was done to and what it was, which is
 * usually enough for a listener to find its own record of why it was asked for.
 *
 * Fired by AwaitAction. The four outcome events deliberately share no parent class: Laravel's
 * dispatcher matches listeners by class and by interface, never by parent class, so a listener
 * on a common base would silently hear nothing.
 */
final class ActionCompleted
{
    /**
     * @param  string  $account  the configured account the action belongs to
     */
    public function __construct(
        public readonly string $account,
        public readonly Action $action,
    ) {
    }
}
