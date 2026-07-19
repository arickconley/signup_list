<?php

namespace App\Livewire;

use App\Actions\AttachPendingAccountAssociations;
use App\Actions\CompleteVerifiedSignup as CompleteSignup;
use App\Data\CompleteSignupInput;
use App\Exceptions\CannotCompleteSignup;
use App\Exceptions\ImmediateTransactionBusy;
use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CompleteVerifiedSignup extends Component
{
    #[Locked]
    public string $sheetPublicId = '';

    public string $name = '';

    #[Locked]
    public string $email = '';

    public string $phone = '';

    public bool $nameConsent = false;

    public bool $emailConsent = false;

    public bool $phoneConsent = false;

    /** @var array<int, string> */
    public array $selectedOptions = [];

    public string $announcement = '';

    public bool $completed = false;

    #[Locked]
    public bool $existingSignup = false;

    /** @var array<int, string> */
    #[Locked]
    public array $existingOptionNames = [];

    /** @var array<int, string> */
    public array $unavailableOptionNames = [];

    public function mount(
        string $sheetPublicId,
        AttachPendingAccountAssociations $attachPendingAccountAssociations,
    ): void {
        $account = Auth::user();

        abort_unless($account instanceof Account && $account->hasVerifiedEmail(), 403);

        $sheet = Sheet::query()
            ->where('public_id', $sheetPublicId)
            ->firstOrFail();

        abort_unless($sheet->isAcceptingVerifiedParticipationSignups(), 404);

        $defaults = $account->accountDefaults();

        $this->sheetPublicId = $sheetPublicId;
        $this->name = $defaults->name;
        $this->email = $defaults->email;
        $this->phone = $defaults->phone ?? '';

        try {
            $existingSignup = $attachPendingAccountAssociations
                ->handleForSheet($account, $sheet)?->load('optionClaims.option');
        } catch (ImmediateTransactionBusy) {
            $message = __('The Signup Sheet is busy. Please wait a moment and try again.');
            $this->addError('signup', $message);
            $this->announcement = $message;

            return;
        }

        if ($existingSignup !== null) {
            $this->existingSignup = true;
            $this->existingOptionNames = array_values($existingSignup->optionClaims
                ->sortBy(fn (OptionClaim $claim): int => $claim->option->position)
                ->map(fn (OptionClaim $claim): string => $claim->option->name)
                ->all());
        }
    }

    public function complete(CompleteSignup $completeSignup): void
    {
        $account = Auth::user();

        abort_unless($account instanceof Account, 403);

        $this->name = trim($this->name);
        $this->phone = trim($this->phone);
        $this->resetErrorBag();
        $this->unavailableOptionNames = [];
        $this->announcement = '';

        $selectionMaximum = Sheet::query()
            ->where('public_id', $this->sheetPublicId)
            ->firstOrFail()
            ->selection_maximum;

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
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

        $rateLimitKey = 'signup:'.$this->sheetPublicId.'|account:'.$account->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            Log::warning('signup.throttled', [
                'operation' => 'submission',
                'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
                'sheet_public_id' => $this->sheetPublicId,
            ]);

            $message = __('Too many signup attempts. Please wait a minute and try again.');
            $this->addError('signup', $message);
            $this->announcement = $message;

            return;
        }

        RateLimiter::hit($rateLimitKey, 60);

        try {
            $completeSignup->handle($account, new CompleteSignupInput(
                sheetPublicId: $this->sheetPublicId,
                name: $this->name,
                phone: $this->phone === '' ? null : $this->phone,
                optionPublicIds: $this->selectedOptions,
                email: $this->email,
                ipAddress: request()->ip() ?? 'unknown',
                nameConsent: $this->nameConsent,
                emailConsent: $this->emailConsent,
                phoneConsent: $this->phoneConsent,
            ));
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
        $this->announcement = __('Signup complete.');
    }

    public function render(): View
    {
        $sheet = Sheet::query()
            ->where('public_id', $this->sheetPublicId)
            ->firstOrFail();

        return view('livewire.complete-verified-signup', [
            'availableOptions' => $sheet->options()
                ->whereColumn('claimed_count', '<', 'capacity')
                ->orderBy('position')
                ->get(['public_id', 'name']),
            'selectionMaximum' => $sheet->selection_maximum,
            'showsNameConsent' => $sheet->name_visibility === Sheet::VISIBILITY_PARTICIPANTS,
            'showsEmailConsent' => $sheet->email_visibility === Sheet::VISIBILITY_PARTICIPANTS,
            'showsPhoneConsent' => $sheet->phone_visibility === Sheet::VISIBILITY_PARTICIPANTS,
        ]);
    }
}
