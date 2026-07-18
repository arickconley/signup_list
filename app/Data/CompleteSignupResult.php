<?php

namespace App\Data;

final readonly class CompleteSignupResult
{
    public function __construct(
        public bool $checkEmail,
        public ?string $accessChallengePublicId = null,
    ) {}
}
