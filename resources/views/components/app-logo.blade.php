@props(['sidebar' => false])

<a {{ $attributes->class('inline-flex items-center gap-3 rounded-lg font-semibold text-stone-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 dark:text-stone-50') }}>
    <span class="flex size-9 items-center justify-center rounded-lg bg-teal-700 text-white shadow-sm dark:bg-teal-500 dark:text-stone-950">
        <x-app-logo-icon class="size-6" />
    </span>
    <span class="font-display text-lg tracking-tight">{{ config('app.name', 'Signup Sheets') }}</span>
</a>
