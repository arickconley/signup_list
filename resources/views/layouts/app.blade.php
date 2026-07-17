<x-layouts::app.sidebar :title="$title ?? null">
    <main id="main-content" class="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-10 lg:py-8">
        {{ $slot }}
    </main>
</x-layouts::app.sidebar>
