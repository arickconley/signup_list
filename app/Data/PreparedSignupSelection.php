<?php

namespace App\Data;

use App\Models\Option;
use App\Models\OptionClaim;

final readonly class PreparedSignupSelection
{
    /**
     * @param  array<int, Option>  $addedOptions
     * @param  array<int, OptionClaim>  $removedClaims
     */
    public function __construct(
        public array $addedOptions,
        public array $removedClaims,
    ) {}
}
