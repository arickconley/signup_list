<?php

use App\Models\Account;
use App\Models\Sheet;
use App\Support\DefaultSheetDeadline;
use App\Support\OwnerEligibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app', ['robots' => 'noindex, nofollow'])] #[Title('Create a signup sheet')] class extends Component
{
    public string $title = '';

    public string $description = '';

    public string $eventAt = '';

    public string $deadlineAt = '';

    public string $location = '';

    public string $selectionMaximum = '1';

    public string $participationPolicy = Sheet::PARTICIPATION_OPEN;

    /** @var list<array{key: string, name: string, description: string, capacity: string}> */
    public array $optionRows = [];

    public function mount(DefaultSheetDeadline $defaultDeadline): void
    {
        $account = Auth::user();

        abort_unless($account instanceof Account, 403);

        $this->deadlineAt = $defaultDeadline->forTimezone($account->timezone)
            ->timezone($account->timezone)
            ->format('Y-m-d\TH:i');
        $this->optionRows = [$this->newOptionRow()];
    }

    public function save(OwnerEligibility $eligibility): void
    {
        $sheet = $this->createSheet(publishing: false, eligibility: $eligibility);

        if (! $sheet instanceof Sheet) {
            return;
        }

        session()->flash('success', __('Draft Sheet created.'));

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function publish(OwnerEligibility $eligibility): void
    {
        $sheet = $this->createSheet(publishing: true, eligibility: $eligibility);

        if (! $sheet instanceof Sheet) {
            return;
        }

        session()->flash('success', __('Signup Sheet published.'));

        $this->redirectRoute('sheets.edit', $sheet, navigate: true);
    }

    public function addOptionRow(): void
    {
        $this->optionRows[] = $this->newOptionRow();
    }

    public function removeOptionRow(int $index): void
    {
        if (! array_key_exists($index, $this->optionRows)) {
            return;
        }

        unset($this->optionRows[$index]);
        $this->optionRows = array_values($this->optionRows);
        $this->resetValidation(['options', 'optionRows']);
    }

    private function createSheet(bool $publishing, OwnerEligibility $eligibility): ?Sheet
    {
        $this->title = trim($this->title);
        $this->description = trim($this->description);
        $this->eventAt = trim($this->eventAt);
        $this->deadlineAt = trim($this->deadlineAt);
        $this->location = trim($this->location);
        $this->selectionMaximum = trim($this->selectionMaximum);
        $this->normalizeOptions();

        $enteredOptions = collect($this->optionRows)
            ->filter(fn (array $option): bool => $option['name'] !== '')
            ->values();
        $optionCount = $enteredOptions->count();

        $this->withValidator(function (Validator $validator) use ($enteredOptions, $publishing): void {
            $validator->after(function (Validator $validator) use ($enteredOptions, $publishing): void {
                if ($publishing && $enteredOptions->isEmpty()) {
                    $validator->errors()->add('options', __('Add at least one valid Option before publishing.'));
                }
            });
        })->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'eventAt' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'deadlineAt' => ['required', 'date_format:Y-m-d\TH:i'],
            'location' => ['nullable', 'string', 'max:255'],
            'selectionMaximum' => [
                $publishing || $optionCount > 0 ? 'required' : 'nullable',
                'integer',
                'min:1',
                'max:'.max($optionCount, 1),
            ],
            'participationPolicy' => ['required', 'in:'.Sheet::PARTICIPATION_OPEN.','.Sheet::PARTICIPATION_VERIFIED],
            'optionRows' => ['array', 'max:100'],
            'optionRows.*.name' => ['nullable', 'required_with:optionRows.*.description', 'string', 'max:255'],
            'optionRows.*.description' => ['nullable', 'string', 'max:1000'],
            'optionRows.*.capacity' => ['required', 'integer', 'min:1'],
        ], attributes: [
            'optionRows.*.name' => __('Option name'),
            'optionRows.*.description' => __('Option description'),
            'optionRows.*.capacity' => __('Option capacity'),
        ]);

        $account = Auth::user();

        abort_unless($account instanceof Account, 403);

        if (! $eligibility->canCreateSheet($account)) {
            $this->addError('ownership', __('This email domain cannot be used to create signup sheets.'));

            return null;
        }

        $eventAt = $this->eventAt === ''
            ? null
            : Carbon::parse($this->eventAt, $account->timezone)->utc();
        $deadlineAt = Carbon::parse($this->deadlineAt, $account->timezone)->utc();

        if (! $deadlineAt->isFuture()) {
            $this->addError('deadlineAt', __('Signup deadline must be in the future.'));

            return null;
        }

        if ($eventAt !== null && $deadlineAt->isAfter($eventAt)) {
            $this->addError('deadlineAt', __('Signup deadline must be at or before the event date and time.'));

            return null;
        }

        return DB::transaction(function () use ($account, $deadlineAt, $enteredOptions, $eventAt, $optionCount, $publishing): Sheet {
            $sheet = $account->ownedSheets()->create([
                'title' => $this->title,
                'description' => $this->description === '' ? null : $this->description,
                'event_at' => $eventAt,
                'location' => $this->location === '' ? null : $this->location,
                'participation_policy' => $this->participationPolicy,
                'deadline_at' => $deadlineAt,
                'selection_maximum' => $optionCount === 0 ? null : (int) $this->selectionMaximum,
                'timezone' => $account->timezone,
                'state' => $publishing ? Sheet::STATE_PUBLISHED : Sheet::STATE_DRAFT,
            ]);

            foreach ($enteredOptions as $position => $option) {
                $sheet->options()->create([
                    'name' => $option['name'],
                    'description' => $option['description'] === '' ? null : $option['description'],
                    'capacity' => (int) $option['capacity'],
                    'position' => $position + 1,
                ]);
            }

            return $sheet;
        });
    }

    private function normalizeOptions(): void
    {
        $this->optionRows = collect($this->optionRows)
            ->map(fn (array $option): array => [
                'key' => is_string($option['key'] ?? null) ? $option['key'] : (string) Str::uuid(),
                'name' => trim((string) ($option['name'] ?? '')),
                'description' => trim((string) ($option['description'] ?? '')),
                'capacity' => trim((string) ($option['capacity'] ?? '')),
            ])
            ->values()
            ->all();
    }

    /** @return array{key: string, name: string, description: string, capacity: string} */
    private function newOptionRow(): array
    {
        return [
            'key' => (string) Str::uuid(),
            'name' => '',
            'description' => '',
            'capacity' => '1',
        ];
    }
};

