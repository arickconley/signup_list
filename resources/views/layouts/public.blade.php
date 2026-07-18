@props([
    'title' => null,
    'robots' => null,
    'includeLivewire' => false,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['includeLivewire' => $includeLivewire])
    </head>
    <body class="min-h-svh overflow-x-hidden bg-stone-100 text-stone-950 dark:bg-stone-950 dark:text-stone-50">
        {{ $slot }}
        @if ($includeLivewire)
            @livewireScripts
        @endif
    </body>
</html>
