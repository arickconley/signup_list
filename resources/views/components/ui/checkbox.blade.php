@props([
    'label',
    'name',
    'checked' => false,
    'id' => null,
    'value' => '1',
    'variant' => 'default',
])

<label @if ($id !== null) for="{{ $id }}" @endif @class([
    'flex min-h-11 cursor-pointer items-center gap-3 text-sm text-stone-700 dark:text-stone-200',
    'font-medium' => $variant === 'default',
    'border border-stone-300 bg-white/60 px-4 py-3 font-semibold dark:border-stone-700 dark:bg-stone-950/30' => $variant === 'card',
])>
    <input
        @if ($id !== null) id="{{ $id }}" @endif
        type="checkbox"
        name="{{ $name }}"
        value="{{ $value }}"
        @checked($checked)
        {{ $attributes->except(['id', 'name', 'value', 'checked', 'variant'])->class('size-5 rounded border-stone-300 text-teal-700 focus:ring-teal-600 dark:border-stone-600 dark:bg-stone-900 dark:text-teal-500') }}
    >
    <span>{{ $label }}</span>
</label>