?>

<div class="mx-auto max-w-3xl">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-teal-400">{{ __('New Signup Sheet') }}</p>
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

            <fieldset class="grid gap-4 border-t border-stone-200 pt-6 dark:border-stone-800">
                <legend class="pe-3 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ __('Schedule') }}</legend>
                <div class="grid gap-6 sm:grid-cols-2">
                    <x-ui.input wire:model="eventAt" :label="__('Event date and time')" type="datetime-local" :description="__('Optional. When the event happens, in your profile timezone.')" />
                    <x-ui.input wire:model="deadlineAt" :label="__('Signup deadline')" type="datetime-local" :description="__('Required. Claims close at this time, in your profile timezone.')" required />
                    <div class="sm:col-span-2">
                        <x-ui.input wire:model="location" :label="__('Location')" type="text" :description="__('Optional.')" />
                    </div>
                </div>
            </fieldset>

            <fieldset class="grid gap-3" @if ($errors->has('participationPolicy')) aria-invalid="true" aria-describedby="participation-policy-error" @endif>
                <legend class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ __('Participation policy') }}</legend>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-stone-300 p-4 dark:border-stone-700">
                        <input wire:model="participationPolicy" type="radio" name="participationPolicy" value="{{ Sheet::PARTICIPATION_OPEN }}" class="mt-0.5 size-5 border-stone-300 text-teal-700 focus:ring-teal-600 dark:border-stone-600 dark:bg-stone-900">
                        <span>
                            <span class="block font-semibold">{{ __('Open Participation') }}</span>
                            <span class="mt-1 block text-sm text-stone-600 dark:text-stone-400">{{ __('Anyone may sign up; email is optional.') }}</span>
                        </span>
                    </label>
                    <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-stone-300 p-4 dark:border-stone-700">
                        <input wire:model="participationPolicy" type="radio" name="participationPolicy" value="{{ Sheet::PARTICIPATION_VERIFIED }}" class="mt-0.5 size-5 border-stone-300 text-teal-700 focus:ring-teal-600 dark:border-stone-600 dark:bg-stone-900">
                        <span>
                            <span class="block font-semibold">{{ __('Verified Participation') }}</span>
                            <span class="mt-1 block text-sm text-stone-600 dark:text-stone-400">{{ __('A verified Account is required before capacity is reserved.') }}</span>
                        </span>
                    </label>
                </div>
                @error('participationPolicy')
                    <p id="participation-policy-error" class="text-sm font-medium text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror
            </fieldset>

            <fieldset class="grid gap-5 border-t border-stone-200 pt-6 dark:border-stone-800" @if ($errors->has('options')) aria-invalid="true" aria-describedby="create-options-error" @endif>
                <legend class="pe-3 font-display text-2xl font-semibold">{{ __('Options') }}</legend>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <p class="max-w-xl text-sm text-stone-600 dark:text-stone-400">
                        {{ __('Add the choices participants can claim. Every Option needs its own capacity.') }}
                    </p>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">
                        {{ trans_choice(':count Option|:count Options', count($optionRows), ['count' => count($optionRows)]) }}
                    </p>
                </div>

                @error('options')
                    <p id="create-options-error" role="alert" class="text-sm font-medium text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror

                <div class="grid gap-4">
                    @forelse ($optionRows as $index => $option)
                        <fieldset wire:key="create-option-{{ $option['key'] }}" class="relative grid gap-4 rounded-xl border border-stone-200 bg-stone-50/70 p-4 dark:border-stone-700 dark:bg-stone-950/40">
                            <legend class="px-2 text-xs font-bold uppercase tracking-[0.16em] text-teal-700 dark:text-teal-400">
                                {{ __('Option :number', ['number' => $index + 1]) }}
                            </legend>

                            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_8rem]">
                                <x-ui.input wire:model="optionRows.{{ $index }}.name" :label="__('Option name')" type="text" :description="__('Required to publish.')" />
                                <x-ui.input wire:model="optionRows.{{ $index }}.capacity" :label="__('Capacity')" type="number" min="1" required />
                            </div>

                            <x-ui.input wire:model="optionRows.{{ $index }}.description" :label="__('Short description')" type="text" :description="__('Optional.')" />

                            <div class="flex justify-end">
                                <x-ui.button wire:click="removeOptionRow({{ $index }})" variant="ghost" size="sm">
                                    {{ __('Remove Option') }}
                                </x-ui.button>
                            </div>
                        </fieldset>
                    @empty
                        <div class="rounded-xl border border-dashed border-stone-300 px-5 py-8 text-center dark:border-stone-700">
                            <p class="font-semibold">{{ __('No Options added yet') }}</p>
                            <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ __('A Draft may be saved without Options. Publishing requires at least one.') }}</p>
                        </div>
                    @endforelse
                </div>

                <div class="flex flex-wrap items-end justify-between gap-4">
                    <x-ui.button wire:click="addOptionRow" variant="outline" icon="plus">
                        {{ __('Add another Option') }}
                    </x-ui.button>

                    <div class="w-full sm:w-64">
                        <x-ui.input
                            wire:model="selectionMaximum"
                            :label="__('Claims per participant')"
                            type="number"
                            min="1"
                            :max="max(count($optionRows), 1)"
                            :description="__('Maximum Options one Signup may claim.')"
                        />
                    </div>
                </div>
            </fieldset>

            <div class="flex flex-col-reverse gap-3 border-t border-stone-200 pt-6 dark:border-stone-800 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-stone-600 dark:text-stone-400">
                    {{ __('Not ready? Save a private Draft and finish it later.') }}
                </p>
                <div class="flex flex-col-reverse gap-3 sm:flex-row">
                    <x-ui.button type="submit" variant="outline" class="w-full sm:w-auto">{{ __('Save Draft') }}</x-ui.button>
                    <x-ui.button wire:click="publish" class="w-full sm:w-auto">{{ __('Publish Sheet') }}</x-ui.button>
                </div>
            </div>
        </form>
</div>
