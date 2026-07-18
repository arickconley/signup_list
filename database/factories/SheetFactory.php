<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Sheet> */
class SheetFactory extends Factory
{
    protected $model = Sheet::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'owner_id' => Account::factory(),
            'title' => fake()->sentence(3),
            'deadline_at' => now()->addDays(14)->setTime(23, 59),
            'timezone' => 'America/Los_Angeles',
        ];
    }
}
