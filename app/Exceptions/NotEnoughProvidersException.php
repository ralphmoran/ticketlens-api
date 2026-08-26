<?php

namespace App\Exceptions;

/**
 * Thrown when consensus round 1 doesn't have enough successful providers to
 * reconcile (needs 2+). Zero successes gets the user-specified universal
 * no-provider message; 1 success gets a consensus-specific one, since "1
 * provider answered" is meaningfully different from "none did."
 */
class NotEnoughProvidersException extends \RuntimeException
{
    public function __construct(public readonly int $successCount, public readonly array $errors)
    {
        parent::__construct(
            $successCount === 0
                ? 'Error: No AI provider is available or an unknown error has occurred.'
                : "Only {$successCount} provider(s) responded successfully — need at least 2 for consensus."
        );
    }
}
