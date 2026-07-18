<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body>
        <div class="paper-grid min-h-svh">
            <header class="mx-auto flex max-w-6xl items-center justify-between px-4 py-5 sm:px-6">
                <x-app-logo href="{{ route('home') }}" />
                <nav class="flex items-center gap-2" aria-label="{{ __('Account') }}">
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-lg px-4 py-2.5 text-sm font-semibold hover:bg-stone-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 dark:hover:bg-stone-800">{{ __('Dashboard') }}</a>
                    @else
                        <a href="{{ route('login') }}" class="rounded-lg px-4 py-2.5 text-sm font-semibold hover:bg-stone-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 dark:hover:bg-stone-800">{{ __('Log in') }}</a>
                        <a href="{{ route('login') }}" class="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 dark:bg-teal-500 dark:text-stone-950 dark:hover:bg-teal-400">{{ __('Create account') }}</a>
                    @endauth
                </nav>
            </header>

            <main>
                <section class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-[1.05fr_.95fr] lg:py-32">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-teal-700 dark:text-teal-400">{{ __('Bring something. Help out. Show up.') }}</p>
                        <h1 class="mt-5 max-w-3xl font-display text-5xl font-semibold leading-[1.02] tracking-tight sm:text-6xl lg:text-7xl">{{ __('Simple signups for real communities.') }}</h1>
                        <p class="mt-6 max-w-xl text-lg leading-8 text-stone-600 dark:text-stone-300">{{ __('Make a Signup Sheet, share one private link, and let people claim what they can bring—without forcing everyone to create an account.') }}</p>
                        <div class="mt-8 flex flex-wrap gap-3">
                            @auth
                                <a href="{{ route('dashboard') }}" class="inline-flex min-h-12 items-center rounded-lg bg-teal-700 px-5 font-semibold text-white shadow-sm hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 dark:bg-teal-500 dark:text-stone-950 dark:hover:bg-teal-400">{{ __('Open dashboard') }}</a>
                            @else
                                <a href="{{ route('login') }}" class="inline-flex min-h-12 items-center rounded-lg bg-teal-700 px-5 font-semibold text-white shadow-sm hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 dark:bg-teal-500 dark:text-stone-950 dark:hover:bg-teal-400">{{ __('Create your first sheet') }}</a>
                            @endauth
                            <a href="#how-it-works" class="inline-flex min-h-12 items-center rounded-lg border border-stone-300 bg-white px-5 font-semibold shadow-sm hover:bg-stone-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 dark:border-stone-700 dark:bg-stone-900 dark:hover:bg-stone-800">{{ __('How it works') }}</a>
                        </div>
                    </div>

                    <div class="relative mx-auto w-full max-w-lg rotate-1 rounded-2xl border border-stone-300 bg-amber-50 p-5 shadow-[0_30px_70px_-35px_rgba(28,25,23,0.7)] dark:border-stone-700 dark:bg-stone-900">
                        <span class="absolute -top-3 start-1/2 h-7 w-24 -translate-x-1/2 -rotate-2 bg-amber-200/80 shadow-sm dark:bg-amber-700/70" aria-hidden="true"></span>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-stone-500">{{ __('Saturday picnic') }}</p>
                        <h2 class="mt-2 font-display text-3xl font-semibold">{{ __('What can you bring?') }}</h2>
                        <div class="mt-6 space-y-3">
                            @foreach ([
                                [__('Fruit & snacks'), __('2 spots left')],
                                [__('Cold drinks'), __('1 spot left')],
                                [__('Blankets'), __('3 spots left')],
                            ] as [$option, $spots])
                                <div class="flex items-center gap-4 rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-700 dark:bg-stone-950">
                                    <span class="flex size-6 items-center justify-center rounded-md border-2 border-teal-700 text-teal-700 dark:border-teal-400 dark:text-teal-400">✓</span>
                                    <span class="min-w-0 flex-1 font-semibold">{{ $option }}</span>
                                    <span class="text-xs text-stone-500 dark:text-stone-400">{{ $spots }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>

                <section id="how-it-works" class="border-y border-stone-200 bg-white/70 dark:border-stone-800 dark:bg-stone-900/60">
                    <div class="mx-auto grid max-w-6xl gap-px px-4 py-14 sm:grid-cols-3 sm:px-6">
                        @foreach ([
                            ['01', __('Create'), __('Add a title, details, and options with fixed capacities.')],
                            ['02', __('Share'), __('Send an unguessable link to the people you want to invite.')],
                            ['03', __('Coordinate'), __('See remaining spots and manage claims without spreadsheet chaos.')],
                        ] as [$number, $title, $description])
                            <article class="border-stone-200 py-6 sm:border-s sm:px-6 sm:first:border-s-0 dark:border-stone-700">
                                <p class="font-mono text-xs font-bold text-amber-700 dark:text-amber-400">{{ $number }}</p>
                                <h2 class="mt-3 font-display text-2xl font-semibold">{{ $title }}</h2>
                                <p class="mt-2 text-sm leading-6 text-stone-600 dark:text-stone-400">{{ $description }}</p>
                            </article>
                        @endforeach
                    </div>
                </section>
            </main>
        </div>

        @livewireScripts
    </body>
</html>
