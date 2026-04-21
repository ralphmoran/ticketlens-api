<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when a team manager attempts to invite a new member but the
 * group's license seat count is already at capacity.
 *
 * HTTP 409 Conflict — semantically "this state conflicts with the
 * invariant that members ≤ license.seats."
 */
class SeatLimitReached extends HttpException
{
    public function __construct(int $seats)
    {
        parent::__construct(409, "Seat limit reached — upgrade the plan or remove a member to invite more (current limit: {$seats}).");
    }
}
