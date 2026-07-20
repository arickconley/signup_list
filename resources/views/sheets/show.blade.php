<x-layouts::public :title="$sheet->title" robots="noindex, nofollow" :include-livewire="true">
    <a href="#main-content" class="fixed start-4 top-4 z-50 -translate-y-24 rounded-md bg-stone-950 px-4 py-3 text-sm font-bold text-white shadow-lg transition focus-visible:translate-y-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 focus-visible:ring-offset-2 dark:bg-stone-50 dark:text-stone-950">
        {{ __('Skip to signup sheet') }}
    </a>

    <div class="paper-grid min-h-svh">
        <header class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-5 sm:px-6 sm:py-7" aria-label="{{ __('Site header') }}">
            <x-app-logo href="{{ route('home') }}" />
            <p class="hidden text-xs font-bold uppercase tracking-[0.18em] text-stone-500 sm:block dark:text-stone-400">
                {{ __('Shared signup sheet') }}
            </p>
        </header>

        <main id="main-content" tabindex="-1" class="mx-auto w-full max-w-6xl px-4 pb-16 pt-3 outline-none sm:px-6 sm:pb-24 sm:pt-8">
            <article x-data class="relative border border-stone-300 bg-amber-50/95 shadow-[0_26px_70px_-38px_rgba(28,25,23,0.75)] dark:border-stone-700 dark:bg-stone-900">
                <span class="absolute -top-3 start-1/2 h-6 w-28 -translate-x-1/2 -rotate-1 bg-amber-200/90 shadow-sm dark:bg-amber-700/80" aria-hidden="true"></span>

                <header class="grid gap-8 border-b-2 border-stone-800 px-5 pb-8 pt-10 sm:px-9 sm:pb-10 sm:pt-12 lg:grid-cols-[minmax(0,1fr)_18rem] lg:gap-12 dark:border-stone-200">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-teal-800 dark:text-teal-300">{{ __('Community field notice') }}</p>
                        <h1 class="mt-4 max-w-3xl text-pretty font-display text-4xl font-semibold leading-[1.05] tracking-tight sm:text-5xl lg:text-6xl">
                            {{ $sheet->title }}
                        </h1>
                        @if (filled($sheet->description))
                            <p class="mt-6 max-w-3xl whitespace-pre-line text-base leading-7 text-stone-700 sm:text-lg sm:leading-8 dark:text-stone-300">{{ $sheet->description }}</p>
                        @endif
                    </div>

                    <div class="self-start border-t border-stone-300 pt-5 lg:border-s lg:border-t-0 lg:ps-8 lg:pt-0 dark:border-stone-700">
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Sheet status') }}</p>
                        <p role="status" @class([
                            'mt-3 inline-flex items-center gap-2 rounded-full border px-3 py-2 text-sm font-bold',
                            'border-teal-800 bg-teal-50 text-teal-900 dark:border-teal-400 dark:bg-teal-950/60 dark:text-teal-200' => $isOpen,
                            'border-stone-500 bg-stone-200 text-stone-950 dark:border-stone-500 dark:bg-stone-800 dark:text-stone-100' => ! $isOpen,
                        ])>
                            <x-ui.icon :name="$isOpen ? 'check' : 'lock'" class="size-4" />
                            {{ $isOpen ? __('Open for signups') : __('Closed to signups') }}
                        </p>
                        <p class="mt-3 text-sm leading-6 text-stone-600 dark:text-stone-400">
                            {{ $isOpen ? __('Review the capacity ledger below.') : __('The deadline has passed. This sheet remains available to review.') }}
                        </p>
                    </div>
                </header>

                @php
                    $detailCount = 1 + (int) filled($sheet->event_at) + (int) filled($sheet->location);
                @endphp
                <dl @class([
                    'grid border-b border-stone-300 bg-white/45 dark:border-stone-700 dark:bg-stone-950/25',
                    'sm:grid-cols-2' => $detailCount === 2,
                    'sm:grid-cols-3' => $detailCount === 3,
                ])>
                    @if ($sheet->event_at)
                        <div class="border-b border-stone-300 px-5 py-5 sm:border-b-0 sm:border-e sm:px-9 dark:border-stone-700">
                            <dt class="text-[0.7rem] font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Event') }}</dt>
                            <dd class="mt-2 font-semibold leading-6">
                                <time datetime="{{ $sheet->event_at->toIso8601String() }}">
                                    {{ $sheet->event_at->timezone($sheet->timezone)->format('M j, Y \a\t g:i A T') }}
                                </time>
                            </dd>
                        </div>
                    @endif

                    @if (filled($sheet->location))
                        <div class="border-b border-stone-300 px-5 py-5 sm:border-b-0 sm:border-e sm:px-9 dark:border-stone-700">
                            <dt class="text-[0.7rem] font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Location') }}</dt>
                            <dd class="mt-2 font-semibold leading-6">{{ $sheet->location }}</dd>
                        </div>
                    @endif

                    <div class="px-5 py-5 sm:px-9">
                        <dt class="text-[0.7rem] font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Signup deadline') }}</dt>
                        <dd class="mt-2 font-semibold leading-6">
                            <time datetime="{{ $sheet->deadline_at->toIso8601String() }}">
                                {{ $sheet->deadline_at->timezone($sheet->timezone)->format('M j, Y \a\t g:i A T') }}
                            </time>
                        </dd>
                    </div>
                </dl>

                <section class="px-5 py-8 sm:px-9 sm:py-11" aria-labelledby="options-title">
                    <div class="flex flex-col gap-2 border-b-2 border-stone-800 pb-5 sm:flex-row sm:items-end sm:justify-between dark:border-stone-200">
                        <div>
                            <p class="font-mono text-xs font-bold uppercase tracking-[0.16em] text-amber-800 dark:text-amber-300">{{ __('Capacity ledger') }}</p>
                            <h2 id="options-title" class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('Options') }}</h2>
                        </div>
                        <p class="text-sm text-stone-600 dark:text-stone-400">{{ trans_choice(':count option|:count options', $options->count(), ['count' => $options->count()]) }}</p>
                    </div>

                    @if (session()->has('option-claimed'))
                        <x-ui.callout class="mt-5" :heading="session('option-claimed')" />
                    @endif

                    <ol class="divide-y divide-stone-300 border-b border-stone-400 dark:divide-stone-700 dark:border-stone-600">
                        @foreach ($options as $option)
                            @php
                                $remaining = max($option->capacity - $option->claimed_count, 0);
                                $isOverCapacity = $option->claimed_count > $option->capacity;
                                $isFull = $option->claimed_count === $option->capacity;
                                $isAvailable = $isOpen && ! $isFull && ! $isOverCapacity;
                                $isClaimedByParticipant = in_array($option->id, $participantClaimedOptionIds, true);
                                $showsParticipantControl = $sheet->participation_policy === \App\Models\Sheet::PARTICIPATION_OPEN
                                    && ($isAvailable || $isClaimedByParticipant);
                            @endphp
                            <li>
                                <article class="grid gap-6 py-6 sm:grid-cols-[minmax(0,1fr)_28rem] sm:items-center sm:gap-8 sm:py-7" aria-labelledby="option-{{ $loop->iteration }}-name">
                                    <div class="min-w-0">
                                        <h3 id="option-{{ $loop->iteration }}-name" class="text-xl font-bold leading-tight sm:text-2xl">{{ $option->name }}</h3>
                                        <p @class([
                                            'mt-3 inline-flex items-center gap-2 text-xs font-bold uppercase tracking-[0.14em]',
                                            'text-teal-800 dark:text-teal-300' => $isAvailable,
                                            'text-stone-600 dark:text-stone-300' => $isFull || ! $isOpen,
                                            'text-amber-800 dark:text-amber-300' => $isOverCapacity,
                                        ])>
                                            <span class="flex size-5 items-center justify-center rounded-full border border-current" aria-hidden="true">
                                                {{ $isAvailable ? '✓' : '×' }}
                                            </span>
                                            @if ($isOverCapacity)
                                                {{ __('Over capacity — unavailable') }}
                                            @elseif ($isFull)
                                                {{ __('Full — unavailable') }}
                                            @elseif (! $isOpen)
                                                {{ __('Sheet closed — unavailable') }}
                                            @else
                                                {{ __('Available') }}
                                            @endif
                                        </p>
                                        @if (filled($option->description))
                                            <p class="mt-2 max-w-2xl whitespace-pre-line text-sm leading-6 text-stone-600 sm:text-base dark:text-stone-400">{{ $option->description }}</p>
                                        @endif
                                    </div>

                                    <div @class([
                                        'grid',
                                        'sm:grid-cols-[minmax(0,1fr)_8.5rem]' => $showsParticipantControl,
                                    ]) data-option-controls>
                                        <dl class="grid grid-cols-3 divide-x divide-stone-300 border border-stone-300 bg-white/55 text-center dark:divide-stone-700 dark:border-stone-700 dark:bg-stone-950/30" aria-label="{{ __('Capacity for :option', ['option' => $option->name]) }}">
                                            <div class="px-2 py-3">
                                                <dt class="text-[0.65rem] font-bold uppercase tracking-[0.12em] text-stone-500 dark:text-stone-400">{{ __('Total') }}</dt>
                                                <dd class="mt-1 font-mono text-xl font-bold tabular-nums">{{ $option->capacity }}</dd>
                                            </div>
                                            <div class="px-2 py-3">
                                                <dt class="text-[0.65rem] font-bold uppercase tracking-[0.12em] text-stone-500 dark:text-stone-400">{{ __('Claimed') }}</dt>
                                                <dd class="mt-1 font-mono text-xl font-bold tabular-nums">{{ $option->claimed_count }}</dd>
                                            </div>
                                            <div class="px-2 py-3">
                                                <dt class="text-[0.65rem] font-bold uppercase tracking-[0.12em] text-stone-500 dark:text-stone-400">{{ __('Remaining') }}</dt>
                                                <dd class="mt-1 font-mono text-xl font-bold tabular-nums">{{ $remaining }}</dd>
                                            </div>
                                        </dl>

                                        @if ($isClaimedByParticipant)
                                            <p class="flex min-h-11 items-center justify-center gap-2 border border-teal-900 bg-teal-50 px-3 py-3 font-mono text-xs font-bold uppercase tracking-[0.12em] text-teal-900 sm:h-full sm:border-s-0 dark:border-teal-300 dark:bg-teal-950/50 dark:text-teal-200">
                                                <span aria-hidden="true">✓</span>
                                                {{ __('Yours') }}
                                            </p>
                                        @elseif ($isAvailable && $participantReachedSelectionMaximum)
                                            <p class="flex min-h-11 items-center justify-center border border-stone-400 bg-stone-100 px-3 py-3 text-center font-mono text-[0.65rem] font-bold uppercase tracking-[0.1em] text-stone-600 sm:h-full sm:border-s-0 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-300">
                                                {{ __('Maximum reached') }}
                                            </p>
                                        @elseif ($isAvailable && $sheet->participation_policy === \App\Models\Sheet::PARTICIPATION_OPEN)
                                            <x-ui.button
                                                variant="ledger"
                                                class="group w-full justify-between py-3 sm:h-full sm:justify-center sm:border-s-0 sm:px-3"
                                                x-on:click="$dispatch('claim-option', { optionPublicId: '{{ $option->public_id }}' })"
                                                :aria-label="__('Claim :option', ['option' => $option->name])"
                                            >
                                                <span class="font-mono text-xs font-bold uppercase tracking-[0.16em]">{{ __('Claim') }}</span>
                                                <span class="flex size-7 items-center justify-center border border-current text-lg leading-none transition-transform duration-150 group-hover:translate-x-0.5" aria-hidden="true">→</span>
                                            </x-ui.button>
                                        @endif
                                    </div>
                                </article>

                                @php
                                    $publicClaims = $option->optionClaims->filter(function ($claim) use ($sheet) {
                                        $signup = $claim->signup;

                                        return $sheet->name_visibility === \App\Models\Sheet::VISIBILITY_PARTICIPANTS
                                            || ($sheet->email_visibility === \App\Models\Sheet::VISIBILITY_PARTICIPANTS && $signup->email_consent && filled($signup->email_snapshot))
                                            || ($sheet->phone_visibility === \App\Models\Sheet::VISIBILITY_PARTICIPANTS && $signup->phone_consent && filled($signup->phone_snapshot));
                                    });
                                @endphp
                                @if ($publicClaims->isNotEmpty())
                                    <section class="border-t border-stone-300 py-5 dark:border-stone-700" aria-label="{{ __('Participants for :option', ['option' => $option->name]) }}">
                                        <h4 class="text-xs font-bold uppercase tracking-[0.14em] text-stone-500 dark:text-stone-400">{{ __('Participants') }}</h4>
                                        <ul class="mt-3 grid gap-3 sm:grid-cols-2">
                                            @foreach ($publicClaims as $claim)
                                                @php($signup = $claim->signup)
                                                <li class="border-s-2 border-teal-700 ps-3 dark:border-teal-400">
                                                    @if ($sheet->name_visibility === \App\Models\Sheet::VISIBILITY_PARTICIPANTS)
                                                        <p class="font-semibold">{{ $signup->name_consent ? $signup->name_snapshot : $signup->initials() }}</p>
                                                    @endif
                                                    @if ($sheet->email_visibility === \App\Models\Sheet::VISIBILITY_PARTICIPANTS && $signup->email_consent && filled($signup->email_snapshot))
                                                        <p class="mt-1 break-words text-sm text-stone-600 dark:text-stone-400">{{ $signup->email_snapshot }}</p>
                                                    @endif
                                                    @if ($sheet->phone_visibility === \App\Models\Sheet::VISIBILITY_PARTICIPANTS && $signup->phone_consent && filled($signup->phone_snapshot))
                                                        <p class="mt-1 break-words text-sm text-stone-600 dark:text-stone-400">{{ $signup->phone_snapshot }}</p>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </section>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </section>

                @if ($isOpen && $sheet->participation_policy === \App\Models\Sheet::PARTICIPATION_OPEN)
                    <livewire:complete-open-signup :sheet-public-id="$sheet->public_id" />
                @elseif ($isOpen && $sheet->participation_policy === \App\Models\Sheet::PARTICIPATION_VERIFIED)
                    <section class="border-t-2 border-stone-800 px-5 py-8 sm:px-9 sm:py-11 dark:border-stone-200" aria-labelledby="verified-participation-title">
                        <div class="max-w-3xl">
                            <p class="font-mono text-xs font-bold uppercase tracking-[0.16em] text-teal-800 dark:text-teal-300">{{ __('Verified Participation') }}</p>
                            <h2 id="verified-participation-title" class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('Verify your email before choosing Options') }}</h2>
                            <p class="mt-3 text-sm leading-6 text-stone-600 dark:text-stone-400">{{ __('A verified Account reserves capacity once per participant. Passwordless email access is available.') }}</p>
                            <x-ui.link :href="route('sheets.participate', $sheet)" class="mt-6 inline-flex min-h-11 items-center rounded-lg bg-teal-700 px-5 py-3 font-bold text-white no-underline hover:bg-teal-800 dark:bg-teal-500 dark:text-stone-950 dark:hover:bg-teal-400">
                                {{ __('Continue with verified email') }}
                            </x-ui.link>
                        </div>
                    </section>
                @endif
            </article>

            <footer class="mt-6 flex flex-col gap-2 px-1 text-xs leading-5 text-stone-500 sm:flex-row sm:items-center sm:justify-between dark:text-stone-400">
                <p>{{ __('Capacity reflects confirmed claims.') }}</p>
                <p>{{ __('Times shown in :timezone.', ['timezone' => str_replace('_', ' ', $sheet->timezone)]) }}</p>
            </footer>
        </main>
    </div>
</x-layouts::public>
