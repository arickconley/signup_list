<div class="flex flex-col gap-8 md:flex-row md:gap-12">
    <aside class="w-full md:w-48 md:shrink-0">
        <nav class="flex gap-1 overflow-x-auto pb-1 md:flex-col" aria-label="{{ __('Settings') }}">
            @foreach ([
                ['route' => 'profile.edit', 'label' => __('Profile')],
                ['route' => 'security.edit', 'label' => __('Security')],
                ['route' => 'appearance.edit', 'label' => __('Appearance')],
            ] as $item)
                <a href="{{ route($item['route']) }}" wire:navigate @if (request()->routeIs($item['route'])) aria-current="page" @endif @class([
                    'min-h-11 shrink-0 rounded-lg px-3 py-2.5 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600',
                    'bg-teal-100 text-teal-950 dark:bg-teal-900/60 dark:text-teal-100' => request()->routeIs($item['route']),
                    'text-stone-600 hover:bg-stone-200/70 dark:text-stone-300 dark:hover:bg-stone-800' => ! request()->routeIs($item['route']),
                ])>{{ $item['label'] }}</a>
            @endforeach
        </nav>
    </aside>

    <div class="min-w-0 max-w-2xl flex-1">
        <div class="mb-6">
            <h2 class="font-display text-2xl font-semibold tracking-tight">{{ $heading ?? '' }}</h2>
            <p class="mt-1 text-sm leading-6 text-stone-600 dark:text-stone-400">{{ $subheading ?? '' }}</p>
        </div>
        {{ $slot }}
    </div>
</div>
