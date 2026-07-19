<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body>
        <main class="paper-grid relative flex min-h-svh items-center justify-center overflow-hidden px-4 py-10 sm:px-6">
            <div class="absolute -start-24 top-12 size-64 rounded-full bg-amber-300/25 blur-3xl dark:bg-amber-700/10" aria-hidden="true"></div>
            <div class="absolute -end-20 bottom-10 size-72 rounded-full bg-teal-300/20 blur-3xl dark:bg-teal-800/10" aria-hidden="true"></div>

            <div class="relative w-full max-w-md">
                <div class="mb-7 flex justify-center">
                    <x-app-logo href="{{ route('home') }}" wire:navigate />
                </div>
                <div class="rounded-2xl border border-stone-200 bg-white p-6 shadow-[0_18px_60px_-30px_rgba(28,25,23,0.45)] sm:p-8 dark:border-stone-800 dark:bg-stone-900">
                    <x-ui.flash class="mb-6" />
                    {{ $slot }}
                </div>
            </div>
        </main>

        <x-site-footer />

        @livewireScripts
    </body>
</html>
