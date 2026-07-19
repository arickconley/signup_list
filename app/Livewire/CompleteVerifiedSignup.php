<?php

namespace App\Livewire;

use App\Actions\CompleteVerifiedSignup as CompleteSignup;
use App\Data\CompleteSignupInput;
use App\Exceptions\CannotCompleteSignup;
use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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

    public function mount(string $sheetPublicId): void
    {
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

        $existingSignup = $sheet->signups()
            ->where('account_id', $account->id)
            ->with('optionClaims.option')
            ->first();

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
            'selectedOptions' => ['required', 'array', 'min:1', 'max:'.$selectionMaximum],
            'selectedOptions.*' => ['required', 'uuid', 'distinct'],
        ], [
            'selectedOptions.required' => __('Choose at least one available Option.'),
            'selectedOptions.min' => __('Choose at least one available Option.'),
            'selectedOptions.max' => __('Choose between 1 and :max available Options.'),
        ]);

        try {
            $completeSignup->handle($account, new CompleteSignupInput(
                sheetPublicId: $this->sheetPublicId,
                name: $this->name,
                phone: $this->phone === '' ? null : $this->phone,
                optionPublicIds: $this->selectedOptions,
                email: $this->email,
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
        ]);
    }
}
