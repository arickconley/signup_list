@props(['variant' => 'info', 'heading' => null])

<div role="alert" {{ $attributes->class([
    'rounded-lg border px-4 py-3 text-sm',
    'border-red-300 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200' => $variant === 'danger',
    'border-teal-300 bg-teal-50 text-teal-900 dark:border-teal-900 dark:bg-teal-950/50 dark:text-teal-100' => $variant !== 'danger',
]) }}>
    @if ($heading)<p class="font-semibold">{{ $heading }}</p>@endif
    {{ $slot }}
</div>
