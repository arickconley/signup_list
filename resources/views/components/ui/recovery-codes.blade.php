@props(['codes'])

<div class="space-y-3">
    <x-ui.callout
        variant="warning"
        :heading="__('Save these recovery codes now')"
    >
        {{ __('They will not be shown again after you leave this screen. Each code can restore access once if your authenticator is unavailable.') }}
    </x-ui.callout>

    <div
        class="grid gap-1 rounded-lg bg-stone-100 p-4 font-mono text-sm dark:bg-white/5"
        role="list"
        aria-label="{{ __('Recovery codes') }}"
    >
        @foreach ($codes as $code)
            <div
                role="listitem"
                class="select-text"
                wire:loading.class="opacity-50 animate-pulse"
            >
                {{ $code }}
            </div>
        @endforeach
    </div>
</div>
