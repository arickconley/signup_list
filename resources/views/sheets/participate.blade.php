<x-layouts::public :title="__('Participate in :sheet', ['sheet' => $sheet->title])" robots="noindex, nofollow" :include-livewire="true">
    <main class="paper-grid min-h-svh px-4 py-10 sm:px-6">
        <section class="mx-auto max-w-3xl border border-stone-300 bg-amber-50/95 p-6 shadow-xl sm:p-10 dark:border-stone-700 dark:bg-stone-900">
            <p class="font-mono text-xs font-bold uppercase tracking-[0.16em] text-teal-800 dark:text-teal-300">{{ __('Verified Participation') }}</p>
            <h1 class="mt-2 font-display text-4xl font-semibold tracking-tight">{{ $sheet->title }}</h1>
            <livewire:complete-verified-signup :sheet-public-id="$sheet->public_id" />
        </section>
    </main>
</x-layouts::public>
