<?php

use App\Models\Account;
use App\Models\Sheet;

test('full-page Livewire routes render the application layout exactly once', function (string $routeName) {
    $account = Account::factory()->create();
    $sheet = Sheet::factory()->for($account, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Layout Test Participant',
        'email_snapshot' => $account->email,
    ]);
    $signup->forceFill(['account_id' => $account->id])->save();

    $url = match ($routeName) {
        'signups.edit' => route($routeName, $signup),
        'sheets.edit', 'sheets.signups' => route($routeName, $sheet),
        default => route($routeName),
    };

    $response = $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get($url)
        ->assertOk();

    $html = $response->getContent();

    expect(substr_count($html, '<!DOCTYPE html>'))->toBe(1)
        ->and(substr_count($html, 'aria-label="Primary navigation"'))->toBe(1)
        ->and(substr_count($html, 'id="main-content"'))->toBe(1);
})->with([
    'create Sheet' => 'sheets.create',
    'edit Sheet' => 'sheets.edit',
    'Owner Signup View' => 'sheets.signups',
    'edit Signup' => 'signups.edit',
    'profile settings' => 'profile.edit',
    'appearance settings' => 'appearance.edit',
    'security settings' => 'security.edit',
]);
