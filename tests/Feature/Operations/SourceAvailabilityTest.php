<?php

use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Testing\TestResponse;

function assertSourceAvailabilityDisclosure(TestResponse $response): void
{
    $response
        ->assertOk()
        ->assertSee('Source for deployed version 0123456789ab')
        ->assertSee('href="https://code.example.test/signup/tree/0123456789abcdef0123456789abcdef01234567"', false)
        ->assertSee('GNU Affero General Public License v3.0 or later')
        ->assertSee('href="https://code.example.test/signup/blob/0123456789abcdef0123456789abcdef01234567/LICENSE"', false)
        ->assertSee('No warranty is provided');
}

beforeEach(function () {
    config([
        'deployment.source.ref' => '0123456789abcdef0123456789abcdef01234567',
        'deployment.source.url' => 'https://code.example.test/signup/tree/0123456789abcdef0123456789abcdef01234567',
        'deployment.source.license_url' => 'https://code.example.test/signup/blob/0123456789abcdef0123456789abcdef01234567/LICENSE',
    ]);
});

test('the home page exposes source availability and warranty terms', function () {
    assertSourceAvailabilityDisclosure($this->get(route('home')));
});

test('the authentication layout exposes source availability and warranty terms', function () {
    assertSourceAvailabilityDisclosure($this->get(route('login')));
});

test('the Account layout exposes source availability and warranty terms', function () {
    $account = Account::factory()->create();

    assertSourceAvailabilityDisclosure($this->actingAs($account)->get(route('dashboard')));
});

test('the shared Signup Sheet layout exposes source availability and warranty terms', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $sheet->options()->create([
        'name' => 'Coffee urn',
        'capacity' => 1,
        'position' => 1,
    ]);

    assertSourceAvailabilityDisclosure($this->get(route('sheets.show', $sheet)));
});

test('the interactive Print View exposes source availability and warranty terms', function () {
    $account = Account::factory()->create();
    $sheet = Sheet::factory()->for($account, 'owner')->create();

    assertSourceAvailabilityDisclosure(
        $this->actingAs($account)->get(route('sheets.signups.print', $sheet)),
    );
});
