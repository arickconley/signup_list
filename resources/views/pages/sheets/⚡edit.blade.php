<?php

use App\Actions\DuplicateSheet;
use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit Draft Sheet')] class extends Component
{
    public Sheet $sheet;

    public string $title = '';

    public string $description = '';

    public string $eventAt = '';

    public string $location = '';

    public string $deadlineAt = '';

    public string $selectionMaximum = '';

    public string $announcement = '';

    public string $optionName = '';

    public string $optionDescription = '';

    public string $optionCapacity = '1';

    public ?int $editingOptionId = null;

    public string $editOptionName = '';

    public string $editOptionDescription = '';

    public string $editOptionCapacity = '';

    public function mount(Sheet $sheet): void
    {
        abort_unless($sheet->owner_id === Auth::id(), 404);

        $this->sheet = $sheet;
        $this->title = $sheet->title;
        $this->description = $sheet->description ?? '';
        $this->eventAt = $sheet->event_at?->timezone($sheet->timezone)->format('Y-m-d\TH:i') ?? '';
        $this->location = $sheet->location ?? '';
        $this->deadlineAt = $sheet->deadline_at->timezone($sheet->timezone)->format('Y-m-d\TH:i');
        $this->selectionMaximum = $sheet->selection_maximum === null
            ? ''
            : (string) $sheet->selection_maximum;
    }

    public function hydrate(): void
    {
        $this->authorizeOwner();
    }

    public function saveDetails(): void
    {
        $this->authorizeOwner();
        $this->normalizeDetails();
        $this->validate($this->detailRules(publishing: false));
        $this->sheet->update($this->detailAttributes());
        $this->sheet->refresh();

        session()->flash('success', __('Draft details saved.'));
        $this->announcement = __('Draft details saved.');
    }

    public function addOption(): void
    {
        $this->authorizeOwner();

        $this->optionName = trim($this->optionName);
        $this->optionDescription = trim($this->optionDescription);
        $this->optionCapacity = trim($this->optionCapacity);

        $this->validate([
            'optionName' => ['required', 'string', 'max:255'],
            'optionDescription' => ['nullable', 'string', 'max:1000'],
            'optionCapacity' => ['required', 'integer', 'min:1'],
        ]);

        $this->sheet->options()->create([
            'name' => $this->optionName,
            'description' => $this->optionDescription === '' ? null : $this->optionDescription,
            'capacity' => (int) $this->optionCapacity,
            'position' => ((int) $this->sheet->options()->max('position')) + 1,
        ]);

        $this->reset('optionName', 'optionDescription');
        $this->optionCapacity = '1';
        $this->announcement = __('Option added.');
    }

    public function startEditingOption(int $optionId): void
    {
        $this->authorizeOwner();

        $option = $this->sheet->options()->findOrFail($optionId);

        $this->editingOptionId = $option->id;
        $this->editOptionName = $option->name;
        $this->editOptionDescription = $option->description ?? '';
        $this->editOptionCapacity = (string) $option->capacity;
    }

    public function updateOption(): void
    {
        $this->authorizeOwner();

        abort_if($this->editingOptionId === null, 404);

        $this->editOptionName = trim($this->editOptionName);
        $this->editOptionDescription = trim($this->editOptionDescription);
        $this->editOptionCapacity = trim($this->editOptionCapacity);

        $this->validate([
            'editOptionName' => ['required', 'string', 'max:255'],
            'editOptionDescription' => ['nullable', 'string', 'max:1000'],
            'editOptionCapacity' => ['required', 'integer', 'min:1'],
        ]);

        $option = $this->sheet->options()->findOrFail($this->editingOptionId);
        $option->update([
            'name' => $this->editOptionName,
            'description' => $this->editOptionDescription === '' ? null : $this->editOptionDescription,
            'capacity' => (int) $this->editOptionCapacity,
        ]);

        $this->cancelEditingOption();
        $this->announcement = __('Option updated.');
    }

    public function cancelEditingOption(): void
    {
        $this->reset('editingOptionId', 'editOptionName', 'editOptionDescription', 'editOptionCapacity');
        $this->resetValidation([
            'editOptionName',
            'editOptionDescription',
            'editOptionCapacity',
        ]);
        $this->announcement = __('Option editing cancelled.');
    }

    public function removeOption(int $optionId): void
    {
        $this->authorizeOwner();

        $option = $this->sheet->options()->findOrFail($optionId);

        DB::transaction(function () use ($option): void {
            $option->delete();

            $remainingOptions = $this->sheet->options()
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            foreach ($remainingOptions as $index => $remainingOption) {
                $remainingOption->update(['position' => $index + 1]);
            }

            if (
                $this->sheet->selection_maximum !== null
                && $this->sheet->selection_maximum > $remainingOptions->count()
            ) {
                $this->sheet->update([
                    'selection_maximum' => $remainingOptions->isEmpty()
                        ? null
                        : $remainingOptions->count(),
                ]);
            }
        });

        $this->sheet->refresh();
        $this->selectionMaximum = $this->sheet->selection_maximum === null
            ? ''
            : (string) $this->sheet->selection_maximum;

        if ($this->editingOptionId === $optionId) {
            $this->cancelEditingOption();
        }

        $this->announcement = __('Option removed.');
    }

    public function moveOptionUp(int $optionId): void
    {
        $this->moveOption($optionId, -1);
    }

    public function moveOptionDown(int $optionId): void
    {
        $this->moveOption($optionId, 1);
    }

    public function publish(): void
    {
        $this->authorizeOwner();
        $this->normalizeDetails();

        $this->withValidator(function (Validator $validator): void {
            $validator->after(function (Validator $validator): void {
                $options = $this->sheet->options()->get(['name', 'capacity']);
                $hasInvalidOption = $options->contains(
                    fn ($option): bool => trim($option->name) === '' || $option->capacity < 1,
                );

                if ($options->isEmpty() || $hasInvalidOption) {
                    $validator->errors()->add('options', __('Add at least one valid Option before publishing.'));
                }
            });
        })->validate($this->detailRules(publishing: true));

        DB::transaction(function (): void {
            $this->sheet->update([
                ...$this->detailAttributes(),
                'state' => Sheet::STATE_PUBLISHED,
            ]);
        });
        $this->sheet->refresh();

        session()->flash('success', __('Signup Sheet published.'));
        $this->announcement = __('Signup Sheet published.');
    }

    public function duplicate(DuplicateSheet $duplicateSheet): void
    {
        $this->authorizeOwner();

        $owner = Auth::user();

        abort_unless($owner instanceof Account, 403);

        $duplicate = $duplicateSheet->handle($owner, $this->sheet);

        session()->flash('success', __('Signup Sheet duplicated into a new Draft Sheet.'));

        $this->redirectRoute('sheets.edit', $duplicate, navigate: true);
    }

    private function normalizeDetails(): void
    {
        $this->title = trim($this->title);
        $this->description = trim($this->description);
        $this->eventAt = trim($this->eventAt);
        $this->location = trim($this->location);
        $this->deadlineAt = trim($this->deadlineAt);
        $this->selectionMaximum = trim($this->selectionMaximum);
    }

    /** @return array<string, array<int, string>> */
    private function detailRules(bool $publishing): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'eventAt' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'location' => ['nullable', 'string', 'max:255'],
            'deadlineAt' => ['required', 'date_format:Y-m-d\TH:i'],
            'selectionMaximum' => [
                $publishing ? 'required' : 'nullable',
                'integer',
                'min:1',
                'max:'.$this->sheet->options()->count(),
            ],
        ];
    }

    /** @return array<string, Carbon|int|string|null> */
    private function detailAttributes(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description === '' ? null : $this->description,
            'event_at' => $this->eventAt === ''
                ? null
                : Carbon::parse($this->eventAt, $this->sheet->timezone)->utc(),
            'location' => $this->location === '' ? null : $this->location,
            'deadline_at' => Carbon::parse($this->deadlineAt, $this->sheet->timezone)->utc(),
            'selection_maximum' => $this->selectionMaximum === '' ? null : (int) $this->selectionMaximum,
        ];
    }

    private function moveOption(int $optionId, int $offset): void
    {
        $this->authorizeOwner();

        $options = $this->sheet->options()
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $currentIndex = $options->search(fn ($option): bool => $option->id === $optionId);

        abort_if($currentIndex === false, 404);

        $targetIndex = max(0, min($options->count() - 1, $currentIndex + $offset));

        if ($targetIndex === $currentIndex) {
            return;
        }

        $movingOption = $options->pull($currentIndex);
        $options = $options->values();
        $options->splice($targetIndex, 0, [$movingOption]);

        DB::transaction(function () use ($options): void {
            foreach ($options as $index => $option) {
                $option->update(['position' => $index + 1]);
            }
        });

        $this->announcement = __('Option moved.');
    }

    private function authorizeOwner(): void
    {
        $this->sheet->refresh();

        abort_unless($this->sheet->owner_id === Auth::id(), 404);
    }
};

