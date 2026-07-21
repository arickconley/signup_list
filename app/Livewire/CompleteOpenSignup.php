<?php

namespace App\Livewire;

use App\Actions\CompleteOpenSignup as CompleteSignup;
use App\Actions\UnclaimOpenParticipationOption;
use App\Data\CompleteSignupInput;
use App\Exceptions\CannotCompleteSignup;
use App\Exceptions\CannotUnclaimOpenParticipationOption;
use App\Models\Account;
use App\Models\Option;
use App\Models\Sheet;
use App\Support\OpenParticipationIdentity;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CompleteOpenSignup extends Component
{
    #[Locked]
    public string $sheetPublicId = '';

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public bool $nameConsent = false;

    public bool $emailConsent = false;

    public bool $phoneConsent = false;

    /** @var array<int, string> */
    public array $selectedOptions = [];

    public string $website = '';

    public string $announcement = '';

    #[Locked]
    public bool $usesAccountName = false;

    public bool $completed = false;

    public bool $claimed = false;

    public bool $checkEmail = false;

    /** @var array<int, string> */
    public array $unavailableOptionNames = [];

    #[Locked]
    public ?string $pendingOptionPublicId = null;

    #[Locked]
    public string $pendingOptionName = '';

    public bool $showNameModal = false;

    public function mount(string $sheetPublicId): void
    {
        $sheet = Sheet::query()
            ->where('public_id', $sheetPublicId)
            ->firstOrFail();

        abort_unless($sheet->isAcceptingOpenParticipationSignups(), 404);

        $this->sheetPublicId = $sheetPublicId;

        $account = Auth::user();

        if ($account instanceof Account && filled($account->name)) {
            $this->usesAccountName = true;
        }
    }

    public function complete(CompleteSignup $completeSignup): void
    {
        $this->submit($completeSignup);
    }

    private function submit(
        CompleteSignup $completeSignup,
        bool $immediateClaim = false,
        ?Account $account = null,
        ?string $participationKeyHash = null,
    ): void {
        $this->name = trim($this->name);
        $this->phone = trim($this->phone);
        $this->email = filled($this->email) ? Account::normalizeEmail($this->email) : '';
        $this->resetErrorBag();
        $this->unavailableOptionNames = [];
        $this->announcement = '';
        $this->claimed = false;

        if (trim($this->website) !== '') {
            $this->reset(
                'name',
                'email',
                'phone',
                'nameConsent',
                'emailConsent',
                'phoneConsent',
                'selectedOptions',
                'website',
            );

            return;
        }

        $selectionMaximum = Sheet::query()
            ->where('public_id', $this->sheetPublicId)
            ->firstOrFail()
            ->selection_maximum;

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'nameConsent' => ['boolean'],
            'emailConsent' => ['boolean'],
            'phoneConsent' => ['boolean'],
            'selectedOptions' => ['required', 'array', 'min:1', 'max:'.$selectionMaximum],
            'selectedOptions.*' => ['required', 'uuid', 'distinct'],
        ], [
            'selectedOptions.required' => __('Choose at least one available Option.'),
            'selectedOptions.min' => __('Choose at least one available Option.'),
            'selectedOptions.max' => __('Choose between 1 and :max available Options.'),
        ]);

        $rateLimitKey = 'signup:'.$this->sheetPublicId.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            Log::warning('signup.throttled', [
                'operation' => 'submission',
                'participation_policy' => Sheet::PARTICIPATION_OPEN,
                'sheet_public_id' => $this->sheetPublicId,
            ]);

            $message = __('Too many signup attempts. Please wait a minute and try again.');
            $this->addError('signup', $message);
            $this->announcement = $message;

            return;
        }

        RateLimiter::hit($rateLimitKey, 60);

        try {
            $input = new CompleteSignupInput(
                sheetPublicId: $this->sheetPublicId,
                name: $this->name,
                phone: $this->phone === '' ? null : $this->phone,
                optionPublicIds: $this->selectedOptions,
                email: $this->email === '' ? null : $this->email,
                ipAddress: request()->ip() ?? 'unknown',
                nameConsent: $this->nameConsent,
                emailConsent: $this->emailConsent,
                phoneConsent: $this->phoneConsent,
            );

            $result = $immediateClaim
                ? $completeSignup->claim($account, $input, $participationKeyHash)
                : $completeSignup->handle($input);
        } catch (CannotCompleteSignup $exception) {
            $this->unavailableOptionNames = $exception->unavailableOptionNames;
            $this->selectedOptions = array_values(array_diff(
                $this->selectedOptions,
                $exception->unavailableOptionPublicIds,
            ));
            $this->addError('signup', $exception->getMessage());
            $this->announcement = $exception->getMessage();

            return;
        }

        $this->completed = true;
        $this->checkEmail = $result->checkEmail;

        if ($result->accessChallengePublicId !== null) {
            session()->put('account_access_challenge', $result->accessChallengePublicId);
        }

        $this->announcement = $this->checkEmail
            ? __('If the address can receive email, confirmation and an access link are on the way.')
            : __('Signup complete.');
    }

    public function claim(
        string $optionPublicId,
        CompleteSignup $completeSignup,
        OpenParticipationIdentity $participationIdentity,
    ): void {
        $authenticatedAccount = Auth::user();
        $account = $authenticatedAccount instanceof Account
            ? $authenticatedAccount
            : null;

        $usesAccountName = $account !== null && $this->usesAccountName;

        if ($usesAccountName) {
            $this->name = $account->accountDefaults()->name;
        }

        $this->selectedOptions = [$optionPublicId];

        try {
            $this->submit(
                $completeSignup,
                immediateClaim: true,
                account: $account,
                participationKeyHash: $account === null
                    ? $participationIdentity->hashForSheet($this->sheetPublicId)
                    : null,
            );
        } finally {
            if ($usesAccountName) {
                $this->name = '';
            }
        }

        if ($this->completed && ! $this->checkEmail) {
            $this->completed = false;
            $this->claimed = true;
            $this->announcement = __('Option claimed.');
        }
    }

    public function beginClaim(
        string $optionPublicId,
        CompleteSignup $completeSignup,
        OpenParticipationIdentity $participationIdentity,
    ): void {
        $option = $this->findAvailableOption($optionPublicId);

        $this->resetErrorBag();
        $this->announcement = '';
        $this->pendingOptionPublicId = $option->public_id;
        $this->pendingOptionName = $option->name;

        if ($this->usesAccountName) {
            $this->claim($option->public_id, $completeSignup, $participationIdentity);

            if ($this->claimed) {
                $this->redirectAfterClaim();
            }

            return;
        }

        $this->showNameModal = true;
    }

    public function claimPending(
        CompleteSignup $completeSignup,
        OpenParticipationIdentity $participationIdentity,
    ): void {
        abort_unless($this->showNameModal && $this->pendingOptionPublicId !== null, 404);

        $this->claim($this->pendingOptionPublicId, $completeSignup, $participationIdentity);

        if ($this->claimed) {
            $this->redirectAfterClaim();
        }
    }

    public function cancelClaim(): void
    {
        $this->resetErrorBag();
        $this->reset('pendingOptionPublicId', 'pendingOptionName', 'showNameModal');
        $this->announcement = '';
    }

    public function unclaim(
        string $optionPublicId,
        UnclaimOpenParticipationOption $unclaimOption,
        OpenParticipationIdentity $participationIdentity,
    ): void {
        $authenticatedAccount = Auth::user();
        $account = $authenticatedAccount instanceof Account
            ? $authenticatedAccount
            : null;

        $this->resetErrorBag();
        $this->announcement = '';

        try {
            $unclaimOption->handle(
                $account,
                $this->sheetPublicId,
                $optionPublicId,
                $account === null
                    ? $participationIdentity->hashForSheet($this->sheetPublicId)
                    : null,
            );
        } catch (CannotUnclaimOpenParticipationOption $exception) {
            $this->addError('unclaim', $exception->getMessage());
            $this->announcement = $exception->getMessage();

            return;
        }

        session()->flash('option-unclaimed', __('Option unclaimed.'));

        $this->redirectRoute('sheets.show', [
            'sheet' => $this->sheetPublicId,
        ], navigate: true);
    }

    public function render(): View
    {
        Sheet::query()
            ->where('public_id', $this->sheetPublicId)
            ->firstOrFail();

        return view('livewire.complete-open-signup');
    }

    private function findAvailableOption(string $optionPublicId): Option
    {
        $sheet = Sheet::query()
            ->where('public_id', $this->sheetPublicId)
            ->firstOrFail();

        abort_unless($sheet->isAcceptingOpenParticipationSignups(), 404);

        return $sheet->options()
            ->where('public_id', $optionPublicId)
            ->whereColumn('claimed_count', '<', 'capacity')
            ->firstOrFail(['public_id', 'name']);
    }

    private function redirectAfterClaim(): void
    {
        session()->flash('option-claimed', __(':option claimed.', [
            'option' => $this->pendingOptionName,
        ]));

        $this->redirectRoute('sheets.show', [
            'sheet' => $this->sheetPublicId,
        ], navigate: true);
    }
}
