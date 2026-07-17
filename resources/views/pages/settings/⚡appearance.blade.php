<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <h2 class="sr-only">{{ __('Appearance settings') }}</h2>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <fieldset
            class="grid gap-3 sm:grid-cols-3"
            x-data="{ appearance: window.Appearance?.get() || 'system' }"
            x-on:appearance-changed.window="appearance = $event.detail"
        >
            <legend class="sr-only">{{ __('Color theme') }}</legend>
            @foreach ([
                ['value' => 'light', 'label' => __('Light'), 'symbol' => '☀'],
                ['value' => 'dark', 'label' => __('Dark'), 'symbol' => '◐'],
                ['value' => 'system', 'label' => __('System'), 'symbol' => '▣'],
            ] as $option)
                <label class="cursor-pointer">
                    <input class="peer sr-only" type="radio" name="appearance" value="{{ $option['value'] }}" x-model="appearance" x-on:change="window.Appearance.set(appearance)">
                    <span class="flex min-h-24 flex-col items-center justify-center gap-2 rounded-xl border border-stone-300 bg-white text-sm font-semibold shadow-sm transition hover:border-teal-500 peer-focus-visible:ring-2 peer-focus-visible:ring-teal-600 peer-focus-visible:ring-offset-2 peer-checked:border-teal-700 peer-checked:bg-teal-50 peer-checked:text-teal-950 dark:border-stone-700 dark:bg-stone-900 dark:peer-checked:border-teal-400 dark:peer-checked:bg-teal-950/50 dark:peer-checked:text-teal-100">
                        <span class="text-2xl" aria-hidden="true">{{ $option['symbol'] }}</span>
                        {{ $option['label'] }}
                    </span>
                </label>
            @endforeach
        </fieldset>
    </x-pages::settings.layout>
</section>
