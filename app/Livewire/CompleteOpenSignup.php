<?php

namespace App\Livewire;

use App\Actions\CompleteOpenSignup as CompleteSignup;
use App\Data\CompleteSignupInput;
use App\Exceptions\CannotCompleteSignup;
use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Contracts\View\View;
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

    /** @var array<int, string> */
    public array $selectedOptions = [];

    public string $website = '';

    public string $announcement = '';

    public bool $completed = false;

    public bool $checkEmail = false;

    /** @var array<int, string> */
    public array $unavailableOptionNames = [];

    public function mount(string $sheetPublicId): void
    {
        $sheet = Sheet::query()
            ->where('public_id', $sheetPublicId)
            ->firstOrFail();

        abort_unless($sheet->isAcceptingOpenParticipationSignups(), 404);

        $this->sheetPublicId = $sheetPublicId;
    }

    public function complete(CompleteSignup $completeSignup): void
    {
        $this->name = trim($this->name);
        $this->phone = trim($this->phone);
        $this->email = filled($this->email) ? Account::normalizeEmail($this->email) : '';
        $this->resetErrorBag();
        $this->unavailableOptionNames = [];
        $this->announcement = '';

        if (trim($this->website) !== '') {
            $this->reset('name', 'email', 'phone', 'selectedOptions', 'website');

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
            'selectedOptions' => ['required', 'array', 'min:1', 'max:'.$selectionMaximum],
            'selectedOptions.*' => ['required', 'uuid', 'distinct'],
        ], [
            'selectedOptions.required' => __('Choose at least one available Option.'),
            'selectedOptions.min' => __('Choose at least one available Option.'),
            'selectedOptions.max' => __('Choose between 1 and :max available Options.'),
        ]);

        $rateLimitKey = 'signup:'.$this->sheetPublicId.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $message = __('Too many signup attempts. Please wait a minute and try again.');
            $this->addError('signup', $message);
            $this->announcement = $message;

            return;
        }

        RateLimiter::hit($rateLimitKey, 60);

        try {
            $result = $completeSignup->handle(new CompleteSignupInput(
                sheetPublicId: $this->sheetPublicId,
                name: $this->name,
                phone: $this->phone === '' ? null : $this->phone,
                optionPublicIds: $this->selectedOptions,
                email: $this->email === '' ? null : $this->email,
                ipAddress: request()->ip() ?? 'unknown',
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
        $this->checkEmail = $result->checkEmail;

        if ($result->accessChallengePublicId !== null) {
            session()->put('account_access_challenge', $result->accessChallengePublicId);
        }

        $this->announcement = $this->checkEmail
            ? __('If the address can receive email, confirmation and an access link are on the way.')
            : __('Signup complete.');
    }

    public function render(): View
    {
        $sheet = Sheet::query()
            ->where('public_id', $this->sheetPublicId)
            ->firstOrFail();

        $acceptingSignups = $sheet->isAcceptingOpenParticipationSignups();

        $availableOptions = $acceptingSignups
            ? $sheet->options()
                ->whereColumn('claimed_count', '<', 'capacity')
                ->orderBy('position')
                ->get(['public_id', 'name'])
            : collect();

        return view('livewire.complete-open-signup', [
            'availableOptions' => $availableOptions,
            'selectionMaximum' => $sheet->selection_maximum,
            'acceptingSignups' => $acceptingSignups,
            'hasAvailableOptions' => $availableOptions->isNotEmpty(),
        ]);
    }
}
