<?php

namespace App\Data;

final readonly class CompleteSignupInput
{
    /**
     * @param  array<int, string>  $optionPublicIds
     */
    public function __construct(
        public string $sheetPublicId,
        public string $name,
        public ?string $phone,
        public array $optionPublicIds,
        public ?string $email = null,
        public string $ipAddress = 'unknown',
    ) {}
}
