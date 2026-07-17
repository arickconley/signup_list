@props(['label' => 'Authentication code', 'name' => 'code'])

<x-ui.input
    :label="$label"
    :name="$name"
    inputmode="numeric"
    autocomplete="one-time-code"
    pattern="[0-9]*"
    maxlength="6"
    {{ $attributes->class('text-center font-mono text-xl tracking-[0.5em] tabular-nums') }}
/>
