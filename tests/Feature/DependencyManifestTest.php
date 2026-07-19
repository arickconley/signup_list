<?php

test('direct Linux build dependencies exclude stale Rollup while retaining required binaries', function () {
    $package = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
    $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($package['optionalDependencies'])
        ->not->toHaveKey('@rollup/rollup-linux-x64-gnu')
        ->toHaveKeys([
            '@tailwindcss/oxide-linux-x64-gnu',
            'lightningcss-linux-x64-gnu',
        ])
        ->and($lock['packages']['']['optionalDependencies'])
        ->not->toHaveKey('@rollup/rollup-linux-x64-gnu')
        ->toHaveKeys([
            '@tailwindcss/oxide-linux-x64-gnu',
            'lightningcss-linux-x64-gnu',
        ])
        ->and($lock['packages'])->not->toHaveKey('node_modules/@rollup/rollup-linux-x64-gnu')
        ->toHaveKeys([
            'node_modules/@tailwindcss/oxide-linux-x64-gnu',
            'node_modules/lightningcss-linux-x64-gnu',
        ]);
});
