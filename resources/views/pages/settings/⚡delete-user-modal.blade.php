<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<dialog
    x-data
    x-ref="dialog"
    x-init="if (@js($errors->isNotEmpty())) $nextTick(() => $refs.dialog.showModal())"
    x-on:open-delete-account.window="$refs.dialog.showModal(); $nextTick(() => $refs.password?.focus())"
    x-on:click.self="$refs.dialog.close()"
    class="m-auto max-h-[calc(100vh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-visible rounded-2xl bg-transparent p-0 text-stone-950 backdrop:bg-stone-950/50 backdrop:backdrop-blur-sm dark:text-stone-50"
    aria-labelledby="delete-account-title"
>
    <div class="w-full rounded-2xl border border-stone-200 bg-white p-6 shadow-2xl dark:border-stone-700 dark:bg-stone-900">
        <form wire:submit="deleteUser" class="space-y-6">
            <div>
                <h2 id="delete-account-title" class="font-display text-2xl font-semibold">{{ __('Are you sure you want to delete your account?') }}</h2>
                <p class="mt-2 text-sm leading-6 text-stone-600 dark:text-stone-400">{{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}</p>
            </div>

            <x-ui.input wire:model="password" x-ref="password" :label="__('Password')" type="password" viewable />

            <div class="flex justify-end gap-2">
                <x-ui.button variant="filled" x-on:click="$refs.dialog.close()">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="danger" type="submit" data-test="confirm-delete-user-button">{{ __('Delete account') }}</x-ui.button>
            </div>
        </form>
    </div>
</dialog>
