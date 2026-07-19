<x-mail::message>
# {{ __('Confirm account deletion') }}

{{ __('Use this one-time code to continue deleting your Signup Sheets account:') }}

<div style="font-size: 28px; font-weight: 700; letter-spacing: 0.25em; text-align: center; margin: 24px 0;">
{{ $code }}
</div>

{{ __('This code expires in :minutes minutes.', ['minutes' => config('account-access.lifetime_minutes')]) }}

{{ __('If you did not request account deletion, you can ignore this email. Your account has not been changed.') }}

{{ config('app.name') }}
</x-mail::message>
