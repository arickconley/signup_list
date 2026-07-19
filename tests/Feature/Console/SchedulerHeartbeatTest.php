<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('the scheduler records current heartbeat evidence atomically on its configured schedule', function () {
    $this->travelTo('2026-07-19 18:30:00 UTC');
    $disk = storage_path('framework/testing/scheduler-heartbeat-'.Str::uuid());
    $heartbeat = $disk.'/scheduler-heartbeat.json';
    File::ensureDirectoryExists($disk);
    File::put($heartbeat, '{"recorded_at":"stale"}');
    config(['deployment.scheduler.heartbeat_path' => $heartbeat]);

    try {
        $this->artisan('app:scheduler-heartbeat')->assertSuccessful();

        expect(json_decode((string) File::get($heartbeat), true, flags: JSON_THROW_ON_ERROR))
            ->toBe(['recorded_at' => '2026-07-19T18:30:00+00:00'])
            ->and(File::files($disk))->toHaveCount(1);

        $this->artisan('schedule:list')
            ->expectsOutputToContain('app:scheduler-heartbeat')
            ->assertSuccessful();
    } finally {
        File::deleteDirectory($disk);
    }
});
