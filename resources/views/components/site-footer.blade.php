@php
    $sourceRef = (string) config('deployment.source.ref');
@endphp

<footer {{ $attributes->class('border-t border-stone-200 bg-stone-50/90 px-4 py-5 text-xs leading-5 text-stone-600 dark:border-stone-800 dark:bg-stone-900/90 dark:text-stone-300') }}>
    <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-center gap-x-4 gap-y-1 text-center">
        <a class="font-semibold underline decoration-stone-400 underline-offset-4 hover:text-teal-700 dark:hover:text-teal-300" href="{{ config('deployment.source.url') }}">
            {{ __('Source for deployed version :ref', ['ref' => substr($sourceRef, 0, 12)]) }}
        </a>
        <a class="font-semibold underline decoration-stone-400 underline-offset-4 hover:text-teal-700 dark:hover:text-teal-300" href="{{ config('deployment.source.license_url') }}" rel="license">
            {{ __('GNU Affero General Public License v3.0 or later') }}
        </a>
        <span>{{ __('No warranty is provided.') }}</span>
    </div>
</footer>
