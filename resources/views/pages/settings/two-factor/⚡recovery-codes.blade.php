<?php

use App\Enums\TwoFactorCredentialChange;
use App\Notifications\AccountTwoFactorAuthenticationChanged;
use App\Support\FreshAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public array $recoveryCodes = [];

    /**
     * Generate new recovery codes for the user.
     */
    public function regenerateRecoveryCodes(
        GenerateNewRecoveryCodes $generateNewRecoveryCodes,
        FreshAuthentication $freshAuthentication,
    ): void {
        $freshAuthentication->ensure();
        $generateNewRecoveryCodes(auth()->user());
        auth()->user()->notify(new AccountTwoFactorAuthenticationChanged(
            TwoFactorCredentialChange::RecoveryCodesRegenerated,
        ));

        $this->loadRecoveryCodes();
    }

    /**
     * Load the recovery codes for the user.
     */
    private function loadRecoveryCodes(): void
    {
        $user = auth()->user();

        if ($user->hasEnabledTwoFactorAuthentication() && $user->two_factor_recovery_codes) {
            try {
                $this->recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
            } catch (Exception) {
                $this->addError('recoveryCodes', 'Failed to load recovery codes');

                $this->recoveryCodes = [];
            }
        }
    }
}; ?>

<div class="space-y-6 rounded-xl border border-stone-200 p-6 shadow-sm dark:border-stone-700" wire:cloak>
    <div class="space-y-2">
        <div class="flex items-center gap-2">
            <x-ui.icon name="lock" class="size-4"/>
            <h3 class="font-display text-lg font-semibold">{{ __('2FA recovery codes') }}</h3>
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
        <div class="space-y-3">
            <x-ui.callout
                variant="warning"
                :heading="__('Save these recovery codes now')"
            >
                {{ __('They will not be shown again after you leave this page. Each code can restore access once.') }}
            </x-ui.callout>

            <div
                class="grid gap-1 rounded-lg bg-stone-100 p-4 font-mono text-sm dark:bg-white/5"
                role="list"
                aria-label="{{ __('Recovery codes') }}"
            >
                @foreach($recoveryCodes as $code)
                    <div
                        role="listitem"
                        class="select-text"
                        wire:loading.class="opacity-50 animate-pulse"
                    >
                        {{ $code }}
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
