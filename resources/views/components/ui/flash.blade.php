@if (session('success') || session('status'))
    <div role="status" {{ $attributes->class('rounded-lg border border-teal-300 bg-teal-50 px-4 py-3 text-sm font-medium text-teal-900 dark:border-teal-900 dark:bg-teal-950/50 dark:text-teal-100') }}>
        {{ session('success') ?: session('status') }}
    </div>
@endif
