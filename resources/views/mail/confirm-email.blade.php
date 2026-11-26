{{-- SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{ __('mail.confirm.greeting', ['name' => $username]) }}

{{ __('mail.confirm.body') }}

{{ $url }}

{{ __('mail.confirm.expiry', ['minutes' => $minutes]) }}

{{ __('mail.confirm.ignore') }}
