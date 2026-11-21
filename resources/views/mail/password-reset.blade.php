<x-mail-layout>
    <p style="margin:0 0 12px">{{ __('account.mail_reset_intro') }}</p>
    <p style="margin:20px 0;text-align:center"><a href="{{ $link }}" style="display:inline-block;background:#BE1F24;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:bold">{{ __('account.mail_reset_button') }}</a></p>
    <p style="margin:0 0 12px">{{ __('account.mail_reset_expiry', ['minutes' => $minutes]) }}</p>
    <p style="margin:0;font-size:13px;color:#6b6670;direction:ltr;text-align:left;word-break:break-all">{{ $link }}</p>
    <p style="margin:16px 0 0;font-size:13px;color:#6b6670">{{ __('account.mail_not_you') }}</p>
</x-mail-layout>
