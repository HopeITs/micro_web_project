{{ __('staff.notifications.mail_intro', ['sender' => $sender, 'city' => $city]) }}
@if($project)

{{ $project }}
@endif

{{ $body }}

{{ __('staff.notifications.mail_footer') }}
