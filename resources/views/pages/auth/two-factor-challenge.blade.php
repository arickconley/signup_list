<x-layouts::auth :title="__('Two-factor authentication')">
    <div class="flex flex-col gap-6">
        <div
            class="relative w-full h-auto"
            x-cloak
            x-data="{
                showRecoveryInput: @js($errors->has('recovery_code')),
                code: '',
                recovery_code: '',
                focusOtp() {
                    this.$nextTick(() => this.$refs.otp?.querySelector('input')?.focus());
                },
                init() {
                    if (! this.showRecoveryInput) {
                        this.focusOtp();
                    }
                },
                toggleInput() {
                    this.showRecoveryInput = !this.showRecoveryInput;

                    this.code = '';
                    this.recovery_code = '';

                    $nextTick(() => {
                        this.showRecoveryInput
                            ? this.$refs.recovery_code?.focus()
                            : this.focusOtp();
                    });
                },
            }"
        >
            <div x-show="!showRecoveryInput">
                <x-auth-header
                    :title="__('Authentication code')"
                    :description="__('Enter the authentication code provided by your authenticator application.')"
                />
            </div>

            <div x-show="showRecoveryInput">
                <x-auth-header
                    :title="__('Recovery code')"
                    :description="__('Please confirm access to your account by entering one of your emergency recovery codes.')"
                />
            </div>

            <form method="POST" action="{{ route('two-factor.login.store') }}">
                @csrf

                <div class="space-y-5 text-center">
                    <div x-show="!showRecoveryInput">
                        <div class="flex items-center justify-center my-5" x-ref="otp">
                            <x-ui.otp
                                x-model="code"
                                length="6"
                                name="code"
                                :label="__('Authentication code')"
                                class="mx-auto"
                             />
                        </div>
                    </div>

                    <div x-show="showRecoveryInput">
                        <div class="my-5">
                            <x-ui.input
                                type="text"
                                name="recovery_code"
                                :label="__('Recovery code')"
                                x-ref="recovery_code"
                                x-bind:required="showRecoveryInput"
                                autocomplete="one-time-code"
                                x-model="recovery_code"
                            />
                        </div>

                        @error('recovery_code')
                            <p class="text-sm font-medium text-red-700 dark:text-red-400">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    <x-ui.button
                        variant="primary"
                        type="submit"
                        class="w-full"
                    >
                        {{ __('Continue') }}
                    </x-ui.button>
                </div>

                <div class="mt-5 space-x-0.5 text-sm leading-5 text-center">
                    <span class="opacity-50">{{ __('or you can') }}</span>
                    <button
                        type="button"
                        x-show="!showRecoveryInput"
                        x-on:click="toggleInput()"
                        class="min-h-11 rounded px-1 font-medium underline underline-offset-4 opacity-80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                    >
                        {{ __('login using a recovery code') }}
                    </button>
                    <button
                        type="button"
                        x-show="showRecoveryInput"
                        x-on:click="toggleInput()"
                        class="min-h-11 rounded px-1 font-medium underline underline-offset-4 opacity-80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                    >
                        {{ __('login using an authentication code') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-layouts::auth>
