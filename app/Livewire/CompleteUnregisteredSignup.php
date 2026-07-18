<?php

namespace App\Livewire;

use App\Actions\CompleteUnregisteredSignup as CompleteSignup;
use App\Exceptions\CannotCompleteSignup;
use App\Models\Sheet;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CompleteUnregisteredSignup extends Component
{
    #[Locked]
    public string $sheetPublicId = '';

    public string $name = '';

    public string $phone = '';

    /** @var array<int, string> */
    public array $selectedOptions = [];

    public string $website = '';

    public string $announcement = '';

    public bool $completed = false;

    /** @var array<int, string> */
    public array $unavailableOptionNames = [];

    public function mount(string $sheetPublicId): void
    {
        $sheet = Sheet::query()
            ->where('public_id', $sheetPublicId)
            ->where('state', Sheet::STATE_PUBLISHED)
            ->where('participation_policy', Sheet::PARTICIPATION_OPEN)
            ->firstOrFail();

        abort_unless($sheet->deadline_at->isFuture(), 404);

        $this->sheetPublicId = $sheetPublicId;
    }

    public function complete(CompleteSignup $completeSignup): void
    {
        $this->name = trim($this->name);
        $this->phone = trim($this->phone);
        $this->resetErrorBag();
        $this->unavailableOptionNames = [];
        $this->announcement = '';

        if (trim($this->website) !== '') {
            $this->reset('name', 'phone', 'selectedOptions', 'website');

            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'selectedOptions' => ['required', 'array', 'min:1', 'max:100'],
            'selectedOptions.*' => ['required', 'uuid', 'distinct'],
        ], [
            'selectedOptions.required' => __('Choose at least one available Option.'),
            'selectedOptions.min' => __('Choose at least one available Option.'),
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
            $completeSignup->handle(
                $this->sheetPublicId,
                $this->name,
                $this->phone === '' ? null : $this->phone,
                $this->selectedOptions,
            );
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

        $acceptingSignups = $sheet->state === Sheet::STATE_PUBLISHED
            && $sheet->participation_policy === Sheet::PARTICIPATION_OPEN
            && $sheet->deadline_at->isFuture();

        $availableOptions = $acceptingSignups
            ? $sheet->options()
                ->whereColumn('claimed_count', '<', 'capacity')
                ->orderBy('position')
                ->get(['public_id', 'name'])
            : collect();

        return view('livewire.complete-unregistered-signup', [
            'availableOptions' => $availableOptions,
            'selectionMaximum' => $sheet->selection_maximum,
            'acceptingSignups' => $acceptingSignups,
            'hasAvailableOptions' => $availableOptions->isNotEmpty(),
        ]);
    }
}
