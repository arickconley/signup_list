<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', [
            'title' => __('Print View'),
            'robots' => 'noindex, nofollow',
            'includeLivewire' => false,
        ])
        <style media="print">
            nav,
            button,
            [data-print-chrome] {
                display: none !important;
            }
        </style>
    </head>
    <body>
        <div data-print-chrome class="mx-auto flex max-w-5xl flex-col gap-3 px-4 pt-6 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <x-ui.link href="{{ route('sheets.signups', $sheet, absolute: false) }}">
                {{ __('Back to Signup View') }}
            </x-ui.link>
            <x-ui.button type="button" onclick="window.print()">
                {{ __('Print') }}
            </x-ui.button>
        </div>
        <main id="main-content" class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
            <header class="border-b-2 border-stone-900 pb-6">
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-stone-600">{{ __('Owner Print View') }}</p>
                <h1 class="mt-2 font-display text-4xl font-semibold tracking-tight">{{ $sheet->title }}</h1>
                <p class="mt-3 text-sm font-semibold text-stone-700">
                    {{ $grouping === 'participant' ? __('Grouped by Participant') : __('Grouped by Option') }}
                </p>
                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-3">
                    @if ($sheet->event_at)
                        <div>
                            <dt class="font-semibold text-stone-600">{{ __('Event') }}</dt>
                            <dd class="mt-1">
                                <time datetime="{{ $sheet->event_at->toIso8601String() }}">
                                    {{ $sheet->event_at->timezone($sheet->timezone)->format('M j, Y \a\t g:i A T') }}
                                </time>
                            </dd>
                        </div>
                    @endif
                    @if (filled($sheet->location))
                        <div>
                            <dt class="font-semibold text-stone-600">{{ __('Location') }}</dt>
                            <dd class="mt-1">{{ $sheet->location }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="font-semibold text-stone-600">{{ __('Signup deadline') }}</dt>
                        <dd class="mt-1">
                            <time datetime="{{ $sheet->deadline_at->toIso8601String() }}">
                                {{ $sheet->deadline_at->timezone($sheet->timezone)->format('M j, Y \a\t g:i A T') }}
                            </time>
                        </dd>
                    </div>
                </dl>
            </header>

            @if ($capacityOptions->isNotEmpty())
            <section class="mt-8" aria-labelledby="capacity-summary-title">
                <h2 id="capacity-summary-title" class="font-display text-2xl font-semibold">{{ __('Option capacity') }}</h2>
                <ol class="mt-4 grid gap-3" aria-label="{{ __('Option capacity summary') }}">
                    @foreach ($capacityOptions as $capacityOption)
                        @php
                            $claimed = $capacityOption->option_claims_count;
                            $remaining = max($capacityOption->capacity - $claimed, 0);
                        @endphp
                        <li class="border-b border-stone-300 pb-3">
                            <h3 class="font-semibold">{{ $capacityOption->name }}</h3>
                            <dl class="mt-2 grid grid-cols-3 gap-4 text-sm">
                                <div>
                                    <dt>{{ __('Capacity') }}</dt>
                                    <dd>{{ $capacityOption->capacity }}</dd>
                                </div>
                                <div>
                                    <dt>{{ __('Claimed') }}</dt>
                                    <dd>{{ $claimed }}</dd>
                                </div>
                                <div>
                                    <dt>{{ __('Remaining') }}</dt>
                                    <dd>{{ $remaining }}</dd>
                                </div>
                            </dl>
                            @if ($claimed > $capacityOption->capacity)
                                <p class="mt-2 font-semibold">{{ __('Over-Capacity — :count over', ['count' => $claimed - $capacityOption->capacity]) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>
            @endif

            @if ($grouping === 'participant')
            <section class="mt-8" aria-labelledby="participant-print-title">
                <h2 id="participant-print-title" class="font-display text-2xl font-semibold">{{ __('Participants') }}</h2>
                @if ($signups->isEmpty())
                    <p class="mt-4 text-sm text-stone-600" role="status">{{ __('No Signups yet') }}</p>
                @else
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b-2 border-stone-900">
                                <th scope="col">{{ __('Participant') }}</th>
                                <th scope="col">{{ __('Option Claims') }}</th>
                                @if ($showEmail)
                                    <th scope="col">{{ __('Email') }}</th>
                                @endif
                                @if ($showPhone)
                                    <th scope="col">{{ __('Phone') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($signups as $signup)
                                <tr class="border-b border-stone-300 align-top">
                                    <th scope="row" class="py-3 pe-4 font-semibold">{{ $signup->name_snapshot }}</th>
                                    <td class="px-4 py-3">
                                        <ul>
                                            @foreach ($signup->optionClaims->sortBy('option.position') as $claim)
                                                <li>{{ $claim->option->name }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                    @if ($showEmail)
                                        <td class="py-3 ps-4">{{ $signup->email_snapshot ?? __('Not submitted') }}</td>
                                    @endif
                                    @if ($showPhone)
                                        <td class="py-3 ps-4">{{ $signup->phone_snapshot ?? __('Not submitted') }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </section>
            @else
            <section class="mt-8" aria-labelledby="option-print-title">
                <h2 id="option-print-title" class="font-display text-2xl font-semibold">{{ __('Options') }}</h2>
                @if ($options->isEmpty())
                    <p class="mt-4 text-sm text-stone-600" role="status">{{ __('No Options yet') }}</p>
                @else
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b-2 border-stone-900">
                                <th scope="col">{{ __('Option') }}</th>
                                <th scope="col">{{ __('Participants') }}</th>
                                @if ($showEmail)
                                    <th scope="col">{{ __('Email') }}</th>
                                @endif
                                @if ($showPhone)
                                    <th scope="col">{{ __('Phone') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($options as $option)
                                <tr class="border-b border-stone-300 align-top">
                                    <th scope="row" class="py-3 pe-4 font-semibold">{{ $option->name }}</th>
                                    @if ($option->optionClaims->isEmpty())
                                        <td class="px-4 py-3">{{ __('No Option Claims') }}</td>
                                        @if ($showEmail)
                                            <td class="px-4 py-3" aria-label="{{ __('No submitted emails') }}">—</td>
                                        @endif
                                        @if ($showPhone)
                                            <td class="py-3 ps-4" aria-label="{{ __('No submitted phone numbers') }}">—</td>
                                        @endif
                                    @else
                                        <td class="px-4 py-3">
                                            <ul>
                                                @foreach ($option->optionClaims as $claim)
                                                    <li>{{ $claim->signup->name_snapshot }}</li>
                                                @endforeach
                                            </ul>
                                        </td>
                                        @if ($showEmail)
                                            <td class="px-4 py-3">
                                                <ul>
                                                    @foreach ($option->optionClaims as $claim)
                                                        <li>{{ $claim->signup->email_snapshot ?? __('Not submitted') }}</li>
                                                    @endforeach
                                                </ul>
                                            </td>
                                        @endif
                                        @if ($showPhone)
                                            <td class="py-3 ps-4">
                                                <ul>
                                                    @foreach ($option->optionClaims as $claim)
                                                        <li>{{ $claim->signup->phone_snapshot ?? __('Not submitted') }}</li>
                                                    @endforeach
                                                </ul>
                                            </td>
                                        @endif
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </section>
            @endif
        </main>
        <x-site-footer />
    </body>
</html>
