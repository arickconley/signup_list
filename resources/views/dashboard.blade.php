<x-layouts::app :title="__('Dashboard')">
    <div class="mx-auto max-w-5xl">
        <div class="flex flex-col gap-4 border-b border-stone-200 pb-6 sm:flex-row sm:items-end sm:justify-between dark:border-stone-800">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-teal-400">{{ __('Your workspace') }}</p>
                <h1 class="mt-2 font-display text-4xl font-semibold tracking-tight">{{ __('Signup sheets') }}</h1>
                <p class="mt-2 max-w-xl text-stone-600 dark:text-stone-400">{{ __('Create a Signup Sheet, send its shareable link, and keep every contribution organized.') }}</p>
            </div>
            <a href="{{ route('sheets.create') }}" wire:navigate class="inline-flex min-h-11 items-center justify-center rounded-lg bg-teal-700 px-4 text-sm font-semibold text-white shadow-sm hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 dark:bg-teal-500 dark:text-stone-950 dark:hover:bg-teal-400">
                {{ __('Create a signup sheet') }}
            </a>
        </div>

        @if ($drafts->isEmpty() && $attachedSignups->isEmpty())
            <section class="paper-grid mt-8 rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-6 py-14 text-center dark:border-stone-700 dark:bg-stone-900/60" aria-labelledby="empty-sheets-title">
                <span class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-amber-200 text-amber-950 shadow-sm dark:bg-amber-300">
                    <x-app-logo-icon class="size-8" />
                </span>
                <h2 id="empty-sheets-title" class="mt-5 font-display text-2xl font-semibold">{{ __('No signup sheets yet') }}</h2>
                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-stone-600 dark:text-stone-400">{{ __('Create your first Draft Sheet when you are ready.') }}</p>
            </section>
        @endif

        @if ($drafts->isNotEmpty())
            <section class="mt-8" aria-labelledby="draft-sheets-title">
                <h2 id="draft-sheets-title" class="font-display text-2xl font-semibold">{{ __('Draft Sheets') }}</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ($drafts as $draft)
                        <a href="{{ route('sheets.edit', $draft) }}" wire:navigate class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm transition hover:border-teal-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 dark:border-stone-800 dark:bg-stone-900 dark:hover:border-teal-500">
                            <span class="text-xs font-bold uppercase tracking-[0.16em] text-teal-700 dark:text-teal-400">{{ __('Draft') }}</span>
                            <h3 class="mt-2 font-display text-xl font-semibold">{{ $draft->title }}</h3>
                            <span class="mt-4 inline-block text-sm font-semibold text-stone-600 dark:text-stone-300">{{ __('Resume editing') }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($attachedSignups->isNotEmpty())
            <section class="mt-10" aria-labelledby="attached-signups-title">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-700 dark:text-amber-400">{{ __('Your Signups') }}</p>
                    <h2 id="attached-signups-title" class="mt-2 font-display text-2xl font-semibold">{{ __('Joined Signup Sheets') }}</h2>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ($attachedSignups as $signup)
                        <article class="group rounded-2xl border border-stone-200 bg-white p-5 shadow-sm transition hover:border-amber-500 dark:border-stone-800 dark:bg-stone-900 dark:hover:border-amber-400">
                            <a href="{{ route('sheets.show', $signup->sheet, absolute: false) }}" wire:navigate class="block rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-4 dark:focus-visible:ring-offset-stone-900">
                                <span class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700 dark:text-amber-400">{{ __('Signup') }}</span>
                                <h3 class="mt-2 font-display text-xl font-semibold group-hover:text-amber-800 dark:group-hover:text-amber-300">{{ $signup->sheet->title }}</h3>
                                <p class="mt-2 text-sm text-stone-600 dark:text-stone-400">
                                    {{ __('Signed up as :name', ['name' => $signup->name_snapshot]) }}
                                </p>
                                <ul class="mt-4 flex flex-wrap gap-2" aria-label="{{ __('Your selections') }}">
                                    @foreach ($signup->optionClaims->sortBy('option.position') as $claim)
                                        <li class="rounded-full bg-amber-100 px-3 py-1 text-sm font-medium text-amber-950 dark:bg-amber-300 dark:text-stone-950">
                                            {{ $claim->option->name }}
                                        </li>
                                    @endforeach
                                </ul>
                            </a>
                            <div class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-3 text-sm">
                                <x-ui.link :href="route('sheets.show', $signup->sheet, absolute: false)" wire:navigate>{{ __('View Signup Sheet') }}</x-ui.link>
                                @if ($signup->canBeEditedBy(auth()->user()))
                                    <x-ui.link :href="route('signups.edit', $signup, absolute: false)" wire:navigate>{{ __('Edit Signup') }}</x-ui.link>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts::app>
