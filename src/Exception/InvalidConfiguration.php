<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Exception;

use Hampel\BinaryLane\Api\Exception\BinaryLaneException;

/**
 * The configuration cannot be turned into a client.
 *
 * Raised rather than letting a half-configured account through, and every case it covers is
 * raised HERE rather than where it would otherwise surface. A missing token reaches the core
 * package as an empty credential and fails on first use as a 401 - which reads as a revoked
 * token rather than as an unset environment variable. An unusable page size or base URI is
 * rejected by the core package with a message about an argument, which is accurate and says
 * nothing about where to go and fix it.
 */
final class InvalidConfiguration extends BinaryLaneException
{
    public static function missingToken(string $account): self
    {
        return new self(sprintf(
            'BinaryLane account "%s" has no token. Set it in config/binarylane.php, or in the '
            . 'environment if the shipped config is in use. Every BinaryLane endpoint needs a '
            . 'credential, so there is no anonymous request to fall back to.',
            $account
        ));
    }

    /**
     * A page size of 0 or less, which the core package would accept as "count only".
     */
    public static function pageSizeBelowOne(int $perPage): self
    {
        return new self(sprintf(
            'binarylane.per_page is %d. A page size of 0 asks BinaryLane for a total and no items, '
            . 'which as a default would make every list() answer an empty page. Use a size from '
            . '1 to 200, or leave it unset for the API\'s own default of 20.',
            $perPage
        ));
    }

    /**
     * The core package refused the page size or base URI.
     *
     * Wrapped rather than passed through, so the message names the configuration rather than
     * an argument - and kept as the previous exception, so the original reason is still
     * readable.
     */
    public static function api(\Throwable $previous): self
    {
        return new self(
            'The binarylane.per_page and binarylane.base_uri settings do not describe an API this '
            . 'package can talk to. ' . $previous->getMessage(),
            0,
            $previous
        );
    }
}
