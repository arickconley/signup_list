<?php

namespace App\Data;

final readonly class AccountDefaults
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $phone,
        public string $timezone,
    ) {}
}
