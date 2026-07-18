<details {{ $attributes->class('group relative') }}>
    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-3 rounded-lg px-2 py-1.5 text-start hover:bg-stone-200/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 dark:hover:bg-stone-800 [&::-webkit-details-marker]:hidden" data-test="sidebar-menu-button">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-amber-200 text-sm font-bold text-amber-950 dark:bg-amber-300">{{ auth()->user()->initials() }}</span>
        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-semibold">{{ auth()->user()->name ?: auth()->user()->email }}</span>
            <span class="block truncate text-xs text-stone-500 dark:text-stone-400">{{ auth()->user()->email }}</span>
        </span>
        <span class="text-stone-500 transition group-open:rotate-180" aria-hidden="true">⌄</span>
    </summary>

    <div class="absolute inset-x-0 bottom-full z-30 mb-2 rounded-xl border border-stone-200 bg-white p-2 shadow-xl dark:border-stone-700 dark:bg-stone-900">
        <a href="{{ route('profile.edit') }}" wire:navigate class="flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-medium hover:bg-stone-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 dark:hover:bg-stone-800">
            <x-ui.icon name="settings" class="size-4" /> {{ __('Settings') }}
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-lg px-3 text-sm font-medium hover:bg-stone-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 dark:hover:bg-stone-800" data-test="logout-button">
                <x-ui.icon name="logout" class="size-4" /> {{ __('Log out') }}
            </button>
        </form>
    </div>
</details>
