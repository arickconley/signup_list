<?php

use App\Models\Account;
use App\Support\OwnerEligibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Create a signup sheet')] class extends Component
{
    public string $title = '';

    public string $description = '';

    public string $eventAt = '';

    public string $location = '';

    public function save(OwnerEligibility $eligibility): void
    {
        $this->title = trim($this->title);
        $this->description = trim($this->description);
        $this->eventAt = trim($this->eventAt);
        $this->location = trim($this->location);

        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'eventAt' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $account = Auth::user();

        abort_unless($account instanceof Account, 403);

        if (! $eligibility->canCreateSheet($account)) {
            $this->addError('ownership', __('This email domain cannot be used to create signup sheets.'));

            return;
        }

        $account->ownedSheets()->create([
            'title' => $this->title,
            'description' => $this->description === '' ? null : $this->description,
            'event_at' => $this->eventAt === ''
                ? null
                : Carbon::parse($this->eventAt, $account->timezone)->utc(),
            'location' => $this->location === '' ? null : $this->location,
            'deadline_at' => Carbon::now($account->timezone)
                ->addDays(14)
                ->setTime(23, 59)
                ->utc(),
            'timezone' => $account->timezone,
        ]);

        session()->flash('success', __('Draft Sheet created.'));

        $this->redirectRoute('dashboard', navigate: true);
    }
};

?>

<x-layouts::app :title="__('Create a signup sheet')">
    <div class="mx-auto max-w-3xl">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-teal-400">{{ __('New Draft Sheet') }}</p>
        <h1 class="mt-2 font-display text-4xl font-semibold tracking-tight">{{ __('Create a signup sheet') }}</h1>

        <form wire:submit="save" class="mt-8 space-y-6 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm dark:border-stone-800 dark:bg-stone-900">
            @if ($errors->any())
                <x-ui.callout variant="danger" :heading="__('Please correct the highlighted fields.')">
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-ui.callout>
            @endif

            <x-ui.input wire:model="title" :label="__('Title')" type="text" required autofocus />

            <div class="grid gap-2">
                <label for="description" class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ __('Description') }}</label>
                <textarea id="description" wire:model="description" rows="4" class="block w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-base text-stone-950 shadow-sm outline-none transition focus:border-teal-600 focus:ring-2 focus:ring-teal-600/25 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-50 dark:focus:border-teal-400 dark:focus:ring-teal-400/25 sm:text-sm" @if ($errors->has('description')) aria-invalid="true" aria-describedby="description-error" @endif></textarea>
                @error('description')
                    <p id="description-error" class="text-sm font-medium text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <x-ui.input wire:model="eventAt" :label="__('Event date and time')" type="datetime-local" :description="__('Optional. Uses your profile timezone.')" />
                <x-ui.input wire:model="location" :label="__('Location')" type="text" :description="__('Optional.')" />
            </div>

            <div class="flex justify-end">
                <x-ui.button type="submit">{{ __('Create Draft Sheet') }}</x-ui.button>
            </div>
        </form>
    </div>
</x-layouts::app>
