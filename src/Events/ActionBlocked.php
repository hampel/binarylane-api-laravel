<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Events;

use Hampel\BinaryLane\Api\Entity\Action;

/**
 * An awaited action has stopped and will not resume on its own.
 *
 * BinaryLane still reports it `in-progress`, and will for as long as nobody acts. Two things
 * put it here:
 *
 *  - `$action->needsInteraction()` - it asked a question. `Actions::proceed()` answers it, and
 *    `UserInteractionType` explains what each answer does; neither is a formality.
 *  - `$action->isBlockedByInvoice()` - an unpaid invoice, named by `blockingInvoiceId`.
 *
 * AwaitAction stops polling when it sees this. Dispatch a new one after acting, if the outcome
 * still matters.
 */
final class ActionBlocked
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
