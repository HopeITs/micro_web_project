@php($rtl = app()->getLocale() === 'ar')
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('hc.brand') }}</title></head>
<body style="margin:0;background:#f4f2f2;font-family:Tahoma,Arial,sans-serif;color:#1f1d20">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px"><tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:12px;overflow:hidden;text-align:{{ $rtl ? 'right' : 'left' }}">
        <tr><td style="background:#BE1F24;color:#ffffff;padding:18px 24px;font-size:18px;font-weight:bold">{{ __('hc.brand') }} · {{ __('hc.organization') }}</td></tr>
        <tr><td style="padding:24px;font-size:15px;line-height:1.7">{{ $slot }}</td></tr>
        <tr><td style="padding:16px 24px;border-top:1px solid #eee;font-size:12px;color:#6b6670">{{ __('account.mail_footer') }}</td></tr>
    </table>
</td></tr></table>
</body>
</html>
