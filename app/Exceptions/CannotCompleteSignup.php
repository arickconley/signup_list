<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class CannotCompleteSignup extends RuntimeException
{
    /**
     * @param  array<int, string>  $unavailableOptionNames
     * @param  array<int, string>  $unavailableOptionPublicIds
     */
    public function __construct(
        string $message,
        public readonly array $unavailableOptionNames = [],
        public readonly array $unavailableOptionPublicIds = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
