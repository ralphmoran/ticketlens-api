<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when the owner attempts to grant Team Access to a recipient who
 * cannot receive it: the platform owner themself, or a Team/Enterprise-tier
 * client who already has real team capability via normal issuance.
 *
 * HTTP 422 Unprocessable Entity — the request is well-formed but the
 * recipient is not eligible for this grant.
 */
class InvalidGrantRecipient extends HttpException
{
    public function __construct(string $reason)
    {
        parent::__construct(422, $reason);
    }
}
