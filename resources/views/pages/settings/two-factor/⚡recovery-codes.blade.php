<?php

use App\Actions\ChangeAccountTwoFactorAuthentication;
use App\Support\FreshAuthentication;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public array $recoveryCodes = [];

    /**
     * Generate new recovery codes for the user.
     */
    public function regenerateRecoveryCodes(
        ChangeAccountTwoFactorAuthentication $changeTwoFactorAuthentication,
        FreshAuthentication $freshAuthentication,
    ): void {
        $freshAuthentication->ensure();
        $this->recoveryCodes = $changeTwoFactorAuthentication->regenerateRecoveryCodes(auth()->user());
    }
}; ?>

<div class="space-y-6 rounded-xl border border-stone-200 p-6 shadow-sm dark:border-stone-700" wire:cloak>
    <div class="space-y-2">
        <div class="flex items-center gap-2">
            <x-ui.icon name="lock" class="size-4"/>
            <h3 class="font-display text-lg font-semibold">{{ __('Two-factor authentication recovery codes') }}</h3>
        </div>
        <p class="text-sm leading-6 text-stone-600 dark:text-stone-400">
            {{ __('Existing codes cannot be shown again. Regenerate them to receive a new one-time set, which immediately replaces the old set.') }}
        </p>
    </div>

    <x-ui.button
        icon="arrow-path"
        variant="primary"
        wire:click="regenerateRecoveryCodes"
        wire:confirm="{{ __('Regenerate recovery codes? Your current codes will stop working immediately.') }}"
    >
        {{ __('Regenerate recovery codes') }}
    </x-ui.button>

    @error('recoveryCodes')
        <x-ui.callout variant="danger" :heading="$message" />
    @enderror

    @if (filled($recoveryCodes))
        <x-ui.recovery-codes :codes="$recoveryCodes" />
    @endif
</div>
