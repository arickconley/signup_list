<?php

use App\Actions\CancelParticipantSignup;
use App\Actions\UpdateParticipantSignup;
use App\Data\UpdateParticipantSignupInput;
use App\Exceptions\CannotCancelParticipantSignup;
use App\Exceptions\CannotUpdateParticipantSignup;
use App\Models\Account;
use App\Models\Option;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app', ['robots' => 'noindex, nofollow'])] #[Title('Edit Signup')] class extends Component
{
    public Signup $signup;

    #[Locked]
    public string $email = '';

    public string $name = '';

    public string $phone = '';

    public bool $nameConsent = false;

    public bool $emailConsent = false;

    public bool $phoneConsent = false;

    /** @var array<int, string> */
    public array $selectedOptions = [];

    /** @var array<int, string> */
    #[Locked]
    public array $currentOptionPublicIds = [];

    public string $announcement = '';

    public bool $saved = false;

    /** @var array<int, string> */
    public array $unavailableOptionNames = [];

    public function mount(Signup $signup): void
    {
        $this->signup = $signup;
        $account = $this->authorizeParticipant();

        $this->email = $signup->email_snapshot ?? '';
        $this->name = $signup->name_snapshot;
        $this->phone = $signup->phone_snapshot ?? '';
        $this->nameConsent = $signup->name_consent;
        $this->emailConsent = $signup->email_consent;
        $this->phoneConsent = $signup->phone_consent;
        $this->selectedOptions = $signup->optionClaims()
            ->with('option')
            ->get()
            ->sortBy(fn ($claim): int => $claim->option->position)
            ->map(fn ($claim): string => $claim->option->public_id)
            ->values()
            ->all();
        $this->currentOptionPublicIds = $this->selectedOptions;
    }

    public function hydrate(): void
    {
        $this->authorizeParticipant();
    }

    public function save(UpdateParticipantSignup $updateParticipantSignup): void
    {
        $account = $this->authorizeParticipant();
        $this->name = trim($this->name);
        $this->phone = trim($this->phone);
        $this->saved = false;
        $this->announcement = '';
        $this->unavailableOptionNames = [];
        $this->resetErrorBag();

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'nameConsent' => ['boolean'],
            'emailConsent' => ['boolean'],
            'phoneConsent' => ['boolean'],
            'selectedOptions' => ['required', 'array', 'min:1'],
            'selectedOptions.*' => ['required', 'uuid', 'distinct'],
        ], [
            'selectedOptions.required' => __('Choose at least one Option or cancel the Signup.'),
            'selectedOptions.min' => __('Choose at least one Option or cancel the Signup.'),
        ]);

        try {
            $updateParticipantSignup->handle($account, new UpdateParticipantSignupInput(
                signupId: $this->signup->id,
                name: $this->name,
                phone: $this->phone === '' ? null : $this->phone,
                optionPublicIds: $this->selectedOptions,
                nameConsent: $this->nameConsent,
                emailConsent: $this->emailConsent,
                phoneConsent: $this->phoneConsent,
            ));
        } catch (CannotUpdateParticipantSignup $exception) {
            $this->unavailableOptionNames = $exception->unavailableOptionNames;
            $this->selectedOptions = array_values(array_diff(
                $this->selectedOptions,
                $exception->unavailableOptionPublicIds,
            ));
            $this->addError('signup', $exception->getMessage());
            $this->announcement = $exception->getMessage();

            return;
        }

        $this->signup->refresh();
        $this->signup->load('sheet');
        $this->currentOptionPublicIds = $this->signup->optionClaims()
            ->with('option')
            ->get()
            ->sortBy(fn ($claim): int => $claim->option->position)
            ->map(fn ($claim): string => $claim->option->public_id)
            ->values()
            ->all();
        $this->selectedOptions = $this->currentOptionPublicIds;
        $this->saved = true;
        $this->announcement = __('Signup saved.');
    }

    public function cancel(CancelParticipantSignup $cancelParticipantSignup): void
    {
        $account = $this->authorizeParticipant();

        try {
            $cancelParticipantSignup->handle($account, $this->signup->id);
        } catch (CannotCancelParticipantSignup $exception) {
            $this->addError('signup', $exception->getMessage());
            $this->announcement = $exception->getMessage();

            return;
        }

        session()->flash('success', __('Signup cancelled. Its Option Claims are available again.'));

        $this->redirectRoute('dashboard', navigate: true);
    }

    /** @return Collection<int, Option> */
    #[Computed]
    public function options(): Collection
    {
        return $this->signup->sheet->options()
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    private function authorizeParticipant(): Account
    {
        $account = Auth::user();

        abort_unless($account instanceof Account, 404);

        $this->signup->refresh();
        $this->signup->load('sheet');

        abort_unless($this->signup->canBeEditedBy($account), 404);

        return $account;
    }
};

