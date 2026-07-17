<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when the owner attempts to grant Team Access with fewer than 2
 * seats — 1 seat leaves no room for anyone but the recipient themself, so
 * "team access" would grant invite-ability with nothing to invite anyone into.
 *
 * HTTP 422 Unprocessable Entity.
 */
class InsufficientSeats extends HttpException
{
    public function __construct(int $seats)
    {
        parent::__construct(422, "Team Access requires at least 2 seats (1 for the recipient, 1+ to invite — got {$seats}).");
    }
}
