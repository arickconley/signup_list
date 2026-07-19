<x-mail::message>
# {{ __('The Owner changed your Signup') }}

{{ __('The Owner changed your Signup for :sheet.', ['sheet' => $sheetTitle]) }}

@if ($removedOptionName !== null)
{{ __('Removed Option: :name', ['name' => $removedOptionName]) }}
@endif

## {{ __('Before selections') }}

@foreach ($beforeSelectionNames as $selectionName)
- {{ $selectionName }}
@endforeach

## {{ __('After selections') }}

@forelse ($afterSelectionNames as $selectionName)
- {{ $selectionName }}
@empty
{{ __('No selections remain.') }}
@endforelse

<x-mail::button :url="$sheetUrl">
{{ __('View Signup Sheet') }}
</x-mail::button>

{{ __('This change was made by the Signup Sheet Owner.') }}

{{ config('app.name') }}
</x-mail::message>
