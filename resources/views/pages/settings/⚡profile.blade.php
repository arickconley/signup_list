<?php

use App\Concerns\ProfileValidationRules;
use App\Models\Account;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $timezone = '';

    public bool $completingProfile = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name ?? '';
        $this->email = Auth::user()->email;
        $this->phone = Auth::user()->phone ?? '';
        $this->timezone = Auth::user()->timezone ?? '';
        $this->completingProfile = ! Auth::user()->hasCompleteProfile();
    }

    public function setDetectedTimezone(string $timezone): void
    {
        if ($this->timezone === '' && in_array($timezone, timezone_identifiers_list(), true)) {
            $this->timezone = $timezone;
        }
    }

    /**
     * Update the profile information for the currently authenticated Account.
     */
    public function updateProfileInformation(): void
    {
        $account = Auth::user();

        $this->name = trim($this->name);
        $this->email = Account::normalizeEmail($this->email);
        $this->phone = trim($this->phone);
        $this->timezone = trim($this->timezone);

        $validated = $this->validate(ProfileValidationRules::profile($account->id));
        $validated['phone'] = $validated['phone'] === '' ? null : $validated['phone'];

        $account->fill($validated);

        if ($account->isDirty('email')) {
            $account->email_verified_at = null;
        }

        $account->save();

        session()->flash('success', __('Profile updated.'));

        if ($this->completingProfile && $account->hasCompleteProfile()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));
        }
    }

    /**
     * Send an email verification notification to the current Account.
     */
    public function resendVerificationNotification(): void
    {
        $account = Auth::user();

        if ($account->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $account->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteAccount(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <h2 class="sr-only">{{ __('Profile settings') }}</h2>

    <x-pages::settings.layout
        :heading="$completingProfile ? __('Complete your profile') : __('Profile')"
        :subheading="$completingProfile
            ? __('Add your name and timezone before continuing.')
            : __('Update the defaults used for future signups')"
    >
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            @if ($errors->any())
                <x-ui.callout variant="danger" :heading="__('Please correct the highlighted fields.')">
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-ui.callout>
            @endif

            <x-ui.input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <x-ui.input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <p class="mt-4 text-sm text-stone-600 dark:text-stone-400">
                            {{ __('Your email address is unverified.') }}

                            <button type="button" class="cursor-pointer font-semibold text-teal-700 underline underline-offset-4 dark:text-teal-400" wire:click="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </button>
                        </p>

                        @if (session('status') === 'verification-link-sent')
                            <p class="mt-2 text-sm font-semibold text-green-700 dark:text-green-400">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </p>
                        @endif
                    </div>
                @endif
            </div>

            <x-ui.input
                wire:model="phone"
                :label="__('Phone')"
                type="tel"
                inputmode="tel"
                autocomplete="tel"
                :description="__('Optional. Used as the default for future signups.')"
            />

            <div
                x-init="
                    if ($wire.timezone === '') {
                        const detectedTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
                        if (detectedTimezone) $wire.setDetectedTimezone(detectedTimezone);
                    }
                "
            >
                <x-ui.input
                    wire:model="timezone"
                    :label="__('Timezone')"
                    type="text"
                    list="timezone-options"
                    required
                    autocomplete="off"
                    :description="__('Used for dates and deadlines you create. Start typing to choose a timezone.')"
                />
                <datalist id="timezone-options">
                    @foreach (timezone_identifiers_list() as $timezoneOption)
                        <option value="{{ $timezoneOption }}"></option>
                    @endforeach
                </datalist>
            </div>

            <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                <x-ui.button variant="primary" type="submit" class="w-full sm:w-auto" data-test="update-profile-button">
                    {{ __('Save') }}
                </x-ui.button>
            </div>

            <x-ui.flash />
        </form>

        @if ($this->showDeleteAccount)
            <livewire:pages::settings.delete-account-form />
        @endif
    </x-pages::settings.layout>
</section>
