<x-mail-layout>
    <p style="margin:0 0 12px">{{ __('account.mail_code_intro') }}</p>
    <p style="margin:20px 0;text-align:center;font-size:30px;letter-spacing:8px;font-weight:bold;direction:ltr">{{ $code }}</p>
    <p style="margin:0 0 12px">{{ __('account.mail_code_expiry', ['minutes' => $minutes]) }}</p>
    <p style="margin:16px 0 0;font-size:13px;color:#6b6670">{{ __('account.mail_not_you') }}</p>
</x-mail-layout>
