<?php

namespace App\Exceptions;

use RuntimeException;

final class CannotChangeSignupClaims extends RuntimeException
{
    /**
     * @param  array<int, string>  $unavailableOptionNames
     * @param  array<int, string>  $unavailableOptionPublicIds
     */
    public function __construct(
        string $message,
        public readonly array $unavailableOptionNames = [],
        public readonly array $unavailableOptionPublicIds = [],
    ) {
        parent::__construct($message);
    }
}
