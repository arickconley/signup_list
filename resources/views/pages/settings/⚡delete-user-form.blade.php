<?php

use Livewire\Component;

new class extends Component {}; ?>

<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <h3 class="font-display text-xl font-semibold">{{ __('Delete account') }}</h3>
        <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ __('Delete your account and all of its resources') }}</p>
    </div>

    <x-ui.button variant="danger" data-test="delete-user-button" x-on:click="$dispatch('open-delete-account')">
        {{ __('Delete account') }}
    </x-ui.button>

    <livewire:pages::settings.delete-user-modal />
</section>
