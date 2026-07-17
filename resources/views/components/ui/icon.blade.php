@props(['name'])

<svg {{ $attributes->class('shrink-0') }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('home') <path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/> @break
        @case('settings') <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H3v-4h.2a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.6v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/> @break
        @case('logout') <path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/> @break
        @case('key') <circle cx="8" cy="15" r="4"/><path d="m11 12 9-9"/><path d="m16 7 2 2"/><path d="m18 5 2 2"/> @break
        @case('trash') <path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="m6 7 1 14h10l1-14"/><path d="M9 7V4h6v3"/> @break
        @case('plus') <path d="M12 5v14M5 12h14"/> @break
        @case('finger-print') <path d="M12 11a2 2 0 0 1 2 2c0 3-.8 5.7-2.2 8"/><path d="M8.3 21A17 17 0 0 0 10 13a2 2 0 0 1 4 0"/><path d="M5.2 19A19 19 0 0 0 6 13a6 6 0 0 1 12 0c0 2.4-.4 4.7-1.2 6.8"/><path d="M4.5 8.5A9 9 0 0 1 21 13"/><path d="M3 13a9 9 0 0 1 .4-2.7"/> @break
        @case('eye') <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/> @break
        @case('eye-slash') <path d="m3 3 18 18"/><path d="M10.6 6.2A10 10 0 0 1 12 6c6.5 0 10 6 10 6a16 16 0 0 1-2.4 3.2M6.5 6.5C3.6 8.3 2 12 2 12s3.5 6 10 6a10 10 0 0 0 4.1-.8"/> @break
        @case('arrow-path')
        @case('refresh') <path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M18.5 9A7 7 0 0 0 6 6.5L4 9M5.5 15A7 7 0 0 0 18 17.5l2-2.5"/> @break
        @case('lock-closed')
        @case('lock') <rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/> @break
        @case('qr-code') <rect x="3" y="3" width="6" height="6"/><rect x="15" y="3" width="6" height="6"/><rect x="3" y="15" width="6" height="6"/><path d="M15 15h2v2h-2zM19 15h2v6h-6v-2"/> @break
        @case('copy') <rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/> @break
        @case('check') <path d="m5 12 4 4L19 6"/> @break
        @case('menu') <path d="M4 7h16M4 12h16M4 17h16"/> @break
        @case('close') <path d="m6 6 12 12M18 6 6 18"/> @break
        @case('loading')
        @case('spinner') <path d="M21 12a9 9 0 1 1-6.2-8.6" class="animate-spin origin-center"/> @break
        @default <circle cx="12" cy="12" r="9"/> <path d="M12 8v4M12 16h.01"/>
    @endswitch
</svg>
