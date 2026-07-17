@props(['href' => null])

<a href="{{ $href }}" {{ $attributes->class('font-semibold text-teal-700 underline decoration-teal-700/30 underline-offset-4 transition hover:decoration-current focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 dark:text-teal-400') }}>{{ $slot }}</a>
