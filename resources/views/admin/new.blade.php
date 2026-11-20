@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $cityName = fn ($c) => $c ? ($en ? $c->name_en : $c->name_ar) : '';
    $roleName = fn ($id) => $id === null ? '—' : (__('admin.roles.'.$id) !== 'admin.roles.'.$id ? __('admin.roles.'.$id) : __('admin.role_other', ['id' => $id]));
    $involved = $groups->flatMap(fn ($group) => $group->identities)->pluck('city')->unique()->values();
    $oldIds = array_map('intval', (array) old('identities'));
@endphp
<x-layouts.hc :title="__('admin.new')">
    <p style="margin-block-end:12px"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('admin.accounts.index', ['locale' => $locale]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('admin.back') }}</a></p>
    <div class="hc-page-head"><div><p class="hc-eyebrow">{{ __('admin.title') }}</p><h1>{{ __('admin.new') }}</h1><p>{{ __('admin.new_choose') }}</p></div></div>
    @include('admin._nav')

    <div class="hc-grid" style="grid-template-columns: repeat(auto-fit, minmax(min(100%, 460px), 1fr)); align-items:start">
        <x-hc.card :title="__('admin.plain_title')" :subtitle="__('admin.plain_intro')" id="plain">
            <form method="POST" action="{{ route('admin.accounts.store-plain', ['locale' => $locale]) }}">@csrf
                <div class="hc-form-grid">
                    <x-hc.field :label="__('admin.username')" for="plain-username" :required="true"><input class="hc-input ltr" id="plain-username" name="username" maxlength="200" required value="{{ old('username') }}" autocomplete="off"></x-hc.field>
                    <x-hc.field :label="__('admin.email')" for="plain-email" :required="true" :hint="__('admin.email_hint')"><input class="hc-input ltr" id="plain-email" name="email" type="email" maxlength="190" required value="{{ old('email') }}" autocomplete="off"></x-hc.field>
                </div>
                <p class="hc-label" style="margin-block:16px 8px">{{ __('admin.role_per_city') }}</p>
                <div class="hc-form-grid">
                    @foreach($cities as $city)
                    <x-hc.field :label="$cityName($city)" :for="'plain-role-'.$city->id">
                        <select class="hc-select" id="plain-role-{{ $city->id }}" name="roles[{{ $city->id }}]"><option value="">{{ __('admin.role_none_short') }}</option>
                            @foreach($roles as $role)<option value="{{ $role }}" @selected(old('roles.'.$city->id) === $role)>{{ __('admin.bundles.'.$role) }}</option>@endforeach</select>
                    </x-hc.field>
                    @endforeach
                </div>
                <div style="margin-block-start:16px"><x-hc.button type="submit" icon="plus">{{ __('admin.create_plain') }}</x-hc.button></div>
            </form>
        </x-hc.card>

        <x-hc.card :title="__('admin.new_title')" :subtitle="__('admin.new_intro')" id="reviewed">
            @if($groups->isEmpty())
                <x-hc.empty :title="__('admin.no_candidates')" icon="users">{{ __('admin.no_candidates_hint') }}</x-hc.empty>
            @else
            <form method="POST" action="{{ route('admin.accounts.store', ['locale' => $locale]) }}">@csrf
                <div class="hc-grid" style="gap:12px;margin-block-end:16px">
                @foreach($groups as $group)
                    <fieldset style="border:1px solid var(--line);border-radius:var(--radius);padding:12px 16px">
                        <legend style="padding-inline:6px;font-weight:700"><bdi class="ltr">{{ $group->username }}</bdi> @if($group->identities->count() > 1)<x-hc.badge tone="warn">{{ __('admin.duplicates', ['count' => $group->identities->count()]) }}</x-hc.badge>@endif</legend>
                        @foreach($group->identities as $user)
                        <div style="display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;padding-block:6px">
                            <div class="hc-choice"><label><input type="checkbox" name="identities[]" value="{{ $user->id }}" @checked(in_array($user->id, $oldIds, true))>{{ $cityName($user->city_name) }} · {{ $roleName($user->role) }} <span class="muted num">#{{ $user->legacy_user_id }}</span></label></div>
                            @if($user->credential_ok)
                                <div class="hc-choice"><label><input type="radio" name="credential" value="{{ $user->id }}" @checked((int) old('credential') === $user->id)>{{ __('admin.credential') }}</label></div>
                            @else
                                <span class="hc-hint">{{ __('admin.credential_unusable') }}</span>
                            @endif
                            @foreach($user->flags as $flag)<x-hc.badge>{{ __('admin.flags.'.$flag) }}</x-hc.badge>@endforeach
                        </div>
                        @endforeach
                    </fieldset>
                @endforeach
                </div>
                <div class="hc-form-grid">
                    <x-hc.field :label="__('admin.account_username')" for="username"><input class="hc-input ltr" id="username" name="username" maxlength="200" value="{{ old('username') }}" autocomplete="off"></x-hc.field>
                    <x-hc.field :label="__('admin.email')" for="email" :hint="__('admin.email_from_identity')"><input class="hc-input ltr" id="email" name="email" type="email" maxlength="190" value="{{ old('email') }}" autocomplete="off"></x-hc.field>
                </div>
                <p class="hc-label" style="margin-block:16px 8px">{{ __('admin.role_per_city') }}</p>
                <div class="hc-form-grid">
                    @foreach($cities as $city)
                    @if($involved->contains((int) $city->id))
                    <x-hc.field :label="$cityName($city)" :for="'role-'.$city->id">
                        <select class="hc-select" id="role-{{ $city->id }}" name="roles[{{ $city->id }}]"><option value="">{{ __('admin.role_none') }}</option>
                            @foreach($roles as $role)<option value="{{ $role }}" @selected(old('roles.'.$city->id) === $role)>{{ __('admin.bundles.'.$role) }}</option>@endforeach</select>
                    </x-hc.field>
                    @endif
                    @endforeach
                </div>
                <div class="hc-choice" style="margin-block:16px"><label><input type="checkbox" name="confirm" value="1" required>{{ __('admin.confirm') }}</label></div>
                <x-hc.button type="submit" icon="check">{{ __('admin.create') }}</x-hc.button>
            </form>
            @endif
        </x-hc.card>
    </div>
</x-layouts.hc>
