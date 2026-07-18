<x-layouts::public :title="__('Signup sheet unavailable')" robots="noindex, nofollow">
    <main class="paper-grid flex min-h-svh items-center justify-center px-4 py-12">
        <div class="max-w-md text-center">
            <x-app-logo href="{{ route('home') }}" class="mx-auto justify-center" />
            <h1 class="mt-10 font-display text-4xl font-semibold tracking-tight">{{ __('Signup sheet unavailable') }}</h1>
            <p class="mt-4 leading-7 text-stone-600 dark:text-stone-300">{{ __('This signup sheet is unavailable.') }}</p>
        </div>
    </main>
</x-layouts::public>
