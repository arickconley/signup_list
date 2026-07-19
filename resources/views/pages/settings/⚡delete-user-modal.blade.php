<?php

use App\Actions\ConsumeAccountAccessChallenge;
use App\Actions\DeleteAccount;
use App\Actions\IssueAccountAccessChallenge;
use App\Concerns\PasswordValidationRules;
use App\Data\AccountDeletionSummary;
use App\Exceptions\CannotDeleteAccount;
use App\Livewire\Actions\Logout;
use App\Mail\AccountDeletionVerificationMail;
use App\Models\Account;
use App\Models\AccountAccessChallenge;
use App\Support\AccountAccessAbuseControl;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    /** @var array{draft: int, open: int, closed: int, archived: int} */
    public array $sheetCounts = [
        'draft' => 0,
        'open' => 0,
        'closed' => 0,
        'archived' => 0,
    ];

    public string $password = '';

    public string $confirmation = '';

    public string $verificationCode = '';

    public bool $verificationSent = false;

    public bool $emailVerified = false;

    public function beginDeletion(): void
    {
        $this->sheetCounts = AccountDeletionSummary::for(Auth::user())->toArray();
        $this->reset(
            'password',
            'confirmation',
            'verificationCode',
            'verificationSent',
            'emailVerified',
        );
        $this->resetErrorBag();
        session()->forget([
            'account_deletion_challenge',
            'account_deletion_email_verified_account_id',
            'account_deletion_email_verified_address',
            'account_deletion_email_verified_at',
        ]);
    }

    public function sendDeletionVerification(
        IssueAccountAccessChallenge $issueAccountAccessChallenge,
        AccountAccessAbuseControl $abuseControl,
    ): void {
        /** @var Account $account */
        $account = Auth::user();

        if ($abuseControl->attemptSend($account->email, request()->ip() ?? 'unknown')) {
            $challenge = $issueAccountAccessChallenge->handle(
                $account->email,
                fn (string $code, string $_magicLink, CarbonInterface $expiresAt): AccountDeletionVerificationMail => new AccountDeletionVerificationMail($code, $expiresAt),
            );

            session()->put('account_deletion_challenge', $challenge->public_id);
        }

        $this->reset('verificationCode', 'emailVerified');
        $this->resetErrorBag('verificationCode');
        $this->verificationSent = true;
    }

    public function verifyDeletionEmail(
        ConsumeAccountAccessChallenge $consumeAccountAccessChallenge,
        AccountAccessAbuseControl $abuseControl,
    ): void {
        $validated = $this->validate([
            'verificationCode' => ['required', 'digits:6'],
        ]);

        /** @var Account $account */
        $account = Auth::user();
        $publicId = session()->get('account_deletion_challenge');

        $challengeMatchesAccount = is_string($publicId)
            && AccountAccessChallenge::query()
                ->where('public_id', $publicId)
                ->where('email', $account->email)
                ->exists();

        $verifiedAccount = $challengeMatchesAccount
            && $abuseControl->attemptVerification($account->email, request()->ip() ?? 'unknown')
                ? $consumeAccountAccessChallenge->usingCode($publicId, $validated['verificationCode'])
                : null;

        if (! $verifiedAccount?->is($account)) {
            $this->addError('verificationCode', __('That verification code is invalid or has expired.'));

            return;
        }

        session()->put([
            'account_deletion_email_verified_account_id' => $account->id,
            'account_deletion_email_verified_address' => $account->email,
            'account_deletion_email_verified_at' => Date::now()->unix(),
        ]);
        session()->forget('account_deletion_challenge');
        $this->emailVerified = true;
    }

    public function cancelDeletion(): void
    {
        $this->reset(
            'password',
            'confirmation',
            'verificationCode',
            'verificationSent',
            'emailVerified',
        );
        $this->resetErrorBag();
        session()->forget([
            'account_deletion_challenge',
            'account_deletion_email_verified_account_id',
            'account_deletion_email_verified_address',
            'account_deletion_email_verified_at',
        ]);
    }

    public function deleteUser(DeleteAccount $deleteAccount, Logout $logout): void
    {
        /** @var Account $account */
        $account = Auth::user();

        if (! $this->hasFreshDeletionEmailVerification()) {
            $this->addError('verification', __('Verify your email again before deleting your account.'));

            return;
        }

        $rules = [
            'confirmation' => ['required', 'in:DELETE'],
        ];

        if ($account->password !== null) {
            $rules['password'] = $this->currentPasswordRules();
        }

        $this->validate($rules);

        try {
            $deleteAccount->handle($account, new AccountDeletionSummary(
                draft: $this->sheetCounts['draft'],
                open: $this->sheetCounts['open'],
                closed: $this->sheetCounts['closed'],
                archived: $this->sheetCounts['archived'],
            ));
        } catch (CannotDeleteAccount $exception) {
            $this->addError('deletion', $exception->getMessage());
            $this->sheetCounts = AccountDeletionSummary::for($account)->toArray();
            $this->reset('password', 'confirmation');

            return;
        }

        $logout();

        $this->redirect('/', navigate: true);
    }

    private function hasFreshDeletionEmailVerification(): bool
    {
        $verifiedAt = (int) session()->get('account_deletion_email_verified_at', 0);

        return session()->get('account_deletion_email_verified_account_id') === Auth::id()
            && session()->get('account_deletion_email_verified_address') === Auth::user()?->email
            && $verifiedAt > 0
            && Date::now()->unix() - $verifiedAt < ((int) config('account-access.lifetime_minutes') * 60);
    }
}; ?>

