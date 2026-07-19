@props([
    'status',
])

@if ($status)
    <div {{ $attributes->merge([
        'role' => 'status',
        'aria-live' => 'polite',
        'class' => 'font-medium text-sm text-green-700 dark:text-green-400',
    ]) }}>
        {{ $status }}
    </div>
@endif
