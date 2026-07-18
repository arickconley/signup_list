<section class="border-t-2 border-stone-800 px-5 py-8 sm:px-9 sm:py-11 dark:border-stone-200" aria-labelledby="signup-title">
    <div class="max-w-3xl">
        <p class="font-mono text-xs font-bold uppercase tracking-[0.16em] text-teal-800 dark:text-teal-300">{{ __('Add your name') }}</p>
        <h2 id="signup-title" class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('Sign up') }}</h2>
        @if ($acceptingSignups && $hasAvailableOptions && ! $completed)
            <p class="mt-3 text-sm leading-6 text-stone-600 dark:text-stone-400">
                {{ trans_choice('Choose up to :count Option for this Signup. Without an account, this limit applies only to this Signup; submitting again can bypass this limit.|Choose up to :count Options for this Signup. Without an account, this limit applies only to this Signup; submitting again can bypass this limit.', $selectionMaximum, ['count' => $selectionMaximum]) }}
            </p>
        @endif
    </div>

    <p class="sr-only" role="status" aria-live="polite">{{ $announcement }}</p>

    @if (! $acceptingSignups)
        <x-ui.callout class="mt-7 max-w-3xl" variant="danger" :heading="__('Signups are closed')">
            <p class="mt-1">{{ $errors->first('signup') ?: __('This Signup Sheet is no longer open for signups.') }}</p>
        </x-ui.callout>
    @elseif ($completed)
        @if ($checkEmail)
            <x-ui.callout class="mt-7 max-w-3xl" :heading="__('Check your email')">
                <p class="mt-1">{{ __('If the address can receive email, confirmation and an access link are on the way.') }}</p>
            </x-ui.callout>
        @else
            <x-ui.callout class="mt-7 max-w-3xl" :heading="__('Signup complete')">
                <p class="mt-1">{{ __('Your Option claims are confirmed. This Signup cannot be edited or cancelled without an account.') }}</p>
            </x-ui.callout>
        @endif
    @elseif (! $hasAvailableOptions)
        <x-ui.callout class="mt-7 max-w-3xl" :heading="__('No Options available')">
            <p class="mt-1">{{ __('All Options are currently unavailable.') }}</p>
        </x-ui.callout>
    @else
    <form wire:submit="complete" class="mt-7 grid max-w-3xl gap-6" novalidate>
        <div class="absolute -start-[10000px] top-auto size-px overflow-hidden" aria-hidden="true">
            <label for="signup-website">{{ __('Leave this field blank') }}</label>
            <input id="signup-website" wire:model="website" type="text" tabindex="-1" autocomplete="off">
        </div>

        @if ($errors->any())
            <x-ui.callout variant="danger" :heading="__('Please correct the highlighted fields.')">
                <ul class="mt-2 list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                @if ($unavailableOptionNames !== [])
                    <p class="mt-2 font-semibold">{{ __('Newly unavailable: :options', ['options' => implode(', ', $unavailableOptionNames)]) }}</p>
                @endif
            </x-ui.callout>
        @endif

        <div class="grid gap-6 sm:grid-cols-2">
            <x-ui.input wire:model="name" name="name" :label="__('Your name')" type="text" autocomplete="name" required />
            <x-ui.input wire:model="email" name="email" :label="__('Email')" type="email" autocomplete="email" :description="__('Optional. Adds passwordless access after verification.')" />
            <x-ui.input wire:model="phone" name="phone" :label="__('Phone')" type="tel" autocomplete="tel" :description="__('Optional.')" />
        </div>

        <fieldset class="grid gap-3" @if ($errors->has('selectedOptions') || $errors->has('signup')) aria-invalid="true" aria-describedby="signup-options-error" @endif>
            <legend class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ __('Available Options') }}</legend>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($availableOptions as $option)
                    <x-ui.checkbox
                        wire:model="selectedOptions"
                        :id="'signup-option-'.$option->public_id"
                        name="selectedOptions[]"
                        :value="$option->public_id"
                        :label="$option->name"
                        variant="card"
                    />
                @endforeach
            </div>
            @if ($errors->has('selectedOptions') || $errors->has('signup'))
                <p id="signup-options-error" class="text-sm font-medium text-red-700 dark:text-red-400">{{ $errors->first('selectedOptions') ?: $errors->first('signup') }}</p>
            @endif
        </fieldset>

        <div>
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="complete">
                <span wire:loading.remove wire:target="complete">{{ __('Complete Signup') }}</span>
                <span wire:loading wire:target="complete">{{ __('Saving…') }}</span>
            </x-ui.button>
        </div>
    </form>
    @endif
</section>
