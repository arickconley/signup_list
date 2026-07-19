@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'value' => null,
    'viewable' => false,
    'description' => null,
])

@php
    $wireModelAttribute = collect($attributes->getAttributes())
        ->keys()
        ->first(fn (string $key) => str_starts_with($key, 'wire:model'));
    $errorKey = $name ?: ($wireModelAttribute ? $attributes->get($wireModelAttribute) : null);
    $inputId = $attributes->get('id', $name ?: ($errorKey ? str_replace('.', '-', $errorKey) : 'field-'.uniqid()));
    $hasError = $errorKey && $errors->has($errorKey);
    $describedBy = collect([
        $attributes->get('aria-describedby'),
        $description ? $inputId.'-description' : null,
        $hasError ? $inputId.'-error' : null,
    ])->filter()->implode(' ');
@endphp

<div class="grid gap-2" @if ($viewable) x-data="{ visible: false }" @endif>
    @if ($label)
        <label for="{{ $inputId }}" class="text-sm font-semibold text-stone-800 dark:text-stone-100">
            {{ $label }}
        </label>
    @endif

    <div class="relative">
        <input
            id="{{ $inputId }}"
            @if ($name) name="{{ $name }}" @endif
            type="{{ $type }}"
            @if ($viewable) x-bind:type="visible ? 'text' : 'password'" @endif
            @if (! is_null($value)) value="{{ $value }}" @endif
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except(['id', 'aria-describedby'])->class([
                'block min-h-11 w-full rounded-lg border bg-white px-3 py-2 text-base text-stone-950 shadow-sm outline-none transition placeholder:text-stone-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/25 dark:bg-stone-900 dark:text-stone-50 dark:placeholder:text-stone-500 dark:focus:border-teal-400 dark:focus:ring-teal-400/25 sm:text-sm',
                'pe-11' => $viewable,
                'border-red-500' => $hasError,
                'border-stone-300 dark:border-stone-700' => ! $hasError,
            ]) }}
        />

        @if ($viewable)
            <button
                type="button"
                class="absolute inset-y-0 end-0 flex min-w-11 items-center justify-center rounded-e-lg text-stone-500 hover:text-stone-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-teal-600 dark:text-stone-400 dark:hover:text-stone-100"
                x-on:click="visible = ! visible"
                x-bind:aria-label="visible ? @js(__('Hide password')) : @js(__('Show password'))"
            >
                <x-ui.icon name="eye" class="size-5" x-show="!visible" />
                <x-ui.icon name="eye-slash" class="size-5" x-show="visible" x-cloak />
            </button>
        @endif
    </div>

    @if ($description)
        <p id="{{ $inputId }}-description" class="text-sm text-stone-600 dark:text-stone-400">{{ $description }}</p>
    @endif

    @if ($hasError)
        <p id="{{ $inputId }}-error" class="text-sm font-medium text-red-700 dark:text-red-400">
            {{ $errors->first($errorKey) }}
        </p>
    @endif
</div>
