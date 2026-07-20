<section class="mt-8 border-t-2 border-stone-800 pt-8 dark:border-stone-200" aria-labelledby="verified-signup-title">
    <p class="font-mono text-xs font-bold uppercase tracking-[0.16em] text-teal-800 dark:text-teal-300">{{ __('Verified Account') }}</p>
    <h2 id="verified-signup-title" class="mt-2 font-display text-3xl font-semibold tracking-tight">{{ __('Claim an Option') }}</h2>
    <p class="mt-3 text-sm leading-6 text-stone-600 dark:text-stone-400">{{ __('Claim an available Option with your Account name.') }}</p>

    <p class="sr-only" role="status" aria-live="polite">{{ $announcement }}</p>

    @if ($existingSignup)
        <x-ui.callout class="mt-7" :heading="__('Your Signup is ready')">
            <p class="mt-1">{{ __('You already have one Signup for this Signup Sheet.') }}</p>
            <ul class="mt-3 list-inside list-disc space-y-1">
                @foreach ($existingOptionNames as $optionName)
                    <li>{{ $optionName }}</li>
                @endforeach
            </ul>
        </x-ui.callout>
    @elseif ($completed)
        <x-ui.callout class="mt-7" :heading="__('Signup complete')">
            <p class="mt-1">{{ __('Your Option claims are confirmed for this Account.') }}</p>
        </x-ui.callout>
    @endif

    @if (! $completed && $availableOptions->isNotEmpty())
    <form class="mt-7 grid gap-6" x-on:submit.prevent novalidate>
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
            <x-ui.input wire:model="name" name="name" :label="__('Your name')" type="text" autocomplete="name" readonly required />
            <x-ui.input wire:model="email" name="email" :label="__('Account email')" type="email" autocomplete="email" readonly required />
            <x-ui.input wire:model="phone" name="phone" :label="__('Phone')" type="tel" autocomplete="tel" :description="__('Optional.')" />
        </div>

        @if ($showsNameConsent || $showsEmailConsent || $showsPhoneConsent)
            <fieldset class="grid gap-4">
                <legend class="font-display text-2xl font-semibold">{{ __('Visibility Consent') }}</legend>
                <p class="text-sm leading-6 text-stone-600 dark:text-stone-400">{{ __('The Owner always sees submitted details. Public display also depends on the Signup Sheet settings.') }}</p>
                <div class="grid gap-3 sm:grid-cols-3">
                    @if ($showsNameConsent)
                        <x-ui.checkbox wire:model="nameConsent" id="verified-name-consent" name="nameConsent" :label="__('Share full name')" variant="card" />
                    @endif
                    @if ($showsEmailConsent)
                        <x-ui.checkbox wire:model="emailConsent" id="verified-email-consent" name="emailConsent" :label="__('Share email')" variant="card" />
                    @endif
                    @if ($showsPhoneConsent)
                        <x-ui.checkbox wire:model="phoneConsent" id="verified-phone-consent" name="phoneConsent" :label="__('Share phone')" variant="card" />
                    @endif
                </div>
            </fieldset>
        @endif

        <fieldset class="grid gap-3" @if ($errors->has('selectedOptions') || $errors->has('signup')) aria-invalid="true" aria-describedby="verified-signup-options-error" @endif>
            <legend class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ __('Available Options') }}</legend>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($availableOptions as $option)
                    <article class="flex min-h-20 items-center justify-between gap-4 border border-stone-300 bg-white/60 px-4 py-3 dark:border-stone-700 dark:bg-stone-950/30">
                        <h3 class="font-semibold text-stone-900 dark:text-stone-100">{{ $option->name }}</h3>
                        <x-ui.button
                            wire:click="claim('{{ $option->public_id }}')"
                            wire:loading.attr="disabled"
                            wire:target="claim('{{ $option->public_id }}')"
                            :aria-label="__('Claim :option', ['option' => $option->name])"
                        >
                            <span wire:loading.remove wire:target="claim('{{ $option->public_id }}')">{{ __('Claim') }}</span>
                            <span wire:loading wire:target="claim('{{ $option->public_id }}')">{{ __('Claiming…') }}</span>
                        </x-ui.button>
                    </article>
                @endforeach
            </div>
            @if ($errors->has('selectedOptions') || $errors->has('signup'))
                <p id="verified-signup-options-error" class="text-sm font-medium text-red-700 dark:text-red-400">{{ $errors->first('selectedOptions') ?: $errors->first('signup') }}</p>
            @endif
        </fieldset>

    </form>
    @endif
</section>
