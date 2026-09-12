<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Events;

use Hampel\BinaryLane\Api\Entity\Action;

/**
 * An awaited action finished and did not work - status `errored`.
 *
 * `$action->failureReason()` is the explanation when BinaryLane gave one, and null when it did
 * not, which is often. `$action->reason` is NOT an explanation: it narrates what was being
 * attempted, in the same words whether the action worked or not.
 *
 * Fired by AwaitAction, which does not retry: an errored action stays errored.
 */
final class ActionFailed
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
