<?php

namespace App\Data;

final readonly class UpdateParticipantSignupInput
{
    /**
     * @param  array<int, string>  $optionPublicIds
     */
    public function __construct(
        public int $signupId,
        public string $name,
        public ?string $phone,
        public array $optionPublicIds,
        public bool $nameConsent,
        public bool $emailConsent,
        public bool $phoneConsent,
    ) {}
}
