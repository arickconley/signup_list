<?php

namespace App\Data;

use App\Models\Signup;

final readonly class SignupClaimTarget
{
    public function __construct(
        public Signup $signup,
        public bool $alreadyComplete,
    ) {}
}
