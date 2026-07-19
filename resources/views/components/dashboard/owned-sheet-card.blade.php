@props(['sheet', 'lifecycle'])

@php
    $editUrl = route('sheets.edit', $sheet, absolute: false);
    $signupViewUrl = route('sheets.signups', $sheet, absolute: false);
    $managementUrl = $editUrl.'#sheet-actions-title';
    $duplicateUrl = $lifecycle === 'archived'
        ? $signupViewUrl.'#sheet-actions-title'
        : $managementUrl;
@endphp

<article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm dark:border-stone-800 dark:bg-stone-900">
    <span @class([
        'text-xs font-bold uppercase tracking-[0.16em]',
        'text-teal-700 dark:text-teal-400' => in_array($lifecycle, ['draft', 'open'], true),
        'text-stone-600 dark:text-stone-400' => in_array($lifecycle, ['closed', 'archived'], true),
    ])>{{ __(ucfirst($lifecycle)) }}</span>
    <h3 class="mt-2 font-display text-xl font-semibold">{{ $sheet->title }}</h3>

    <nav class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-3 border-t border-stone-200 pt-4 text-sm dark:border-stone-800" aria-label="{{ __('Actions for :title', ['title' => $sheet->title]) }}">
        @if (in_array($lifecycle, ['open', 'closed'], true))
            <x-ui.link class="inline-flex min-h-11 items-center" :href="route('sheets.show', $sheet, absolute: false)">{{ __('View Sheet') }}</x-ui.link>
        @endif

        @if ($lifecycle !== 'archived')
            <x-ui.link class="inline-flex min-h-11 items-center" :href="$editUrl" wire:navigate>{{ __('Edit Sheet') }}</x-ui.link>
        @endif

        @if (in_array($lifecycle, ['open', 'closed', 'archived'], true))
            <x-ui.link class="inline-flex min-h-11 items-center" :href="$signupViewUrl" wire:navigate>{{ __('View Signups') }}</x-ui.link>
        @endif

        @if ($lifecycle === 'open')
            <x-ui.link class="inline-flex min-h-11 items-center" :href="$managementUrl">{{ __('Close Sheet') }}</x-ui.link>
        @elseif ($lifecycle === 'closed')
            <x-ui.link class="inline-flex min-h-11 items-center" :href="$managementUrl">{{ __('Reopen Sheet') }}</x-ui.link>
        @endif

        @if (in_array($lifecycle, ['open', 'closed'], true))
            <x-ui.link class="inline-flex min-h-11 items-center" :href="$managementUrl">{{ __('Archive Sheet') }}</x-ui.link>
        @endif

        <x-ui.link class="inline-flex min-h-11 items-center" :href="$duplicateUrl">{{ __('Duplicate Sheet') }}</x-ui.link>

        @if (in_array($lifecycle, ['open', 'closed', 'archived'], true))
            <x-ui.link class="inline-flex min-h-11 items-center" :href="route('sheets.signups.print', $sheet, absolute: false)">{{ __('Print Signups') }}</x-ui.link>
        @endif
    </nav>
</article>
