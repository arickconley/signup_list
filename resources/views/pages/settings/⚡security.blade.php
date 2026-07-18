<?php

use App\Actions\ChangeAccountPassword;
use App\Concerns\PasswordValidationRules;
use App\Enums\TwoFactorCredentialChange;
use App\Notifications\AccountTwoFactorAuthenticationChanged;
use App\Support\FreshAuthentication;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Title;
use Livewire\Component;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

new #[Title('Security settings')] class extends Component {
    use PasswordValidationRules;

    public string $password = '';
    public string $password_confirmation = '';

    #[Locked]
    public bool $hasPassword;

    public bool $canManageTwoFactor;

    public bool $twoFactorEnabled;

    public bool $requiresConfirmation;

    #[Locked]
    public bool $canManagePasskeys;

    #[Locked]
    public array $passkeys = [];

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $this->hasPassword = auth()->user()->password !== null;
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
    }

    /**
     * Add or replace the password for the currently authenticated Account.
     */
    public function setPassword(
        ChangeAccountPassword $changeAccountPassword,
        FreshAuthentication $freshAuthentication,
    ): void
    {
        $freshAuthentication->ensure();

        $validated = $this->validate([
            'password' => $this->passwordRules(),
        ]);
        $hadPassword = Auth::user()->password !== null;

        $changeAccountPassword->set(Auth::user(), $validated['password']);

        $this->reset('password', 'password_confirmation');
        $this->hasPassword = true;

        session()->flash('success', $hadPassword ? __('Password replaced.') : __('Password added.'));
    }

    /**
     * Remove the password from the currently authenticated Account.
     */
    public function removePassword(
        ChangeAccountPassword $changeAccountPassword,
        DisableTwoFactorAuthentication $disableTwoFactorAuthentication,
        FreshAuthentication $freshAuthentication,
    ): void
    {
        $freshAuthentication->ensure();

        $hadTwoFactorAuthentication = Auth::user()->hasEnabledTwoFactorAuthentication();
        $changeAccountPassword->remove(Auth::user());

        if ($hadTwoFactorAuthentication) {
            $disableTwoFactorAuthentication(Auth::user());
            Auth::user()->notify(new AccountTwoFactorAuthenticationChanged(TwoFactorCredentialChange::Disabled));
            $this->twoFactorEnabled = false;
        }

        $this->hasPassword = false;
        session()->flash('success', __('Password removed.'));
    }

    /**
     * Load the user's passkeys.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Show the delete confirmation modal.
     */
    public function confirmDelete(int $passkeyId): void
    {
        $passkey = auth()->user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->showDeleteModal = true;
    }

    /**
     * Delete the passkey.
     */
    public function deletePasskey(
        DeletePasskey $deletePasskey,
        FreshAuthentication $freshAuthentication,
    ): void
    {
        if (! $this->deletingPasskeyId) {
            return;
        }

        $freshAuthentication->ensure();

        $passkey = auth()->user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(auth()->user(), $passkey);

        $this->closeDeleteModal();
        $this->loadPasskeys();
    }

    /**
     * Close the delete confirmation modal.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }

    /**
     * Handle the two-factor authentication enabled event.
     */
    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(
        DisableTwoFactorAuthentication $disableTwoFactorAuthentication,
        FreshAuthentication $freshAuthentication,
    ): void {
        $freshAuthentication->ensure();
        $disableTwoFactorAuthentication(auth()->user());
        auth()->user()->notify(new AccountTwoFactorAuthenticationChanged(TwoFactorCredentialChange::Disabled));

        $this->twoFactorEnabled = false;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <h2 class="sr-only">{{ __('Security settings') }}</h2>

    <x-pages::settings.layout
        :heading="$hasPassword ? __('Replace password') : __('Add password')"
        :subheading="__('Use a long, unique password. Email sign-in remains available.')"
    >
        <form method="POST" wire:submit="setPassword" class="mt-6 space-y-6">
            <x-ui.input
                wire:model="password"
                :label="$hasPassword ? __('New password') : __('Password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />
            <x-ui.input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center gap-4">
                <x-ui.button variant="primary" type="submit" data-test="update-password-button">
                    {{ $hasPassword ? __('Replace password') : __('Add password') }}
                </x-ui.button>
            </div>
            <x-ui.flash />
        </form>

        @if ($hasPassword)
            <section class="mt-10 border-t border-stone-200 pt-8 dark:border-stone-800">
                <h3 class="font-display text-xl font-semibold">{{ __('Remove password') }}</h3>
                <p class="mt-1 text-sm leading-6 text-stone-600 dark:text-stone-400">
                    {{ __('You can continue signing in by email or passkey.') }}
                </p>
                <x-ui.button
                    variant="danger"
                    type="button"
                    class="mt-4"
                    wire:click="removePassword"
                    wire:confirm="{{ __('Remove your password? Email and passkey sign-in will remain available.') }}"
                >
                    {{ __('Remove password') }}
                </x-ui.button>
            </section>
        @endif

        @if ($canManageTwoFactor && $hasPassword)
            <section class="mt-12">
                <h3 class="font-display text-xl font-semibold">{{ __('Two-factor authentication') }}</h3>
                <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ __('Manage your two-factor authentication settings') }}</p>

                <div class="flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                    @if ($twoFactorEnabled)
                        <div class="space-y-4">
                            <p class="text-sm leading-6 text-stone-600 dark:text-stone-300">
                                {{ __('You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}
                            </p>

                            <div class="flex justify-start">
                                <x-ui.button
                                    variant="danger"
                                    wire:click="disable"
                                >
                                    {{ __('Disable 2FA') }}
                                </x-ui.button>
                            </div>

                            <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
                        </div>
                    @else
                        <div class="space-y-4">
                            <p class="text-sm leading-6 text-stone-600 dark:text-stone-400">
                                {{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.') }}
                            </p>

                            <x-ui.button
                                variant="primary"
                                wire:click="$dispatch('start-two-factor-setup')"
                                x-on:click="$dispatch('open-two-factor-setup')"
                            >
                                {{ __('Enable 2FA') }}
                            </x-ui.button>

                            <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                        </div>
                    @endif
                </div>
            </section>
        @endif

        @if ($canManagePasskeys)
            <section class="mt-12">
                <h3 class="font-display text-xl font-semibold">{{ __('Passkeys') }}</h3>
                <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ __('Manage your passkeys for passwordless sign-in') }}</p>

                <div class="mt-6 flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                    <div class="overflow-hidden rounded-xl border border-stone-200 dark:border-stone-700">
                        @forelse ($passkeys as $passkey)
                            <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-stone-200 dark:border-stone-700' : '' }}">
                                <div class="flex items-center gap-4">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-stone-100 dark:bg-stone-800">
                                        <x-ui.icon name="key" class="size-5 text-stone-500 dark:text-stone-400" />
                                    </div>
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2.5">
                                            <p class="font-medium tracking-tight">{{ $passkey['name'] }}</p>
                                            @if ($passkey['authenticator'])
                                                <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-600 dark:bg-stone-800 dark:text-stone-300">{{ $passkey['authenticator'] }}</span>
                                            @endif
                                        </div>
                                        <p class="text-xs text-stone-500 dark:text-stone-400">
                                            {{ __('Added :time', ['time' => $passkey['created_at_diff']]) }}
                                            @if ($passkey['last_used_at_diff'])
                                                <span class="opacity-50 mx-1">/</span>
                                                {{ __('Last used :time', ['time' => $passkey['last_used_at_diff']]) }}
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <x-ui.button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    wire:click="confirmDelete({{ $passkey['id'] }})"
                                    class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                    aria-label="{{ __('Remove passkey :name', ['name' => $passkey['name']]) }}"
                                />
                            </div>
                        @empty
                            <div class="p-8 text-center">
                                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-stone-100 dark:bg-stone-800">
                                    <x-ui.icon name="key" class="size-7 text-stone-400 dark:text-stone-500" />
                                </div>
                                <p class="font-medium">{{ __('No passkeys yet') }}</p>
                                <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ __('Add a passkey to sign in without a password') }}</p>
                            </div>
                        @endforelse
                    </div>

                    <x-passkey-registration />
                </div>
            </section>
        @endif
    </x-pages::settings.layout>

    @if ($showDeleteModal)
        <dialog
            x-data
            x-init="$el.showModal()"
            x-on:cancel.prevent="$wire.closeDeleteModal()"
            x-on:click.self="$wire.closeDeleteModal()"
            class="m-auto max-h-[calc(100vh-2rem)] w-[calc(100%-2rem)] max-w-md overflow-visible rounded-2xl bg-transparent p-0 text-stone-950 backdrop:bg-stone-950/50 backdrop:backdrop-blur-sm dark:text-stone-50"
            aria-labelledby="remove-passkey-title"
            wire:cloak
        >
            <div class="w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 shadow-2xl dark:border-stone-700 dark:bg-stone-900">
                <div class="space-y-6">
                    <div class="space-y-2">
                        <h2 id="remove-passkey-title" class="font-display text-2xl font-semibold">{{ __('Remove passkey') }}</h2>
                        <p class="text-sm leading-6 text-stone-600 dark:text-stone-300">
                            {{ __('Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.', ['name' => $deletingPasskeyName]) }}
                        </p>
                    </div>

                    <div class="flex justify-end gap-3">
                        <x-ui.button variant="outline" wire:click="closeDeleteModal">
                            {{ __('Cancel') }}
                        </x-ui.button>
                        <x-ui.button variant="danger" wire:click="deletePasskey">
                            {{ __('Remove passkey') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </dialog>
    @endif
</section>