?>

<div class="mx-auto max-w-3xl">
        <header class="border-b border-stone-200 pb-6 dark:border-stone-800">
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-700 dark:text-amber-400">{{ __('Your Signup') }}</p>
            <h1 class="mt-2 font-display text-4xl font-semibold tracking-tight">{{ __('Edit Signup') }}</h1>
            <p class="mt-3 text-stone-600 dark:text-stone-400">{{ $signup->sheet->title }}</p>
        </header>

        <p class="sr-only" role="status" aria-live="polite">{{ $announcement }}</p>

        @if ($errors->any())
            <x-ui.callout class="mt-6" variant="danger" :heading="__('Please correct the highlighted fields.')">
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

        @if ($saved)
            <x-ui.callout class="mt-6" :heading="__('Signup saved.')">
                <p class="mt-1">{{ __('Your participant details and Option Claims are up to date.') }}</p>
            </x-ui.callout>
        @endif

        <form wire:submit="save" class="paper-grid mt-8 grid gap-8 rounded-2xl border border-stone-200 bg-stone-50 p-6 shadow-sm sm:p-8 dark:border-stone-800 dark:bg-stone-900/60" novalidate>
            <section class="grid gap-5" aria-labelledby="participant-details-title">
                <div>
                    <p class="font-mono text-xs font-bold uppercase tracking-[0.16em] text-teal-700 dark:text-teal-400">{{ __('Participant record') }}</p>
                    <h2 id="participant-details-title" class="mt-2 font-display text-2xl font-semibold">{{ __('Your details') }}</h2>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input wire:model="name" name="name" :label="__('Name')" type="text" autocomplete="name" required />
                    <x-ui.input wire:model="phone" name="phone" :label="__('Phone')" type="tel" autocomplete="tel" :description="__('Optional.')" />
                    <div class="sm:col-span-2">
                        <x-ui.input
                            id="participant-email"
                            wire:model="email"
                            name="email"
                            :label="__('Signup email')"
                            type="email"
                            readonly
                            aria-readonly="true"
                            :description="__('Email submitted with this Signup cannot be changed here.')"
                        />
                    </div>
                </div>
            </section>

            @if (
                $signup->sheet->name_visibility === Sheet::VISIBILITY_PARTICIPANTS
                || $signup->sheet->email_visibility === Sheet::VISIBILITY_PARTICIPANTS
                || $signup->sheet->phone_visibility === Sheet::VISIBILITY_PARTICIPANTS
            )
                <fieldset class="grid gap-4">
                    <legend class="font-display text-2xl font-semibold">{{ __('Visibility Consent') }}</legend>
                    <p class="text-sm leading-6 text-stone-600 dark:text-stone-400">{{ __('The Owner always sees submitted details. Public display also depends on the Signup Sheet settings.') }}</p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        @if ($signup->sheet->name_visibility === Sheet::VISIBILITY_PARTICIPANTS)
                            <x-ui.checkbox wire:model="nameConsent" id="name-consent" name="nameConsent" :label="__('Share full name')" variant="card" />
                        @endif
                        @if ($signup->sheet->email_visibility === Sheet::VISIBILITY_PARTICIPANTS)
                            <x-ui.checkbox wire:model="emailConsent" id="email-consent" name="emailConsent" :label="__('Share email')" variant="card" />
                        @endif
                        @if ($signup->sheet->phone_visibility === Sheet::VISIBILITY_PARTICIPANTS)
                            <x-ui.checkbox wire:model="phoneConsent" id="phone-consent" name="phoneConsent" :label="__('Share phone')" variant="card" />
                        @endif
                    </div>
                </fieldset>
            @endif

            <fieldset class="grid gap-4" @if ($errors->has('selectedOptions') || $errors->has('signup')) aria-invalid="true" aria-describedby="participant-options-error" @endif>
                <legend class="font-display text-2xl font-semibold">{{ __('Option Claims') }}</legend>
                @php
                    $currentClaimCount = count($currentOptionPublicIds);
                    $currentIsOverLimit = $currentClaimCount > $signup->sheet->selection_maximum;
                @endphp
                @if ($currentIsOverLimit)
                    <div class="border-s-4 border-amber-500 bg-amber-50 px-4 py-3 text-amber-950 dark:bg-amber-950/40 dark:text-amber-100" role="status">
                        <p class="font-semibold">{{ __('Signup over current limit') }}</p>
                        <p class="mt-1 text-sm">{{ __('Remove existing claims before adding another Option.') }}</p>
                    </div>
                @endif
                <p class="text-sm leading-6 text-stone-600 dark:text-stone-400">
                    {{ trans_choice('Choose up to :count Option.|Choose up to :count Options.', $signup->sheet->selection_maximum, ['count' => $signup->sheet->selection_maximum]) }}
                </p>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($this->options as $option)
                        @php
                            $isCurrent = in_array($option->public_id, $currentOptionPublicIds, true);
                            $isOverCapacity = $option->claimed_count > $option->capacity;
                            $isFull = $option->claimed_count === $option->capacity;
                            $isUnavailable = ! $isCurrent && ($currentIsOverLimit || $isFull || $isOverCapacity);
                            $remaining = max($option->capacity - $option->claimed_count, 0);
                            $status = match (true) {
                                $isCurrent && $isOverCapacity => __('Currently claimed — over capacity'),
                                $isCurrent && $isFull => __('Currently claimed — full'),
                                $isCurrent => __('Currently claimed'),
                                $currentIsOverLimit => __('Remove existing claims before adding another Option.'),
                                $isUnavailable => __('Unavailable'),
                                default => trans_choice(':count place remaining|:count places remaining', $remaining, ['count' => $remaining]),
                            };
                        @endphp
                        <div wire:key="participant-option-{{ $option->public_id }}" @class([
                            'border-s-4 ps-3',
                            'border-amber-500' => $isCurrent,
                            'border-stone-300 dark:border-stone-700' => ! $isCurrent,
                        ])>
                            <x-ui.checkbox
                                wire:model="selectedOptions"
                                :id="'participant-option-'.$option->public_id"
                                name="selectedOptions[]"
                                :value="$option->public_id"
                                :label="$option->name"
                                :disabled="$isUnavailable"
                                variant="card"
                            />
                            <p class="mt-1 ps-4 text-xs font-semibold uppercase tracking-[0.1em] text-stone-500 dark:text-stone-400">{{ $status }}</p>
                        </div>
                    @endforeach
                </div>
                @if ($errors->has('selectedOptions') || $errors->has('signup'))
                    <p id="participant-options-error" class="text-sm font-medium text-red-700 dark:text-red-400">{{ $errors->first('selectedOptions') ?: $errors->first('signup') }}</p>
                @endif
            </fieldset>

            <div>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ __('Save Signup') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </x-ui.button>
            </div>
        </form>

        <section class="mt-8 border-s-4 border-red-600 bg-red-50 px-5 py-5 dark:border-red-500 dark:bg-red-950/30" aria-labelledby="cancel-signup-title">
            <h2 id="cancel-signup-title" class="font-display text-2xl font-semibold text-red-950 dark:text-red-100">{{ __('Cancel Signup') }}</h2>
            <p id="cancel-signup-description" class="mt-2 text-sm leading-6 text-red-900 dark:text-red-200">
                {{ __('This removes every Option Claim and releases their capacity. This cannot be undone.') }}
            </p>
            <x-ui.button
                class="mt-4"
                variant="danger"
                wire:click="cancel"
                wire:confirm="{{ __('Cancel this Signup and release every Option Claim? This cannot be undone.') }}"
                wire:loading.attr="disabled"
                wire:target="cancel"
                aria-describedby="cancel-signup-description"
            >
                <span wire:loading.remove wire:target="cancel">{{ __('Cancel Signup') }}</span>
                <span wire:loading wire:target="cancel">{{ __('Cancelling…') }}</span>
            </x-ui.button>
        </section>

        <div class="mt-6">
            <x-ui.link :href="route('dashboard')" wire:navigate action>{{ __('Back to Dashboard') }}</x-ui.link>
        </div>
</div>
