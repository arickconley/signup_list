@props(['label', 'name', 'checked' => false])

<label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm font-medium text-stone-700 dark:text-stone-200">
    <input type="checkbox" name="{{ $name }}" value="1" @checked($checked) {{ $attributes->except(['name', 'checked']) }} class="size-5 rounded border-stone-300 text-teal-700 focus:ring-teal-600 dark:border-stone-600 dark:bg-stone-900 dark:text-teal-500">
    <span>{{ $label }}</span>
</label>
