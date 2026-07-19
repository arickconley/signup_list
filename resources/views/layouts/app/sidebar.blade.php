@props(['title' => null, 'robots' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['robots' => $robots])
    </head>
    <body>
        <div
            class="min-h-screen lg:flex"
            x-data="{
                navOpen: false,
                desktop: window.matchMedia('(min-width: 1024px)').matches,
                init() {
                    const media = window.matchMedia('(min-width: 1024px)');

                    media.addEventListener('change', (event) => {
                        this.desktop = event.matches;

                        if (this.desktop) this.navOpen = false;
                    });
                },
                openNav() {
                    this.navOpen = true;
                    this.$nextTick(() => this.$refs.navCloseTrigger?.focus());
                },
                closeNav() {
                    if (! this.navOpen) return;

                    this.navOpen = false;
                    this.$nextTick(() => this.$refs.navOpenTrigger?.focus());
                },
            }"
            x-on:keydown.escape.window="closeNav()"
        >
            <header class="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-stone-200 bg-stone-50/95 px-4 backdrop-blur lg:hidden dark:border-stone-800 dark:bg-stone-950/95">
                <x-app-logo href="{{ route('dashboard') }}" wire:navigate />
                <button type="button" class="flex size-11 items-center justify-center rounded-lg hover:bg-stone-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 dark:hover:bg-stone-800" x-ref="navOpenTrigger" x-on:click="openNav()" aria-controls="app-navigation" x-bind:aria-expanded="navOpen">
                    <span class="sr-only">{{ __('Open navigation') }}</span>
                    <x-ui.icon name="menu" class="size-6" />
                </button>
            </header>

            <div x-show="navOpen" x-cloak class="fixed inset-0 z-40 bg-stone-950/40 backdrop-blur-sm lg:hidden" x-on:click="closeNav()" aria-hidden="true"></div>

            <aside
                id="app-navigation"
                class="fixed inset-y-0 start-0 z-50 flex w-72 flex-col border-e border-stone-200 bg-stone-50 p-4 transition-transform duration-200 lg:sticky lg:top-0 lg:z-20 lg:h-screen lg:translate-x-0 dark:border-stone-800 dark:bg-stone-900"
                x-bind:class="navOpen ? 'translate-x-0' : '-translate-x-full'"
                x-bind:inert="!desktop && !navOpen"
                x-bind:aria-hidden="(!desktop && !navOpen).toString()"
            >
                <div class="flex items-center justify-between">
                    <x-app-logo href="{{ route('dashboard') }}" wire:navigate />
                    <button type="button" class="flex size-11 items-center justify-center rounded-lg hover:bg-stone-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 lg:hidden dark:hover:bg-stone-800" x-ref="navCloseTrigger" x-on:click="closeNav()">
                        <span class="sr-only">{{ __('Close navigation') }}</span>
                        <x-ui.icon name="close" class="size-6" />
                    </button>
                </div>

                <nav class="mt-8" aria-label="{{ __('Primary navigation') }}">
                    <p class="px-3 text-xs font-bold uppercase tracking-[0.18em] text-stone-500 dark:text-stone-400">{{ __('Workspace') }}</p>
                    <a href="{{ route('dashboard') }}" wire:navigate @class([
                        'mt-2 flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600',
                        'bg-teal-100 text-teal-950 dark:bg-teal-900/60 dark:text-teal-100' => request()->routeIs('dashboard'),
                        'text-stone-700 hover:bg-stone-200/70 dark:text-stone-200 dark:hover:bg-stone-800' => ! request()->routeIs('dashboard'),
                    ])>
                        <x-ui.icon name="home" class="size-5" />
                        {{ __('Dashboard') }}
                    </a>
                </nav>

                <div class="mt-auto border-t border-stone-200 pt-4 dark:border-stone-800">
                    <x-desktop-account-menu />
                </div>
            </aside>

            <div class="flex min-h-svh min-w-0 flex-1 flex-col">
                <a href="#main-content" class="sr-only z-[60] rounded-lg bg-white px-4 py-3 font-semibold focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:ring-2 focus:ring-teal-600 dark:bg-stone-900">{{ __('Skip to content') }}</a>
                <x-ui.flash class="m-4 lg:mx-10 lg:mt-6" />
                {{ $slot }}
                <x-site-footer class="mt-auto" />
            </div>
        </div>

        @livewireScripts
    </body>
</html>