<dialog
    x-data
    x-ref="dialog"
    x-init="if (@js($errors->isNotEmpty())) $nextTick(() => $refs.dialog.showModal())"
    x-on:open-delete-account.window="$wire.beginDeletion(); $refs.dialog.showModal(); $nextTick(() => $refs.password?.focus())"
    x-on:click.self="$refs.dialog.close()"
    class="m-auto max-h-[calc(100vh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-visible rounded-2xl bg-transparent p-0 text-stone-950 backdrop:bg-stone-950/50 backdrop:backdrop-blur-sm dark:text-stone-50"
    aria-labelledby="delete-account-title"
>
    <div class="w-full rounded-2xl border border-stone-200 bg-white p-6 shadow-2xl dark:border-stone-700 dark:bg-stone-900">
        <form wire:submit="deleteUser" class="space-y-6">
            <div>
                <h2 id="delete-account-title" class="font-display text-2xl font-semibold">{{ __('Are you sure you want to delete your account?') }}</h2>
                <p class="mt-2 text-sm leading-6 text-stone-600 dark:text-stone-400">{{ __('Owned Signup Sheets will be permanently deleted. Signups on other Owners’ Sheets will remain without your identity. Verify your email, review the counts, and explicitly confirm below.') }}</p>
            </div>

            <dl class="grid grid-cols-2 gap-3 rounded-xl bg-stone-100 p-4 text-sm dark:bg-stone-800">
                <div><dt class="text-stone-600 dark:text-stone-400">{{ __('Draft Sheets') }}</dt><dd class="font-semibold">{{ $sheetCounts['draft'] }}</dd></div>
                <div><dt class="text-stone-600 dark:text-stone-400">{{ __('Open Sheets') }}</dt><dd class="font-semibold">{{ $sheetCounts['open'] }}</dd></div>
                <div><dt class="text-stone-600 dark:text-stone-400">{{ __('Closed Sheets') }}</dt><dd class="font-semibold">{{ $sheetCounts['closed'] }}</dd></div>
                <div><dt class="text-stone-600 dark:text-stone-400">{{ __('Archived Sheets') }}</dt><dd class="font-semibold">{{ $sheetCounts['archived'] }}</dd></div>
            </dl>

            @error('verification')
                <x-ui.callout variant="danger">{{ $message }}</x-ui.callout>
            @enderror

            @error('deletion')
                <x-ui.callout variant="danger">{{ $message }}</x-ui.callout>
            @enderror

            @if ($emailVerified)
                <x-ui.callout :heading="__('Email verified')">
                    {{ __('Finish the irreversible confirmation below while this verification is fresh.') }}
                </x-ui.callout>
            @elseif ($verificationSent)
                <div class="space-y-4">
                    <x-ui.otp wire:model="verificationCode" name="verificationCode" :label="__('Email verification code')" required />
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button wire:click="verifyDeletionEmail" variant="primary">{{ __('Verify email') }}</x-ui.button>
                        <x-ui.button wire:click="sendDeletionVerification" variant="ghost">{{ __('Send another code') }}</x-ui.button>
                    </div>
                </div>
            @else
                <x-ui.button wire:click="sendDeletionVerification" variant="primary">
                    {{ __('Email me a deletion verification code') }}
                </x-ui.button>
            @endif

            @if (Auth::user()->password !== null)
                <x-ui.input wire:model="password" x-ref="password" :label="__('Password')" type="password" viewable />
            @endif

            <x-ui.input
                wire:model="confirmation"
                :label="__('Type DELETE to confirm')"
                type="text"
                autocomplete="off"
                required
            />

            <div class="flex justify-end gap-2">
                <x-ui.button variant="filled" wire:click="cancelDeletion" x-on:click="$refs.dialog.close()">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="danger" type="submit" data-test="confirm-delete-user-button">{{ __('Delete account') }}</x-ui.button>
            </div>
        </form>
    </div>
</dialog>
