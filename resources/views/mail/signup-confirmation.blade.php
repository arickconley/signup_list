<x-mail::message>
# {{ __('Your Signup is confirmed') }}

{{ __('Your selections for :sheet:', ['sheet' => $sheetTitle]) }}

@foreach ($selectionNames as $selectionName)
- {{ $selectionName }}
@endforeach

<x-mail::button :url="$sheetUrl">
{{ __('View Signup Sheet') }}
</x-mail::button>

{{ __('Use this one-time code to prove your email address:') }}

<div style="font-size: 28px; font-weight: 700; letter-spacing: 0.25em; text-align: center; margin: 24px 0;">
{{ $code }}
</div>

{{ __('Or use this secure link:') }}

<x-mail::button :url="$magicLink">
{{ __('Sign in securely') }}
</x-mail::button>

{{ __('This code and link expire in :minutes minutes. Either can be used once.', ['minutes' => config('account-access.lifetime_minutes')]) }}

{{ __('If you did not submit this Signup, you can ignore this email.') }}

{{ config('app.name') }}
</x-mail::message>