?>

<x-layouts::app :title="$sheet->title">
    <div class="mx-auto max-w-3xl">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-teal-400">
            {{ $sheet->state === Sheet::STATE_PUBLISHED ? __('Published Sheet') : __('Draft Sheet') }}
        </p>
        <h1 class="mt-2 font-display text-4xl font-semibold tracking-tight">{{ $sheet->title }}</h1>

        <p class="sr-only" role="status" aria-live="polite">{{ $announcement }}</p>

        @if ($errors->any())
            <x-ui.callout class="mt-6" variant="danger" :heading="__('Please correct the highlighted fields.')">
                <ul class="mt-2 list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.callout>
        @endif

        <form wire:submit="saveDetails" class="mt-8 grid gap-6 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm dark:border-stone-800 dark:bg-stone-900">
            <h2 class="font-display text-2xl font-semibold">{{ __('Sheet details') }}</h2>

            <x-ui.input wire:model="title" :label="__('Title')" type="text" required />

            <div class="grid gap-2">
                <label for="description" class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ __('Description') }}</label>
                <textarea id="description" wire:model="description" rows="4" class="block w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-base text-stone-950 shadow-sm outline-none transition focus:border-teal-600 focus:ring-2 focus:ring-teal-600/25 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-50 dark:focus:border-teal-400 dark:focus:ring-teal-400/25 sm:text-sm" @if ($errors->has('description')) aria-invalid="true" aria-describedby="description-error" @endif></textarea>
                @error('description')
                    <p id="description-error" class="text-sm font-medium text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <x-ui.input wire:model="eventAt" :label="__('Event date and time')" type="datetime-local" :description="__('Optional. Uses the Sheet timezone.')" />
                <x-ui.input wire:model="location" :label="__('Location')" type="text" :description="__('Optional.')" />
                <x-ui.input wire:model="deadlineAt" :label="__('Signup deadline')" type="datetime-local" required />
                <x-ui.input wire:model="selectionMaximum" :label="__('Selection maximum')" type="number" min="1" :description="__('Maximum Options each Signup may claim.')" />
            </div>

            <div>
                <x-ui.button type="submit">{{ __('Save details') }}</x-ui.button>
            </div>
        </form>

        <section class="mt-8" aria-labelledby="preview-title">
            <h2 id="preview-title" class="font-display text-2xl font-semibold">{{ __('Preview') }}</h2>

            <div class="mt-4 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm dark:border-stone-800 dark:bg-stone-900">
                <h3 class="font-display text-3xl font-semibold">{{ $sheet->title }}</h3>
                @if ($sheet->description)
                    <p class="mt-4 whitespace-pre-line text-stone-600 dark:text-stone-300">{{ $sheet->description }}</p>
                @endif

        <dl class="mt-6 grid gap-4 sm:grid-cols-2">
            @if ($sheet->event_at)
                <div>
                    <dt class="text-xs font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Event') }}</dt>
                    <dd class="mt-1 font-semibold">{{ $sheet->event_at->timezone($sheet->timezone)->format('M j, Y g:i A T') }}</dd>
                </div>
            @endif

            @if ($sheet->location)
                <div>
                    <dt class="text-xs font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Location') }}</dt>
                    <dd class="mt-1 font-semibold">{{ $sheet->location }}</dd>
                </div>
            @endif

            <div>
                <dt class="text-xs font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Signup deadline') }}</dt>
                <dd class="mt-1 font-semibold">{{ $sheet->deadline_at->timezone($sheet->timezone)->format('M j, Y g:i A T') }}</dd>
            </div>

            <div>
                <dt class="text-xs font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Participation') }}</dt>
                <dd class="mt-1 font-semibold">{{ __('Open Participation') }}</dd>
            </div>

            <div>
                <dt class="text-xs font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Participant visibility') }}</dt>
                <dd class="mt-1 font-semibold">{{ __('Owner only') }}</dd>
            </div>

            <div>
                <dt class="text-xs font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Contact visibility') }}</dt>
                <dd class="mt-1 font-semibold">{{ __('Owner only') }}</dd>
            </div>

            @if ($sheet->selection_maximum)
                <div>
                    <dt class="text-xs font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Selection maximum') }}</dt>
                    <dd class="mt-1 font-semibold">{{ $sheet->selection_maximum }}</dd>
                </div>
            @endif
        </dl>
            </div>
        </section>

        <section class="mt-8" aria-labelledby="options-title">
            <h2 id="options-title" class="font-display text-2xl font-semibold">{{ __('Options') }}</h2>

            <form wire:submit="addOption" class="mt-4 grid gap-4 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm dark:border-stone-800 dark:bg-stone-900">
                <x-ui.input wire:model="optionName" :label="__('Option name')" type="text" required />
                <x-ui.input wire:model="optionDescription" :label="__('Short description')" type="text" :description="__('Optional.')" />
                <x-ui.input wire:model="optionCapacity" :label="__('Capacity')" type="number" min="1" required />

                <div>
                    <x-ui.button type="submit">{{ __('Add Option') }}</x-ui.button>
                </div>
            </form>

            <ol class="mt-4 space-y-3">
                @foreach ($sheet->options()->orderBy('position')->get() as $option)
                    <li wire:key="option-{{ $option->id }}" class="rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
                        @if ($editingOptionId === $option->id)
                            <form wire:submit="updateOption" class="grid gap-4">
                                <x-ui.input wire:model="editOptionName" :label="__('Option name')" type="text" required />
                                <x-ui.input wire:model="editOptionDescription" :label="__('Short description')" type="text" :description="__('Optional.')" />
                                <x-ui.input wire:model="editOptionCapacity" :label="__('Capacity')" type="number" min="1" required />

                                <div class="flex flex-wrap gap-2">
                                    <x-ui.button type="submit" size="sm">{{ __('Save Option') }}</x-ui.button>
                                    <x-ui.button wire:click="cancelEditingOption" variant="ghost" size="sm">{{ __('Cancel') }}</x-ui.button>
                                </div>
                            </form>
                        @else
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h3 class="font-display text-lg font-semibold">{{ $option->name }}</h3>
                                    @if ($option->description)
                                        <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ $option->description }}</p>
                                    @endif
                                    <p class="mt-2 text-sm font-semibold">{{ __('Capacity: :capacity', ['capacity' => $option->capacity]) }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <x-ui.button wire:click="moveOptionUp({{ $option->id }})" variant="ghost" size="sm" :disabled="$loop->first">{{ __('Move up') }}</x-ui.button>
                                    <x-ui.button wire:click="moveOptionDown({{ $option->id }})" variant="ghost" size="sm" :disabled="$loop->last">{{ __('Move down') }}</x-ui.button>
                                    <x-ui.button wire:click="startEditingOption({{ $option->id }})" variant="outline" size="sm">{{ __('Edit') }}</x-ui.button>
                                    <x-ui.button wire:click="removeOption({{ $option->id }})" wire:confirm="{{ __('Remove this Option?') }}" variant="danger" size="sm">{{ __('Remove') }}</x-ui.button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="mt-8 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm dark:border-stone-800 dark:bg-stone-900" aria-labelledby="publishing-title">
            @if ($sheet->state === Sheet::STATE_PUBLISHED)
                <h2 id="publishing-title" class="font-display text-2xl font-semibold">{{ __('Shareable link') }}</h2>
                <p class="mt-2 text-sm text-stone-600 dark:text-stone-400">{{ __('Share this link with participants.') }}</p>
                <a href="{{ url('/sheets/'.$sheet->public_id) }}" class="mt-4 inline-flex min-h-11 items-center rounded-lg font-semibold text-teal-700 underline underline-offset-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 dark:text-teal-400">
                    {{ url('/sheets/'.$sheet->public_id) }}
                </a>
            @else
                <h2 id="publishing-title" class="font-display text-2xl font-semibold">{{ __('Ready to publish?') }}</h2>
                <p class="mt-2 text-sm text-stone-600 dark:text-stone-400">{{ __('Publishing makes the shareable link available.') }}</p>
                @error('options')
                    <p id="publishing-options-error" role="alert" class="mt-3 text-sm font-medium text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror
                <x-ui.button wire:click="publish" class="mt-4" :aria-describedby="$errors->has('options') ? 'publishing-options-error' : null">{{ __('Publish Signup Sheet') }}</x-ui.button>
            @endif
        </section>

        <section class="mt-8 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm dark:border-stone-800 dark:bg-stone-900" aria-labelledby="sheet-actions-title">
            <h2 id="sheet-actions-title" class="font-display text-2xl font-semibold">{{ __('Sheet actions') }}</h2>
            <p class="mt-2 text-sm text-stone-600 dark:text-stone-400">{{ __('Start a new Draft Sheet using this Signup Sheet’s content and settings.') }}</p>
            <x-ui.button wire:click="duplicate" variant="outline" class="mt-4">{{ __('Duplicate Sheet') }}</x-ui.button>
        </section>
    </div>
</x-layouts::app>
