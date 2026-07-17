<x-layouts::app :title="__('Dashboard')">
    <div class="mx-auto max-w-5xl">
        <div class="flex flex-col gap-4 border-b border-stone-200 pb-6 sm:flex-row sm:items-end sm:justify-between dark:border-stone-800">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-teal-400">{{ __('Your workspace') }}</p>
                <h1 class="mt-2 font-display text-4xl font-semibold tracking-tight">{{ __('Signup sheets') }}</h1>
                <p class="mt-2 max-w-xl text-stone-600 dark:text-stone-400">{{ __('Create a sheet, share its private link, and keep every contribution organized.') }}</p>
            </div>
        </div>

        <section class="paper-grid mt-8 rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-6 py-14 text-center dark:border-stone-700 dark:bg-stone-900/60" aria-labelledby="empty-sheets-title">
            <span class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-amber-200 text-amber-950 shadow-sm dark:bg-amber-300">
                <x-app-logo-icon class="size-8" />
            </span>
            <h2 id="empty-sheets-title" class="mt-5 font-display text-2xl font-semibold">{{ __('No signup sheets yet') }}</h2>
            <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-stone-600 dark:text-stone-400">{{ __('Your owned and joined sheets will appear here once sheet creation is available.') }}</p>
        </section>
    </div>
</x-layouts::app>
