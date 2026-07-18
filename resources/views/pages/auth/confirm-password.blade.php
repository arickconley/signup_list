<x-layouts::auth :title="__('Confirm your account')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Confirm your account')"
            :description="__('This is a secure area. Confirm it’s you before continuing.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        @error('access')
            <p class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200" role="alert">
                {{ $message }}
            </p>
        @enderror

        <x-passkey-verify
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm"
            :label="__('Confirm with passkey')"
            :loading-label="__('Confirming...')"
            :separator="__('Or confirm by email')"
        />

        @if (session()->has('account_access_challenge'))
            <form method="POST" action="{{ route('account-access.code') }}" class="flex flex-col gap-4">
                @csrf

                <x-ui.otp name="code" :label="__('Confirmation code')" required autofocus />

                <x-ui.button variant="primary" type="submit" class="w-full">
                    {{ __('Confirm with code') }}
                </x-ui.button>
            </form>
        @endif

        <form method="POST" action="{{ route('account-access.request') }}" class="flex flex-col gap-4">
            @csrf

            <x-ui.button variant="primary" type="submit" class="w-full">
                {{ __('Email me a confirmation code') }}
            </x-ui.button>
        </form>

        @if (auth()->user()->password !== null)
            <div class="flex items-center gap-3 text-xs uppercase tracking-wide text-stone-500" aria-hidden="true">
                <span class="h-px flex-1 bg-stone-200 dark:bg-stone-700"></span>
                {{ __('Or confirm with password') }}
                <span class="h-px flex-1 bg-stone-200 dark:bg-stone-700"></span>
            </div>

            <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
                @csrf

                <x-ui.input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                <x-ui.button variant="primary" type="submit" class="w-full" data-test="confirm-password-button">
                    {{ __('Confirm') }}
                </x-ui.button>
            </form>
        @endif
    </div>
</x-layouts::auth>
