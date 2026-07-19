<section class="mt-8 border-t-2 border-stone-800 pt-8 dark:border-stone-200" aria-labelledby="verified-signup-title">
    <p class="font-mono text-xs font-bold uppercase tracking-[0.16em] text-teal-800 dark:text-teal-300">{{ __('Verified Account') }}</p>
    <h2 id="verified-signup-title" class="mt-2 font-display text-3xl font-semibold tracking-tight">{{ __('Choose your Options') }}</h2>

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
    @else
    <form wire:submit="complete" class="mt-7 grid gap-6" novalidate>
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
            <x-ui.input wire:model="email" name="email" :label="__('Account email')" type="email" autocomplete="email" readonly required />
            <x-ui.input wire:model="phone" name="phone" :label="__('Phone')" type="tel" autocomplete="tel" :description="__('Optional.')" />
        </div>

        <fieldset class="grid gap-3" @if ($errors->has('selectedOptions') || $errors->has('signup')) aria-invalid="true" aria-describedby="verified-signup-options-error" @endif>
            <legend class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ trans_choice('Choose up to :count Option|Choose up to :count Options', $selectionMaximum, ['count' => $selectionMaximum]) }}</legend>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($availableOptions as $option)
                    <x-ui.checkbox
                        wire:model="selectedOptions"
                        :id="'verified-signup-option-'.$option->public_id"
                        name="selectedOptions[]"
                        :value="$option->public_id"
                        :label="$option->name"
                        variant="card"
                    />
                @endforeach
            </div>
            @if ($errors->has('selectedOptions') || $errors->has('signup'))
                <p id="verified-signup-options-error" class="text-sm font-medium text-red-700 dark:text-red-400">{{ $errors->first('selectedOptions') ?: $errors->first('signup') }}</p>
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
