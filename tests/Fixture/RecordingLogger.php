<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Tests\Fixture;

use Psr\Log\AbstractLogger;

/**
 * Keeps what was logged, so a test can say what an operator would have seen.
 *
 * The parameters are untyped on purpose: psr/log 1.x declares log() without types, and a
 * narrower parameter here would not be allowed to override it on the lowest resolvable tree.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param  mixed  $level
     * @param  string|\Stringable  $message
     * @param  array<mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : '',
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
