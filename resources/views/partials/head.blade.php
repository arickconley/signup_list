<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
@if (filled($robots ?? null))
    <meta name="robots" content="{{ $robots }}">
@endif

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<script>
    (() => {
        const appearance = localStorage.getItem('appearance') || 'system';
        const dark = appearance === 'dark' || (appearance === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);

        document.documentElement.classList.toggle('dark', dark);
        document.documentElement.dataset.appearance = appearance;
    })();
</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
@if ($includeLivewire ?? true)
    @livewireStyles
@endif
