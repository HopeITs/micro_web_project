<x-layouts.hc :title="__('field.meeting.title')">
    <div class="hc-page-head"><div><h1>{{ __('field.meeting.title') }}</h1><p>{{ __('field.meeting.scope') }}</p></div>
        <x-hc.button variant="ghost" icon="arrow" :href="route('meetings.index', ['locale' => app()->getLocale()])">{{ __('field.meeting.back') }}</x-hc.button></div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif
    <livewire:profession-meeting-form :meeting-id="$meetingId" :city="$city" />
    @if($meetingId)
        <div style="margin-block-start:16px"><livewire:attachments-panel owner="meeting" :owner-id="$meetingId" /></div>
    @endif
</x-layouts.hc>
