<?php

use App\Models\Account;
use App\Models\Sheet;

test('every Sheet response is noindex including guest redirects and missing models', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
    ]);
    $signup = $sheet->signups()->create(['name_snapshot' => 'Noindex Participant']);

    foreach ([
        route('sheets.create'),
        route('sheets.edit', $sheet),
        route('sheets.signups', $sheet),
        route('sheets.signups.print', $sheet),
        route('sheets.participate', $sheet),
        route('signups.edit', $signup),
    ] as $url) {
        $this->get($url)
            ->assertRedirect(route('login'))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    $unknown = '00000000-0000-4000-8000-000000000000';

    foreach ([
        "/sheets/{$unknown}/edit",
        "/sheets/{$unknown}/signups",
        "/sheets/{$unknown}/signups/print",
        "/sheets/{$unknown}/participate",
        "/signups/{$unknown}/edit",
    ] as $url) {
        $this->actingAs($owner)
            ->get($url)
            ->assertNotFound()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
});
