<x-layouts::auth :title="__('Access your account')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Access your account')"
            :description="__('Enter your email. We’ll send a one-time code and secure sign-in link.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        @error('access')
            <p class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200" role="alert">
                {{ $message }}
            </p>
        @enderror

        @if (session()->has('account_access_challenge'))
            <form method="POST" action="{{ route('account-access.code') }}" class="flex flex-col gap-4">
                @csrf

                <x-ui.otp
                    name="code"
                    :label="__('Sign-in code')"
                    required
                    autofocus
                />

                <x-ui.button variant="primary" type="submit" class="w-full">
                    {{ __('Verify code') }}
                </x-ui.button>
            </form>

            <div class="flex items-center gap-3 text-xs uppercase tracking-wide text-stone-500" aria-hidden="true">
                <span class="h-px flex-1 bg-stone-200 dark:bg-stone-700"></span>
                {{ __('or use another sign-in method') }}
                <span class="h-px flex-1 bg-stone-200 dark:bg-stone-700"></span>
            </div>
        @endif

        <x-passkey-verify />

        <form method="POST" action="{{ route('account-access.request') }}" class="flex flex-col gap-6">
            @csrf

            <x-ui.input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <x-ui.button variant="primary" type="submit" class="w-full" data-test="request-access-button">
                {{ __('Email me a sign-in code') }}
            </x-ui.button>
        </form>

        <p class="text-center text-sm leading-6 text-stone-600 dark:text-stone-400">
            {{ __('New here? This will create your account—no password required.') }}
        </p>
    </div>
</x-layouts::auth>
