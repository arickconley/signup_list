@props([
    'variant' => 'primary',
    'size' => 'md',
    'icon' => null,
    'type' => 'button',
])

@php
    $variants = [
        'primary' => 'border-transparent bg-teal-700 text-white shadow-sm hover:bg-teal-800 dark:bg-teal-500 dark:text-stone-950 dark:hover:bg-teal-400',
        'danger' => 'border-transparent bg-red-700 text-white shadow-sm hover:bg-red-800 dark:bg-red-600 dark:hover:bg-red-500',
        'outline' => 'border-stone-300 bg-white text-stone-800 shadow-sm hover:bg-stone-50 dark:border-stone-600 dark:bg-stone-900 dark:text-stone-100 dark:hover:bg-stone-800',
        'filled' => 'border-transparent bg-stone-200 text-stone-900 hover:bg-stone-300 dark:bg-stone-700 dark:text-stone-100 dark:hover:bg-stone-600',
        'ghost' => 'border-transparent bg-transparent text-stone-700 hover:bg-stone-100 dark:text-stone-200 dark:hover:bg-stone-800',
    ];
    $sizes = [
        'sm' => 'min-h-11 px-3 text-sm',
        'md' => 'min-h-11 px-4 text-sm',
    ];
@endphp

<button
    type="{{ $type }}"
    {{ $attributes->class([
        'inline-flex cursor-pointer items-center justify-center gap-2 rounded-lg border font-semibold transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 dark:focus-visible:ring-teal-400 dark:focus-visible:ring-offset-stone-950',
        $variants[$variant] ?? $variants['primary'],
        $sizes[$size] ?? $sizes['md'],
    ]) }}
>
    @if ($icon)
        <x-ui.icon :name="$icon" class="size-4" />
    @endif
    {{ $slot }}
</button>
