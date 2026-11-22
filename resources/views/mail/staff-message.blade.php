<x-mail-layout>
    <p style="margin:0 0 12px">{{ __('staff.notifications.mail_intro', ['sender' => $sender, 'city' => $city]) }}</p>
    @if($project)<p style="margin:0 0 12px;direction:ltr"><strong>{{ $project }}</strong></p>@endif
    <p style="margin:16px 0;white-space:pre-line">{{ $body }}</p>
    <p style="margin:16px 0 0;font-size:13px;color:#6b6670">{{ __('staff.notifications.mail_footer') }}</p>
</x-mail-layout>
