<?php

use App\Actions\RemoveOwnerOptionClaim;
use App\Actions\RemoveOwnerSignup;
use App\Exceptions\CannotRemoveOwnerOptionClaim;
use App\Exceptions\CannotRemoveOwnerSignup;
use App\Models\Account;
use App\Models\Option;
use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app', ['robots' => 'noindex, nofollow'])] #[Title('Signup View')] class extends Component
{
    public Sheet $sheet;

    #[Url(as: 'group', except: 'participant', history: true)]
    public string $grouping = 'participant';

    public string $announcement = '';

    public function mount(Sheet $sheet): void
    {
        abort_unless($sheet->owner_id === Auth::id(), 404);

        $this->sheet = $sheet;

        if (! in_array($this->grouping, ['participant', 'option'], true)) {
            $this->grouping = 'participant';
        }
    }

    public function hydrate(): void
    {
        $this->authorizeOwner();
    }

    public function showParticipantGrouping(): void
    {
        $this->authorizeOwner();
        $this->grouping = 'participant';
        $this->announcement = __('Signup View grouped by Participant.');
    }

    public function showOptionGrouping(): void
    {
        $this->authorizeOwner();
        $this->grouping = 'option';
        $this->announcement = __('Signup View grouped by Option.');
    }

    public function removeOptionClaim(
        int $optionClaimId,
        RemoveOwnerOptionClaim $removeOwnerOptionClaim,
    ): void {
        $this->authorizeOwner();

        $owner = Auth::user();
        abort_unless($owner instanceof Account, 404);

        $optionClaim = OptionClaim::query()
            ->with(['option', 'signup'])
            ->whereHas('signup', function (Builder $query): void {
                $query->where('sheet_id', $this->sheet->id);
            })
            ->whereKey($optionClaimId)
            ->first();

        try {
            $removeOwnerOptionClaim->handle($owner, $this->sheet, $optionClaimId);
        } catch (CannotRemoveOwnerOptionClaim $exception) {
            $this->addError('removal', $exception->getMessage());
            $this->announcement = $exception->getMessage();

            return;
        }

        unset($this->signups, $this->options);

        $this->announcement = __(':option was removed from :participant.', [
            'option' => $optionClaim?->option->name ?? __('The Option Claim'),
            'participant' => $optionClaim?->signup->name_snapshot ?? __('the Signup'),
        ]);
    }

    public function removeSignup(
        int $signupId,
        RemoveOwnerSignup $removeOwnerSignup,
    ): void {
        $this->authorizeOwner();

        $owner = Auth::user();
        abort_unless($owner instanceof Account, 404);

        $signup = Signup::query()
            ->where('sheet_id', $this->sheet->id)
            ->whereKey($signupId)
            ->first();

        try {
            $removeOwnerSignup->handle($owner, $this->sheet, $signupId);
        } catch (CannotRemoveOwnerSignup $exception) {
            $this->addError('removal', $exception->getMessage());
            $this->announcement = $exception->getMessage();

            return;
        }

        unset($this->signups, $this->options);

        $this->announcement = __('The Signup for :participant was removed.', [
            'participant' => $signup?->name_snapshot ?? __('the participant'),
        ]);
    }

    /** @return Collection<int, Signup> */
    #[Computed]
    public function signups(): Collection
    {
        $this->authorizeOwner();

        return $this->sheet->signups()
            ->with(['optionClaims.option', 'pendingAccountAssociation'])
            ->oldest('created_at')
            ->oldest('id')
            ->get();
    }

    /** @return Collection<int, Option> */
    #[Computed]
    public function options(): Collection
    {
        $this->authorizeOwner();

        return $this->sheet->options()
            ->with([
                'optionClaims.signup.optionClaims',
                'optionClaims.signup.pendingAccountAssociation',
            ])
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    private function authorizeOwner(): void
    {
        $this->sheet->refresh();

        abort_unless($this->sheet->owner_id === Auth::id(), 404);
    }
};

?>

<div class="mx-auto max-w-6xl">
    <header class="relative overflow-hidden border-b-2 border-stone-900 pb-7 dark:border-stone-100">
        <div class="paper-grid absolute inset-0 -z-10 opacity-60" aria-hidden="true"></div>
        <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-teal-700 dark:text-teal-400">{{ __('Owner Signup View') }}</p>
                <h1 class="mt-2 text-pretty font-display text-4xl font-semibold tracking-tight sm:text-5xl">{{ $sheet->title }}</h1>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-stone-600 sm:text-base dark:text-stone-400">
                    {{ __('Submitted contact details and current Option Claims are private to you.') }}
                </p>
            </div>
            <a href="{{ route('sheets.edit', $sheet) }}" wire:navigate class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg border border-stone-300 bg-white px-4 text-sm font-semibold shadow-sm hover:border-teal-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 dark:border-stone-700 dark:bg-stone-900 dark:hover:border-teal-400">
                {{ __('Edit Signup Sheet') }}
            </a>
        </div>
    </header>

    <div class="mt-6 flex flex-col gap-4 rounded-2xl border border-stone-200 bg-white p-3 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:p-4 dark:border-stone-800 dark:bg-stone-900">
        <fieldset>
            <legend class="px-1 text-xs font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Group Signups by') }}</legend>
            <div class="mt-2 grid grid-cols-2 gap-1 rounded-xl bg-stone-100 p-1 dark:bg-stone-800" role="group" aria-label="{{ __('Signup grouping') }}">
                <button type="button" wire:click="showParticipantGrouping" wire:loading.attr="disabled" wire:target="showParticipantGrouping,showOptionGrouping" aria-controls="signup-grouping-results" aria-pressed="{{ $grouping === 'participant' ? 'true' : 'false' }}" @class([
                    'min-h-11 rounded-lg px-4 text-sm font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 dark:focus-visible:ring-teal-400 dark:focus-visible:ring-offset-stone-900',
                    'bg-white text-stone-950 shadow-sm dark:bg-stone-950 dark:text-stone-50' => $grouping === 'participant',
                    'text-stone-600 hover:text-stone-950 dark:text-stone-300 dark:hover:text-white' => $grouping !== 'participant',
                ])>
                    {{ __('Participant') }}
                </button>
                <button type="button" wire:click="showOptionGrouping" wire:loading.attr="disabled" wire:target="showParticipantGrouping,showOptionGrouping" aria-controls="signup-grouping-results" aria-pressed="{{ $grouping === 'option' ? 'true' : 'false' }}" @class([
                    'min-h-11 rounded-lg px-4 text-sm font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 dark:focus-visible:ring-teal-400 dark:focus-visible:ring-offset-stone-900',
                    'bg-white text-stone-950 shadow-sm dark:bg-stone-950 dark:text-stone-50' => $grouping === 'option',
                    'text-stone-600 hover:text-stone-950 dark:text-stone-300 dark:hover:text-white' => $grouping !== 'option',
                ])>
                    {{ __('Option') }}
                </button>
            </div>
        </fieldset>

        <div class="min-h-6 text-sm text-stone-600 dark:text-stone-400">
            <p wire:loading.delay role="status" aria-live="polite" wire:target="showParticipantGrouping,showOptionGrouping" class="inline-flex items-center gap-2">
                <x-ui.icon name="loading" class="size-4" />
                {{ __('Updating Signup View…') }}
            </p>
            <p class="sr-only" role="status" aria-live="polite">{{ $announcement }}</p>
        </div>
    </div>

    @error('removal')
        <p role="alert" class="mt-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm font-semibold text-red-900 dark:border-red-800 dark:bg-red-950/50 dark:text-red-200">{{ $message }}</p>
    @enderror

    @php
        $totalCapacity = $this->options->sum('capacity');
        $totalClaimed = $this->options->sum(fn ($option) => $option->optionClaims->count());
        $totalRemaining = $this->options->sum(fn ($option) => max($option->capacity - $option->optionClaims->count(), 0));
    @endphp
    <section class="mt-6 overflow-hidden rounded-2xl border border-stone-200 bg-stone-950 text-stone-50 shadow-sm dark:border-stone-700" aria-labelledby="capacity-overview-title">
        <div class="flex flex-col gap-2 border-b border-stone-700 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="font-mono text-xs font-bold uppercase tracking-[0.16em] text-amber-300">{{ __('Live capacity ledger') }}</p>
                <h2 id="capacity-overview-title" class="mt-1 font-display text-2xl font-semibold">{{ __('Capacity overview') }}</h2>
            </div>
            <p class="text-xs font-medium text-stone-300">{{ __('Counts reflect current Option Claims.') }}</p>
        </div>

        <dl class="grid grid-cols-2 divide-x divide-y divide-stone-700 sm:grid-cols-4 sm:divide-y-0">
            <div class="px-4 py-4 sm:px-5">
                <dt class="text-[0.65rem] font-bold uppercase tracking-[0.14em] text-stone-400">{{ __('Total capacity') }}</dt>
                <dd class="mt-1 font-mono text-3xl font-bold tabular-nums">{{ $totalCapacity }}</dd>
            </div>
            <div class="px-4 py-4 sm:px-5">
                <dt class="text-[0.65rem] font-bold uppercase tracking-[0.14em] text-stone-400">{{ __('Claimed') }}</dt>
                <dd class="mt-1 font-mono text-3xl font-bold tabular-nums">{{ $totalClaimed }}</dd>
            </div>
            <div class="px-4 py-4 sm:px-5">
                <dt class="text-[0.65rem] font-bold uppercase tracking-[0.14em] text-stone-400">{{ __('Remaining') }}</dt>
                <dd class="mt-1 font-mono text-3xl font-bold tabular-nums">{{ $totalRemaining }}</dd>
            </div>
            <div class="px-4 py-4 sm:px-5">
                <dt class="text-[0.65rem] font-bold uppercase tracking-[0.14em] text-stone-400">{{ __('Signups') }}</dt>
                <dd class="mt-1 font-mono text-3xl font-bold tabular-nums">{{ $this->signups->count() }}</dd>
            </div>
        </dl>

        @if ($this->options->isNotEmpty())
            <ol class="divide-y divide-stone-800 border-t border-stone-700">
                @foreach ($this->options as $option)
                    @php
                        $claimed = $option->optionClaims->count();
                        $remaining = max($option->capacity - $claimed, 0);
                        $isOverCapacity = $claimed > $option->capacity;
                        $isFull = $claimed === $option->capacity;
                    @endphp
                    <li wire:key="capacity-option-{{ $option->id }}" class="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                        <div class="min-w-0">
                            <h3 class="truncate font-bold">{{ $option->name }}</h3>
                            <p @class([
                                'mt-1 text-xs font-bold uppercase tracking-[0.12em]',
                                'text-amber-300' => $isOverCapacity,
                                'text-stone-300' => $isFull,
                                'text-teal-300' => ! $isOverCapacity && ! $isFull,
                            ])>
                                @if ($isOverCapacity)
                                    {{ __('Over-Capacity — :count over', ['count' => $claimed - $option->capacity]) }}
                                @elseif ($isFull)
                                    {{ __('Full') }}
                                @else
                                    {{ trans_choice(':count remaining', $remaining, ['count' => $remaining]) }}
                                @endif
                            </p>
                        </div>
                        <dl class="grid grid-cols-3 gap-x-5 text-sm" aria-label="{{ __('Capacity for :option', ['option' => $option->name]) }}">
                            <div>
                                <dt class="text-[0.6rem] font-bold uppercase tracking-wider text-stone-400">{{ __('Total') }}</dt>
                                <dd class="font-mono font-bold tabular-nums">{{ $option->capacity }}</dd>
                            </div>
                            <div>
                                <dt class="text-[0.6rem] font-bold uppercase tracking-wider text-stone-400">{{ __('Claimed') }}</dt>
                                <dd class="font-mono font-bold tabular-nums">{{ $claimed }}</dd>
                            </div>
                            <div>
                                <dt class="text-[0.6rem] font-bold uppercase tracking-wider text-stone-400">{{ __('Remaining') }}</dt>
                                <dd class="font-mono font-bold tabular-nums">{{ $remaining }}</dd>
                            </div>
                        </dl>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    <div id="signup-grouping-results" wire:loading.class="opacity-60" wire:target="showParticipantGrouping,showOptionGrouping" aria-live="polite">
    @if ($grouping === 'participant')
    <section class="mt-8" aria-labelledby="participant-grouping-title">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="font-mono text-xs font-bold uppercase tracking-[0.16em] text-amber-700 dark:text-amber-400">{{ __('Grouped by Participant') }}</p>
                <h2 id="participant-grouping-title" class="mt-2 font-display text-3xl font-semibold tracking-tight">{{ __('Signups') }}</h2>
            </div>
            <p class="text-sm font-medium text-stone-600 dark:text-stone-400">
                {{ trans_choice(':count Signup|:count Signups', $this->signups->count(), ['count' => $this->signups->count()]) }}
            </p>
        </div>

        @if ($this->signups->isEmpty())
            <div class="paper-grid mt-5 rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-6 py-14 text-center dark:border-stone-700 dark:bg-stone-900/60">
                <p class="font-display text-2xl font-semibold">{{ __('No Signups yet') }}</p>
                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-stone-600 dark:text-stone-400">{{ __('New Signups will appear here with their submitted details and Option Claims.') }}</p>
            </div>
        @else
            <ol class="mt-5 grid gap-4 lg:grid-cols-2">
                @foreach ($this->signups as $signup)
                    @php
                        $claims = $signup->optionClaims->sortBy('option.position')->values();
                        $isOverLimit = $sheet->selection_maximum !== null && $claims->count() > $sheet->selection_maximum;
                    @endphp
                    <li wire:key="participant-signup-{{ $signup->id }}" class="relative overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm dark:border-stone-800 dark:bg-stone-900">
                        <article aria-labelledby="signup-{{ $signup->id }}-name">
                            <header class="border-b border-stone-200 px-5 py-5 dark:border-stone-800">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 id="signup-{{ $signup->id }}-name" class="font-display text-2xl font-semibold">{{ $signup->name_snapshot }}</h3>
                                        <p class="mt-2 inline-flex rounded-full bg-stone-100 px-2.5 py-1 text-xs font-bold uppercase tracking-[0.12em] text-stone-700 dark:bg-stone-800 dark:text-stone-200">
                                            @if ($signup->pendingAccountAssociation !== null)
                                                {{ __('Pending Account Association') }}
                                            @elseif ($signup->account_id !== null)
                                                {{ __('Attached Account') }}
                                            @else
                                                {{ __('Unregistered Participant') }}
                                            @endif
                                        </p>
                                    </div>
                                    @if ($isOverLimit)
                                        <p role="status" class="rounded-md border border-amber-400 bg-amber-50 px-2.5 py-1.5 text-xs font-bold text-amber-950 dark:border-amber-500 dark:bg-amber-950/60 dark:text-amber-200">
                                            {{ __('Over limit — :claimed of :maximum maximum', ['claimed' => $claims->count(), 'maximum' => $sheet->selection_maximum]) }}
                                        </p>
                                    @endif
                                </div>
                            </header>

                            <div class="grid gap-6 px-5 py-5 sm:grid-cols-2">
                                <section aria-labelledby="signup-{{ $signup->id }}-contact-title">
                                    <h4 id="signup-{{ $signup->id }}-contact-title" class="text-xs font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Submitted contact details') }}</h4>
                                    <dl class="mt-3 space-y-3 text-sm">
                                        <div>
                                            <dt class="font-semibold text-stone-500 dark:text-stone-400">{{ __('Email') }}</dt>
                                            <dd class="mt-0.5 break-words font-medium">{{ $signup->email_snapshot ?? __('Not submitted') }}</dd>
                                        </div>
                                        <div>
                                            <dt class="font-semibold text-stone-500 dark:text-stone-400">{{ __('Phone') }}</dt>
                                            <dd class="mt-0.5 break-words font-medium">{{ $signup->phone_snapshot ?? __('Not submitted') }}</dd>
                                        </div>
                                    </dl>
                                </section>

                                <section aria-labelledby="signup-{{ $signup->id }}-claims-title">
                                    <h4 id="signup-{{ $signup->id }}-claims-title" class="text-xs font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Option Claims') }}</h4>
                                    @if ($claims->isEmpty())
                                        <p class="mt-3 text-sm text-stone-600 dark:text-stone-400">{{ __('No current Option Claims.') }}</p>
                                    @else
                                        <ul class="mt-3 space-y-2">
                                            @foreach ($claims as $claim)
                                                <li class="flex items-center justify-between gap-3 text-sm font-semibold">
                                                    <span class="flex min-w-0 items-start gap-2">
                                                        <span class="mt-1 flex size-4 shrink-0 items-center justify-center rounded-full bg-teal-100 text-[0.65rem] text-teal-900 dark:bg-teal-900 dark:text-teal-100" aria-hidden="true">✓</span>
                                                        <span>{{ $claim->option->name }}</span>
                                                    </span>
                                                    <x-ui.button
                                                        wire:click="removeOptionClaim({{ $claim->id }})"
                                                        wire:confirm="{{ __('Remove :option from :participant? This releases one capacity unit.', ['option' => $claim->option->name, 'participant' => $signup->name_snapshot]) }}"
                                                        wire:loading.attr="disabled"
                                                        wire:target="removeOptionClaim({{ $claim->id }})"
                                                        variant="danger"
                                                        size="sm"
                                                    >
                                                        {{ __('Remove') }}
                                                    </x-ui.button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </section>
                            </div>
                            <footer class="flex justify-end border-t border-stone-200 px-5 py-4 dark:border-stone-800">
                                <x-ui.button
                                    wire:click="removeSignup({{ $signup->id }})"
                                    wire:confirm="{{ __('Remove the entire Signup for :participant? This releases all claimed capacity and cannot be undone.', ['participant' => $signup->name_snapshot]) }}"
                                    wire:loading.attr="disabled"
                                    wire:target="removeSignup({{ $signup->id }})"
                                    variant="danger"
                                    size="sm"
                                >
                                    {{ __('Remove entire Signup') }}
                                </x-ui.button>
                            </footer>
                        </article>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
    @else
        <section class="mt-8" aria-labelledby="option-grouping-title">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="font-mono text-xs font-bold uppercase tracking-[0.16em] text-amber-700 dark:text-amber-400">{{ __('Grouped by Option') }}</p>
                    <h2 id="option-grouping-title" class="mt-2 font-display text-3xl font-semibold tracking-tight">{{ __('Options and claiming Signups') }}</h2>
                </div>
                <p class="text-sm font-medium text-stone-600 dark:text-stone-400">
                    {{ trans_choice(':count Option|:count Options', $this->options->count(), ['count' => $this->options->count()]) }}
                </p>
            </div>

            @if ($this->options->isEmpty())
                <div class="paper-grid mt-5 rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-6 py-14 text-center dark:border-stone-700 dark:bg-stone-900/60">
                    <p class="font-display text-2xl font-semibold">{{ __('No Options yet') }}</p>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-stone-600 dark:text-stone-400">{{ __('Add an Option before collecting Signups.') }}</p>
                </div>
            @else
                <ol class="mt-5 space-y-5">
                    @foreach ($this->options as $option)
                        <li wire:key="grouped-option-{{ $option->id }}" class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm dark:border-stone-800 dark:bg-stone-900">
                            <article aria-labelledby="grouped-option-{{ $option->id }}-name">
                                <header class="border-b border-stone-200 bg-stone-50 px-5 py-4 dark:border-stone-800 dark:bg-stone-900/80">
                                    <h3 id="grouped-option-{{ $option->id }}-name" class="font-display text-2xl font-semibold">{{ $option->name }}</h3>
                                </header>

                                @if ($option->optionClaims->isEmpty())
                                    <p class="px-5 py-6 text-sm text-stone-600 dark:text-stone-400">{{ __('No Signups claim this Option.') }}</p>
                                @else
                                    <ol class="divide-y divide-stone-200 dark:divide-stone-800">
                                        @foreach ($option->optionClaims->sortBy('id') as $claim)
                                            @php
                                                $claimingSignup = $claim->signup;
                                                $claimingSignupCount = $claimingSignup->optionClaims->count();
                                                $claimingSignupIsOverLimit = $sheet->selection_maximum !== null && $claimingSignupCount > $sheet->selection_maximum;
                                            @endphp
                                            <li wire:key="option-{{ $option->id }}-signup-{{ $claimingSignup->id }}" class="grid gap-4 px-5 py-5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.25fr)] sm:items-start">
                                                <div>
                                                    <h4 class="text-lg font-bold">{{ $claimingSignup->name_snapshot }}</h4>
                                                    <p class="mt-1 text-xs font-bold uppercase tracking-[0.12em] text-stone-500 dark:text-stone-400">
                                                        @if ($claimingSignup->pendingAccountAssociation !== null)
                                                            {{ __('Pending Account Association') }}
                                                        @elseif ($claimingSignup->account_id !== null)
                                                            {{ __('Attached Account') }}
                                                        @else
                                                            {{ __('Unregistered Participant') }}
                                                        @endif
                                                    </p>
                                                    @if ($claimingSignupIsOverLimit)
                                                        <p role="status" class="mt-2 inline-flex rounded-md border border-amber-400 bg-amber-50 px-2.5 py-1.5 text-xs font-bold text-amber-950 dark:border-amber-500 dark:bg-amber-950/60 dark:text-amber-200">
                                                            {{ __('Over limit — :claimed of :maximum maximum', ['claimed' => $claimingSignupCount, 'maximum' => $sheet->selection_maximum]) }}
                                                        </p>
                                                    @endif
                                                </div>
                                                <div>
                                                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                                                        <div>
                                                            <dt class="font-semibold text-stone-500 dark:text-stone-400">{{ __('Submitted email') }}</dt>
                                                            <dd class="mt-0.5 break-words font-medium">{{ $claimingSignup->email_snapshot ?? __('Not submitted') }}</dd>
                                                        </div>
                                                        <div>
                                                            <dt class="font-semibold text-stone-500 dark:text-stone-400">{{ __('Submitted phone') }}</dt>
                                                            <dd class="mt-0.5 break-words font-medium">{{ $claimingSignup->phone_snapshot ?? __('Not submitted') }}</dd>
                                                        </div>
                                                    </dl>
                                                    <div class="mt-4 flex justify-end">
                                                        <x-ui.button
                                                            wire:click="removeOptionClaim({{ $claim->id }})"
                                                            wire:confirm="{{ __('Remove :option from :participant? This releases one capacity unit.', ['option' => $option->name, 'participant' => $claimingSignup->name_snapshot]) }}"
                                                            wire:loading.attr="disabled"
                                                            wire:target="removeOptionClaim({{ $claim->id }})"
                                                            variant="danger"
                                                            size="sm"
                                                        >
                                                            {{ __('Remove claim') }}
                                                        </x-ui.button>
                                                    </div>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif
                            </article>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    @endif
    </div>
</div>
