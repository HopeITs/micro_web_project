@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
@endphp
<x-layouts.hc :title="__('staff.notifications.send_title')">
    <div class="hc-page-head"><div><h1>{{ __('staff.notifications.send_title') }}</h1><p>{{ __('staff.notifications.send_intro') }}</p></div></div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif
    <x-hc.card style="max-width:640px">
        @if($cities->count() > 1)
        <form method="GET" action="{{ route('notifications.create', ['locale' => $locale]) }}" class="hc-toolbar" style="padding:0;gap:8px;align-items:end;margin-block-end:16px">
            <div class="hc-field"><label class="hc-label" for="city-pick">{{ __('staff.notifications.city') }}</label>
                <select class="hc-select" id="city-pick" name="city" onchange="this.form.submit()">@foreach($cities as $c)<option value="{{ $c->id }}" @selected($c->id === $city)>{{ $en ? $c->name_en : $c->name_ar }}</option>@endforeach</select></div>
        </form>
        @endif
        <form method="POST" action="{{ route('notifications.send', ['locale' => $locale]) }}" class="hc-grid" style="gap:16px" novalidate>@csrf
            <input type="hidden" name="city" value="{{ $city }}">
            <x-hc.field :label="__('staff.notifications.to')" for="to" :required="true">
                <select class="hc-select" id="to" name="to"><option value="all" @selected(old('to', 'all') === 'all')>{{ __('staff.notifications.everyone') }}</option>
                    @foreach($recipients as $user)<option value="{{ $user->id }}" @selected((string) old('to') === (string) $user->id)>{{ $user->username }}</option>@endforeach</select>
                @error('to')<span class="hc-error">{{ $message }}</span>@enderror
            </x-hc.field>
            <x-hc.field :label="__('staff.notifications.date')" for="date">
                <input class="hc-input num ltr" id="date" name="date" type="date" value="{{ old('date', now()->toDateString()) }}">
                @error('date')<span class="hc-error">{{ $message }}</span>@enderror
            </x-hc.field>
            <x-hc.field :label="__('staff.notifications.about')" for="title">
                <input class="hc-input" id="title" name="title" maxlength="255" value="{{ old('title') }}">
                @error('title')<span class="hc-error">{{ $message }}</span>@enderror
            </x-hc.field>
            <x-hc.field :label="__('staff.notifications.body')" for="body" :required="true">
                <textarea class="hc-input" id="body" name="body" rows="6" required>{{ old('body') }}</textarea>
                @error('body')<span class="hc-error">{{ $message }}</span>@enderror
            </x-hc.field>
            @if($admin)
            <x-hc.field :label="__('staff.notifications.project')" for="project" :hint="__('staff.notifications.project_hint')">
                <input class="hc-input num ltr" id="project" name="project" inputmode="numeric" maxlength="19" value="{{ old('project') }}">
                @error('project')<span class="hc-error">{{ $message }}</span>@enderror
            </x-hc.field>
            @endif
            <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="email_copy" value="1" @checked(old('email_copy', '1') === '1') style="width:20px;height:20px"> {{ __('staff.notifications.email_copy') }}</label>
            <div><x-hc.button type="submit" icon="mail">{{ __('staff.notifications.send') }}</x-hc.button></div>
        </form>
    </x-hc.card>
</x-layouts.hc>
