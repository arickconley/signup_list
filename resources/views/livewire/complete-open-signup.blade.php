<div x-on:claim-option.window="$wire.beginClaim($event.detail.optionPublicId)">
    <p class="sr-only" role="status" aria-live="polite">{{ $announcement }}</p>

    @if ($completed)
        <div class="border-t-2 border-stone-800 px-5 py-8 sm:px-9 dark:border-stone-200">
            @if ($checkEmail)
                <x-ui.callout :heading="__('Check your email')">
                    <p class="mt-1">{{ __('If the address can receive email, confirmation and an access link are on the way.') }}</p>
                </x-ui.callout>
            @else
                <x-ui.callout :heading="__('Signup complete')">
                    <p class="mt-1">{{ __('Your Option claims are confirmed. This Signup cannot be edited or cancelled without an account.') }}</p>
                </x-ui.callout>
            @endif
        </div>
    @elseif ($errors->has('signup') && ! $showNameModal)
        <div class="border-t-2 border-stone-800 px-5 py-8 sm:px-9 dark:border-stone-200">
            <x-ui.callout variant="danger" :heading="__('This Option could not be claimed')">
                {{ $errors->first('signup') }}
                @if ($unavailableOptionNames !== [])
                    <p class="mt-2 font-semibold">{{ __('Newly unavailable: :options', ['options' => implode(', ', $unavailableOptionNames)]) }}</p>
                @endif
            </x-ui.callout>
        </div>
    @endif

    @if ($showNameModal)
        <dialog
            wire:key="claim-option-modal-{{ $pendingOptionPublicId }}"
            x-data
            x-ref="dialog"
            x-init="$nextTick(() => { if (! $refs.dialog.open) $refs.dialog.showModal(); $refs.name?.focus(); })"
            x-on:cancel.prevent="$wire.cancelClaim(); $refs.dialog.close()"
            x-on:click.self="$wire.cancelClaim(); $refs.dialog.close()"
            class="m-auto max-h-[calc(100vh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-visible bg-transparent p-0 text-stone-950 backdrop:bg-stone-950/60 backdrop:backdrop-blur-sm dark:text-stone-50"
            aria-labelledby="claim-option-title"
            aria-describedby="claim-option-description"
        >
            <div class="relative max-h-[calc(100vh-2rem)] overflow-y-auto border-2 border-stone-900 bg-amber-50 p-6 shadow-[12px_12px_0_rgba(28,25,23,0.3)] sm:p-8 dark:border-stone-100 dark:bg-stone-900 dark:shadow-[12px_12px_0_rgba(214,211,209,0.16)]">
                <span class="absolute -top-3 start-1/2 h-6 w-24 -translate-x-1/2 -rotate-2 bg-amber-200/95 shadow-sm dark:bg-amber-700/90" aria-hidden="true"></span>
                <form wire:submit="claimPending" class="grid gap-6" novalidate>
                    <div>
                        <p class="font-mono text-xs font-bold uppercase tracking-[0.16em] text-teal-800 dark:text-teal-300">{{ __('Participant') }}</p>
                        <h2 id="claim-option-title" class="mt-2 font-display text-3xl font-semibold tracking-tight">
                            {{ __('Claim :option', ['option' => $pendingOptionName]) }}
                        </h2>
                        <p id="claim-option-description" class="mt-2 text-sm leading-6 text-stone-600 dark:text-stone-400">
                            {{ __('Enter your name to confirm this Option. It stays in this browser for your next claim.') }}
                        </p>
                    </div>

                    <div class="absolute -start-[10000px] top-auto size-px overflow-hidden" aria-hidden="true">
                        <label for="signup-website">{{ __('Leave this field blank') }}</label>
                        <input id="signup-website" wire:model="website" type="text" tabindex="-1" autocomplete="off">
                    </div>

                    @if ($errors->any())
                        <x-ui.callout variant="danger" :heading="__('This Option could not be claimed')">
                            {{ $errors->first('name') ?: $errors->first('signup') }}
                        </x-ui.callout>
                    @endif

                    <div
                        data-remember-participant-name
                        x-data
                        x-init="
                            const rememberedName = localStorage.getItem('signup.participant-name');
                            if (rememberedName && ! $wire.name) $wire.name = rememberedName;
                        "
                        x-on:input.debounce.250ms="
                            const participantName = $event.target.value.trim();
                            if (participantName) localStorage.setItem('signup.participant-name', participantName);
                            else localStorage.removeItem('signup.participant-name');
                        "
                    >
                        <x-ui.input wire:model="name" x-ref="name" name="name" :label="__('Your name')" type="text" autocomplete="name" required />
                    </div>

                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <x-ui.button
                            variant="outline"
                            x-on:click="$refs.dialog.close()"
                            wire:click="cancelClaim"
                        >
                            {{ __('Cancel') }}
                        </x-ui.button>
                        <x-ui.button variant="ledger" type="submit" class="min-w-36 justify-between" wire:loading.attr="disabled" wire:target="claimPending">
                            <span wire:loading.remove wire:target="claimPending">{{ __('Claim') }}</span>
                            <span wire:loading wire:target="claimPending">{{ __('Claiming…') }}</span>
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif
</div>
