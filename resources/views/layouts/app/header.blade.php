<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body>
        <header class="border-b border-stone-200 bg-stone-50 dark:border-stone-800 dark:bg-stone-900">
            <div class="mx-auto flex min-h-16 max-w-7xl items-center justify-between px-4 sm:px-6">
                <x-app-logo href="{{ route('dashboard') }}" wire:navigate />
                <nav class="flex items-center gap-2" aria-label="{{ __('Primary navigation') }}">
                    <a href="{{ route('dashboard') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-semibold hover:bg-stone-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 dark:hover:bg-stone-800">{{ __('Dashboard') }}</a>
                    <x-desktop-user-menu class="w-56" />
                </nav>
            </div>
        </header>
        <main id="main-content" class="mx-auto max-w-7xl px-4 py-8 sm:px-6">{{ $slot }}</main>
        @livewireScripts
    </body>
</html>
