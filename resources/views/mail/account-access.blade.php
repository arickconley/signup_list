<x-mail::message>
# {{ __('Sign in to Signup Sheets') }}

{{ __('Use this one-time code:') }}

<div style="font-size: 28px; font-weight: 700; letter-spacing: 0.25em; text-align: center; margin: 24px 0;">
{{ $code }}
</div>

{{ __('Or use this secure link:') }}

<x-mail::button :url="$magicLink">
{{ __('Sign in securely') }}
</x-mail::button>

{{ __('This code and link expire in :minutes minutes. Either can be used for one sign-in.', ['minutes' => config('account-access.lifetime_minutes')]) }}

{{ __('If you did not request this email, you can ignore it.') }}

{{ config('app.name') }}
</x-mail::message>
