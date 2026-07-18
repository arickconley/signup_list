<?php

namespace App\Data;

final readonly class SignupDefaults
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $phone,
        public string $timezone,
    ) {}
}
