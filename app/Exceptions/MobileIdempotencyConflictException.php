<?php

namespace App\Exceptions;

use App\Models\MobileClientAction;
use RuntimeException;

class MobileIdempotencyConflictException extends RuntimeException
{
    public function __construct(public readonly MobileClientAction $clientAction)
    {
        parent::__construct('The client action id has already been used with a different request.');
    }
}
