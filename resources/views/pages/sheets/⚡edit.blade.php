<?php

use App\Models\Sheet;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit Draft Sheet')] class extends Component
{
    public Sheet $sheet;

    public function mount(Sheet $sheet): void
    {
        abort_unless($sheet->owner_id === Auth::id(), 404);

        $this->sheet = $sheet;
    }
};

?>

<x-layouts::app :title="$sheet->title">
    <div class="mx-auto max-w-3xl">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-teal-400">{{ __('Draft Sheet') }}</p>
        <h1 class="mt-2 font-display text-4xl font-semibold tracking-tight">{{ $sheet->title }}</h1>

        @if ($sheet->description)
            <p class="mt-4 whitespace-pre-line text-stone-600 dark:text-stone-300">{{ $sheet->description }}</p>
        @endif

        <dl class="mt-8 grid gap-4 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm sm:grid-cols-2 dark:border-stone-800 dark:bg-stone-900">
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
                <dt class="text-xs font-bold uppercase tracking-[0.16em] text-stone-500 dark:text-stone-400">{{ __('Participant details') }}</dt>
                <dd class="mt-1 font-semibold">{{ __('Owner only') }}</dd>
            </div>
        </dl>
    </div>
</x-layouts::app>
