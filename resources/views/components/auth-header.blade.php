@props([
    'title',
    'description',
])

<div class="flex w-full flex-col gap-2 text-center">
    <h1 class="font-display text-3xl font-semibold tracking-tight text-stone-950 dark:text-white">{{ $title }}</h1>
    <p class="text-sm leading-6 text-stone-600 dark:text-stone-400">{{ $description }}</p>
</div>
