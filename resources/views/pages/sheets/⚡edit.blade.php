<?php

use App\Actions\DeleteOwnerOption;
use App\Actions\DuplicateSheet;
use App\Exceptions\CannotDeleteOwnerOption;
use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit Signup Sheet')] class extends Component
{
    public Sheet $sheet;

    public string $title = '';

    public string $description = '';

    public string $eventAt = '';

    public string $location = '';

    public string $deadlineAt = '';

    public string $selectionMaximum = '';

    public string $participationPolicy = Sheet::PARTICIPATION_OPEN;

    public string $nameVisibility = Sheet::VISIBILITY_OWNER_ONLY;

    public string $emailVisibility = Sheet::VISIBILITY_OWNER_ONLY;

    public string $phoneVisibility = Sheet::VISIBILITY_OWNER_ONLY;

    public string $announcement = '';

    public string $optionName = '';

    public string $optionDescription = '';

    public string $optionCapacity = '1';

    public ?int $editingOptionId = null;

    public string $editOptionName = '';

    public string $editOptionDescription = '';

    public string $editOptionCapacity = '';

    #[Locked]
    public ?int $deletingOptionId = null;

    #[Locked]
    public int $deletingOptionClaimCount = 0;

    public function mount(Sheet $sheet): void
    {
        abort_unless($sheet->owner_id === Auth::id(), 404);
        $sheet->refresh();
        abort_if($sheet->isArchived(), 404);

        $this->closePublishedSheetAtDeadline($sheet);

        $this->sheet = $sheet;
        $this->title = $sheet->title;
        $this->description = $sheet->description ?? '';
        $this->eventAt = $sheet->event_at?->timezone($sheet->timezone)->format('Y-m-d\TH:i') ?? '';
        $this->location = $sheet->location ?? '';
        $this->deadlineAt = $sheet->deadline_at->timezone($sheet->timezone)->format('Y-m-d\TH:i');
        $this->selectionMaximum = $sheet->selection_maximum === null
            ? ''
            : (string) $sheet->selection_maximum;
        $this->participationPolicy = $sheet->participation_policy;
        $this->nameVisibility = $sheet->name_visibility ?? Sheet::VISIBILITY_OWNER_ONLY;
        $this->emailVisibility = $sheet->email_visibility ?? Sheet::VISIBILITY_OWNER_ONLY;
        $this->phoneVisibility = $sheet->phone_visibility ?? Sheet::VISIBILITY_OWNER_ONLY;
    }

    public function hydrate(): void
    {
        $this->authorizeOwner();
        $this->closePublishedSheetAtDeadline($this->sheet);
    }

    public function saveDetails(): void
    {
        $this->authorizeOwner();
        $this->normalizeDetails();
        $this->validate($this->detailRules(publishing: false));
        $this->sheet->update($this->detailAttributes());
        $this->sheet->refresh();

        $message = $this->sheet->state === Sheet::STATE_PUBLISHED
            ? __('Published Sheet changes saved.')
            : __('Draft details saved.');

        session()->flash('success', $message);
        $this->announcement = $message;
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

    public function requestOptionDeletion(int $optionId): void
    {
        $this->authorizeOwner();
        abort_unless($this->sheet->state === Sheet::STATE_PUBLISHED, 404);

        $option = $this->sheet->options()->findOrFail($optionId);

        $this->deletingOptionId = $option->id;
        $this->deletingOptionClaimCount = $option->optionClaims()->count();
        $this->announcement = __('Option deletion confirmation requested.');
    }

    public function cancelOptionDeletion(): void
    {
        $this->authorizeOwner();
        $this->reset('deletingOptionId', 'deletingOptionClaimCount');
        $this->announcement = __('Option deletion cancelled.');
    }

    public function confirmOptionDeletion(DeleteOwnerOption $deleteOwnerOption): void
    {
        $this->authorizeOwner();
        abort_unless($this->sheet->state === Sheet::STATE_PUBLISHED, 404);
        abort_if($this->deletingOptionId === null, 404);

        $owner = Auth::user();

        abort_unless($owner instanceof Account, 403);

        $optionId = $this->deletingOptionId;
        $confirmedClaimCount = $this->deletingOptionClaimCount;

        try {
            $deleteOwnerOption->handle($owner, $this->sheet, $optionId, $confirmedClaimCount);
        } catch (CannotDeleteOwnerOption $exception) {
            $this->sheet->refresh();
            $this->selectionMaximum = (string) $this->sheet->selection_maximum;
            $this->reset('deletingOptionId', 'deletingOptionClaimCount');
            $this->addError('optionDeletion', $exception->getMessage());
            $this->announcement = $exception->getMessage();

            return;
        }

        $this->sheet->refresh();
        $this->selectionMaximum = (string) $this->sheet->selection_maximum;
        $this->reset('deletingOptionId', 'deletingOptionClaimCount');
        $this->announcement = __('Option deleted.');
    }

    public function removeOption(int $optionId): void
    {
        $this->authorizeOwner();
        abort_unless($this->sheet->state === Sheet::STATE_DRAFT, 404);

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
        abort_unless($this->sheet->state === Sheet::STATE_DRAFT, 404);
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

    public function closeSheet(): void
    {
        $this->authorizeOwner();

        $closed = Sheet::query()
            ->whereKey($this->sheet->id)
            ->where('owner_id', Auth::id())
            ->where('state', Sheet::STATE_PUBLISHED)
            ->update(['state' => Sheet::STATE_CLOSED]);

        $this->sheet->refresh();

        if ($closed !== 1) {
            return;
        }

        session()->flash('success', __('Signup Sheet closed.'));
        $this->announcement = __('Signup Sheet closed.');
    }

    public function reopenSheet(): void
    {
        $this->authorizeOwner();
        $this->sheet->refresh();
        $this->deadlineAt = trim($this->deadlineAt);
        $this->validate([
            'deadlineAt' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);

        $deadline = Carbon::parse($this->deadlineAt, $this->sheet->timezone)->utc();

        if (! $deadline->isFuture()) {
            $message = __('Choose a future deadline to reopen this Signup Sheet.');
            $this->addError('deadlineAt', $message);
            $this->announcement = $message;

            return;
        }

        $reopened = Sheet::query()
            ->whereKey($this->sheet->id)
            ->where('owner_id', Auth::id())
            ->where('state', Sheet::STATE_CLOSED)
            ->update([
                'state' => Sheet::STATE_PUBLISHED,
                'deadline_at' => $deadline,
            ]);

        $this->sheet->refresh();

        if ($reopened !== 1) {
            return;
        }

        session()->flash('success', __('Signup Sheet reopened.'));
        $this->announcement = __('Signup Sheet reopened.');
    }

    public function archiveSheet(): void
    {
        $this->authorizeOwner();
        $this->sheet->refresh();

        $archived = Sheet::query()
            ->whereKey($this->sheet->id)
            ->where('owner_id', Auth::id())
            ->whereIn('state', [
                Sheet::STATE_PUBLISHED,
                Sheet::STATE_CLOSED,
            ])
            ->update(['state' => Sheet::STATE_ARCHIVED]);

        $this->sheet->refresh();

        if ($archived !== 1) {
            return;
        }

        session()->flash('success', __('Signup Sheet archived. This cannot be undone.'));
        $this->redirectRoute('dashboard', navigate: true);
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
        $this->participationPolicy = trim($this->participationPolicy);
        $this->nameVisibility = trim($this->nameVisibility);
        $this->emailVisibility = trim($this->emailVisibility);
        $this->phoneVisibility = trim($this->phoneVisibility);
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
                $publishing || $this->sheet->state === Sheet::STATE_PUBLISHED ? 'required' : 'nullable',
                'integer',
                'min:1',
                'max:'.$this->sheet->options()->count(),
            ],
            'participationPolicy' => ['required', 'in:'.Sheet::PARTICIPATION_OPEN.','.Sheet::PARTICIPATION_VERIFIED],
            'nameVisibility' => ['required', 'in:'.Sheet::VISIBILITY_OWNER_ONLY.','.Sheet::VISIBILITY_PARTICIPANTS],
            'emailVisibility' => ['required', 'in:'.Sheet::VISIBILITY_OWNER_ONLY.','.Sheet::VISIBILITY_PARTICIPANTS],
            'phoneVisibility' => ['required', 'in:'.Sheet::VISIBILITY_OWNER_ONLY.','.Sheet::VISIBILITY_PARTICIPANTS],
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
            'participation_policy' => $this->participationPolicy,
            'name_visibility' => $this->nameVisibility,
            'email_visibility' => $this->emailVisibility,
            'phone_visibility' => $this->phoneVisibility,
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

        abort_unless(
            $this->sheet->owner_id === Auth::id() && ! $this->sheet->isArchived(),
            404,
        );
    }

    private function closePublishedSheetAtDeadline(Sheet $sheet): void
    {
        Sheet::query()
            ->whereKey($sheet->id)
            ->where('owner_id', Auth::id())
            ->where('state', Sheet::STATE_PUBLISHED)
            ->where('deadline_at', '<=', now())
            ->update(['state' => Sheet::STATE_CLOSED]);
        $sheet->refresh();
    }
};

?>

<x-layouts::app :title="$sheet->title" robots="noindex, nofollow">
    <div class="mx-auto max-w-3xl">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-teal-400">
            {{ match ($sheet->state) {
                Sheet::STATE_PUBLISHED => __('Published Sheet'),
                Sheet::STATE_CLOSED => __('Closed Sheet'),
                Sheet::STATE_ARCHIVED => __('Archived Sheet'),
                default => __('Draft Sheet'),
            } }}
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

            <fieldset class="grid gap-4">
                <legend class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ __('Visibility settings') }}</legend>
                <p class="text-sm text-stone-600 dark:text-stone-400">{{ __('Participants see a field only when this Sheet allows it and that participant consents.') }}</p>

                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach ([
                        'nameVisibility' => __('Participant names'),
                        'emailVisibility' => __('Participant emails'),
                        'phoneVisibility' => __('Participant phones'),
                    ] as $visibilityProperty => $visibilityLabel)
                        <div class="grid gap-2">
                            <label for="{{ $visibilityProperty }}" class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ $visibilityLabel }}</label>
                            <select id="{{ $visibilityProperty }}" wire:model="{{ $visibilityProperty }}" class="block min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-base text-stone-950 shadow-sm outline-none transition focus:border-teal-600 focus:ring-2 focus:ring-teal-600/25 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-50 dark:focus:border-teal-400 dark:focus:ring-teal-400/25 sm:text-sm" @if ($errors->has($visibilityProperty)) aria-invalid="true" aria-describedby="{{ $visibilityProperty }}-error" @endif>
                                <option value="{{ Sheet::VISIBILITY_OWNER_ONLY }}">{{ __('Owner only') }}</option>
                                <option value="{{ Sheet::VISIBILITY_PARTICIPANTS }}">{{ __('Participants with consent') }}</option>
                            </select>
                            @error($visibilityProperty)
                                <p id="{{ $visibilityProperty }}-error" class="text-sm font-medium text-red-700 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>
            </fieldset>

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
                <dd class="mt-1 font-semibold">
                    {{ $sheet->participation_policy === Sheet::PARTICIPATION_VERIFIED
                        ? __('Verified Participation')
                        : __('Open Participation') }}
                </dd>
            </div>

            <div>
                <dt class="text-xs font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Participant visibility') }}</dt>
                <dd class="mt-1 font-semibold">
                    {{ $sheet->name_visibility === Sheet::VISIBILITY_PARTICIPANTS
                        ? __('Participants with consent')
                        : __('Owner only') }}
                </dd>
            </div>

            <div>
                <dt class="text-xs font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Contact visibility') }}</dt>
                <dd class="mt-1 font-semibold">
                    {{ __('Email: :email; Phone: :phone', [
                        'email' => $sheet->email_visibility === Sheet::VISIBILITY_PARTICIPANTS ? __('Participants with consent') : __('Owner only'),
                        'phone' => $sheet->phone_visibility === Sheet::VISIBILITY_PARTICIPANTS ? __('Participants with consent') : __('Owner only'),
                    ]) }}
                </dd>
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
                                    @if ($sheet->state === Sheet::STATE_PUBLISHED)
                                        @php
                                            $remaining = max($option->capacity - $option->claimed_count, 0);
                                            $isOverCapacity = $option->claimed_count > $option->capacity;
                                            $isFull = $option->claimed_count === $option->capacity;
                                        @endphp
                                        <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">
                                            {{ __('Claimed: :claimed · Remaining: :remaining', [
                                                'claimed' => $option->claimed_count,
                                                'remaining' => $remaining,
                                            ]) }}
                                        </p>
                                        <p role="status" @class([
                                            'mt-2 text-sm font-bold',
                                            'text-amber-700 dark:text-amber-400' => $isOverCapacity,
                                            'text-stone-600 dark:text-stone-300' => $isFull,
                                            'text-teal-700 dark:text-teal-400' => ! $isOverCapacity && ! $isFull,
                                        ])>
                                            @if ($isOverCapacity)
                                                {{ __('Over-Capacity — :count over', ['count' => $option->claimed_count - $option->capacity]) }}
                                            @elseif ($isFull)
                                                {{ __('Full — no capacity remaining') }}
                                            @else
                                                {{ trans_choice(':count place available|:count places available', $remaining, ['count' => $remaining]) }}
                                            @endif
                                        </p>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <x-ui.button wire:click="moveOptionUp({{ $option->id }})" variant="ghost" size="sm" :disabled="$loop->first">{{ __('Move up') }}</x-ui.button>
                                    <x-ui.button wire:click="moveOptionDown({{ $option->id }})" variant="ghost" size="sm" :disabled="$loop->last">{{ __('Move down') }}</x-ui.button>
                                    <x-ui.button wire:click="startEditingOption({{ $option->id }})" variant="outline" size="sm">{{ __('Edit') }}</x-ui.button>
                                    @if ($sheet->state === Sheet::STATE_DRAFT)
                                        <x-ui.button wire:click="removeOption({{ $option->id }})" wire:confirm="{{ __('Remove this Option?') }}" variant="danger" size="sm">{{ __('Remove') }}</x-ui.button>
                                    @elseif ($sheet->state === Sheet::STATE_PUBLISHED)
                                        <x-ui.button wire:click="requestOptionDeletion({{ $option->id }})" variant="danger" size="sm">{{ __('Delete') }}</x-ui.button>
                                    @endif
                                </div>
                            </div>
                            @if ($deletingOptionId === $option->id)
                                <x-ui.callout class="mt-4" variant="danger" :heading="__('Delete :name?', ['name' => $option->name])">
                                    <p>{{ trans_choice(
                                        'This will remove :count Option Claim.|This will remove :count Option Claims.',
                                        $deletingOptionClaimCount,
                                        ['count' => $deletingOptionClaimCount],
                                    ) }}</p>
                                    <p class="mt-1">{{ __('This cannot be undone.') }}</p>
                                    <div class="mt-4 flex flex-wrap gap-2">
                                        <x-ui.button wire:click="confirmOptionDeletion" variant="danger" size="sm">{{ __('Delete Option') }}</x-ui.button>
                                        <x-ui.button wire:click="cancelOptionDeletion" variant="outline" size="sm">{{ __('Cancel') }}</x-ui.button>
                                    </div>
                                </x-ui.callout>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="mt-8 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm dark:border-stone-800 dark:bg-stone-900" aria-labelledby="publishing-title">
            @if ($sheet->isPubliclyViewable())
                <h2 id="publishing-title" class="font-display text-2xl font-semibold">{{ __('Shareable link') }}</h2>
                <p class="mt-2 text-sm text-stone-600 dark:text-stone-400">{{ __('Share this link with participants.') }}</p>
                <a href="{{ url('/sheets/'.$sheet->public_id) }}" class="mt-4 inline-flex min-h-11 items-center rounded-lg font-semibold text-teal-700 underline underline-offset-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 dark:text-teal-400">
                    {{ url('/sheets/'.$sheet->public_id) }}
                </a>
                <div class="mt-5 border-t border-stone-200 pt-5 dark:border-stone-800">
                    <a href="{{ route('sheets.signups', $sheet, absolute: false) }}" wire:navigate class="inline-flex min-h-11 items-center justify-center rounded-lg bg-teal-700 px-4 text-sm font-semibold text-white shadow-sm hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 dark:bg-teal-500 dark:text-stone-950 dark:hover:bg-teal-400">
                        {{ __('View Signups') }}
                    </a>
                </div>
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
            @if ($sheet->state === Sheet::STATE_PUBLISHED)
                <p class="mt-2 text-sm text-stone-600 dark:text-stone-400">{{ __('Stop new Signups while keeping the shareable link available to review.') }}</p>
                <x-ui.button wire:click="closeSheet" wire:confirm="{{ __('Close this Signup Sheet?') }}" variant="danger" class="mt-4">{{ __('Close Sheet') }}</x-ui.button>
            @elseif ($sheet->state === Sheet::STATE_CLOSED)
                <p class="mt-2 text-sm text-stone-600 dark:text-stone-400">{{ __('Set a future Signup deadline above before reopening this Sheet.') }}</p>
                <x-ui.button wire:click="reopenSheet" variant="outline" class="mt-4">{{ __('Reopen Sheet') }}</x-ui.button>
            @endif
            @if (in_array($sheet->state, [Sheet::STATE_PUBLISHED, Sheet::STATE_CLOSED], true))
                <p class="mt-4 text-sm text-stone-600 dark:text-stone-400">{{ __('Move this Signup Sheet to archived records. Archiving cannot be undone.') }}</p>
                <x-ui.button wire:click="archiveSheet" wire:confirm="{{ __('Archive this Signup Sheet? This cannot be undone.') }}" variant="danger" class="mt-4">{{ __('Archive Sheet') }}</x-ui.button>
            @endif
            <p class="mt-2 text-sm text-stone-600 dark:text-stone-400">{{ __('Start a new Draft Sheet using this Signup Sheet’s content and settings.') }}</p>
            <x-ui.button wire:click="duplicate" variant="outline" class="mt-4">{{ __('Duplicate Sheet') }}</x-ui.button>
        </section>
    </div>
</x-layouts::app>
