<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class CannotUnclaimOpenParticipationOption extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
