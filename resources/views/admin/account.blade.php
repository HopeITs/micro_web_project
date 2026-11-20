@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $cityName = fn ($c) => $c ? ($en ? $c->name_en : $c->name_ar) : '';
    $roleName = fn ($id) => $id === null ? '—' : (__('admin.roles.'.$id) !== 'admin.roles.'.$id ? __('admin.roles.'.$id) : __('admin.role_other', ['id' => $id]));
    $cap = fn (string $capability) => __('admin.cap.'.$capability);
    $base = ['locale' => $locale, 'account' => $account->id];
    // Capabilities grouped by their area (the part before the dot), so a city panel reads as short lists.
    $areas = fn (array $caps) => collect($caps)->groupBy(fn ($c) => explode('.', $c)[0]);
@endphp
<x-layouts.hc :title="$account->username">
    <p style="margin-block-end:12px"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('admin.accounts.index', ['locale' => $locale]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('admin.back') }}</a></p>
    <div class="hc-page-head">
        <div>
            <p class="hc-eyebrow">{{ __('admin.account') }}</p>
            <h1><bdi class="ltr">{{ $account->username }}</bdi></h1>
            <p>{{ __('admin.created') }} <span class="num">{{ substr((string) $account->created_at, 0, 10) }}</span> ·
                {{ $account->credential_legacy_user_id ? ($account->has_password ? __('admin.cred_web') : __('admin.cred_desktop')) : ($account->must_change ? __('admin.cred_one_time') : __('admin.cred_web')) }}</p>
        </div>
        @if($account->disabled_at)<x-hc.badge tone="danger" style="font-size:.9rem;padding:4px 14px">{{ __('admin.disabled') }}</x-hc.badge>@else<x-hc.badge tone="ok" style="font-size:.9rem;padding:4px 14px">{{ __('admin.active') }}</x-hc.badge>@endif
    </div>
    @include('admin._nav')
    @if($self)<x-hc.alert type="info" style="margin-block-end:16px">{{ __('admin.self') }}</x-hc.alert>@endif
    @if(session('one_time_password'))
        <x-hc.alert type="warning" :title="__('admin.one_time_password')" style="margin-block-end:16px"><bdi dir="ltr"><code style="font-size:1.1rem;user-select:all">{{ session('one_time_password') }}</code></bdi><br>{{ __('admin.one_time_note') }}</x-hc.alert>
    @endif

    <div class="hc-grid" style="grid-template-columns: repeat(auto-fit, minmax(min(100%, 520px), 1fr)); align-items:start">
        <div class="hc-grid">
            @foreach($panels as $i => $panel)
            @php($city = $panel['city'])
            <x-hc.section :title="$cityName($city).' · '.(count($panel['held']) ? collect($panel['held'])->map(fn ($r) => __('admin.bundles.'.$r))->implode('، ') : __('admin.no_roles'))" :step="$city->code" :open="count($panel['held']) > 0 || ($i === 0 && collect($panels)->every(fn ($p) => $p['held'] === []))" id="city-{{ $city->id }}">
                <h3 class="hc-label" style="margin-block-end:8px">{{ __('admin.roles_title') }}</h3>
                <div class="hc-chips" style="margin-block-end:12px">
                    @forelse($panel['held'] as $held)
                        <span class="hc-chip" aria-pressed="true">{{ __('admin.bundles.'.$held) }}
                            @unless($self)
                            <form method="POST" action="{{ route('admin.accounts.role-revoke', $base) }}" style="display:inline" onsubmit="return confirm(@js(__('admin.confirm_revoke_role')))">@csrf
                                <input type="hidden" name="city" value="{{ $city->id }}"><input type="hidden" name="role" value="{{ $held }}">
                                <button class="hc-icon-btn" style="width:24px;height:24px" type="submit" aria-label="{{ __('admin.revoke_role') }}: {{ __('admin.bundles.'.$held) }}" title="{{ __('admin.revoke_role') }}"><x-hc.icon name="x" :size="14"/></button>
                            </form>
                            @endunless
                        </span>
                    @empty<span class="muted">{{ __('admin.no_roles') }}</span>@endforelse
                </div>
                @if($panel['roles'] && ! $self)
                <form method="POST" action="{{ route('admin.accounts.role', $base) }}" class="hc-toolbar" style="padding:0;margin-block-end:16px">@csrf
                    <input type="hidden" name="city" value="{{ $city->id }}">
                    <div class="hc-field grow"><label class="hc-label" for="role-{{ $city->id }}">{{ __('admin.add_role') }}</label>
                        <select class="hc-select" id="role-{{ $city->id }}" name="role">@foreach($panel['roles'] as $role)@unless(in_array($role, $panel['held'], true))<option value="{{ $role }}">{{ __('admin.bundles.'.$role) }}</option>@endunless @endforeach</select></div>
                    <div class="hc-field" style="justify-content:flex-end"><x-hc.button type="submit" variant="secondary" icon="plus">{{ __('admin.apply_role') }}</x-hc.button></div>
                </form>
                @endif

                <details>
                    <summary class="hc-label" style="cursor:pointer">{{ __('admin.capabilities') }} ({{ count($panel['active']) }})</summary>
                    <form method="POST" action="{{ route('admin.accounts.capabilities', $base) }}" style="margin-block-start:12px">@csrf
                        <input type="hidden" name="city" value="{{ $city->id }}">
                        @foreach($areas($panel['grantable']) as $area => $caps)
                            <div class="hc-choice" style="margin-block-end:8px">
                                @foreach($caps as $capability)<label><input type="checkbox" name="capabilities[]" value="{{ $capability }}" @checked(in_array($capability, $panel['active'], true)) @disabled($self)>{{ $cap($capability) }}</label>@endforeach
                            </div>
                        @endforeach
                        @if($panel['fixed'])<p class="hc-hint">{{ __('admin.fixed') }}: {{ collect($panel['fixed'])->map($cap)->implode('، ') }}</p>@endif
                        @unless($self)<x-hc.button type="submit" size="sm" icon="check">{{ __('admin.save_capabilities') }}</x-hc.button>@endunless
                    </form>
                </details>
            </x-hc.section>
            @endforeach
        </div>

        <div class="hc-grid">
            <x-hc.card :title="__('admin.email')" :subtitle="__('admin.email_hint')">
                @if($canEmail)
                <form method="POST" action="{{ route('admin.accounts.email', $base) }}" class="hc-toolbar" style="padding:0">@csrf
                    <x-hc.field :label="__('admin.email')" for="email" class="grow"><input class="hc-input ltr" id="email" name="email" type="email" maxlength="190" value="{{ old('email', $account->email) }}"></x-hc.field>
                    <div class="hc-field" style="justify-content:flex-end"><x-hc.button type="submit" variant="secondary">{{ __('admin.save') }}</x-hc.button></div>
                </form>
                @else
                <p class="ltr">{{ $account->email ?: __('admin.none_held') }}</p>
                @unless($self)<p class="hc-hint">{{ __('admin.email_locked') }}</p>@endunless
                @endif
            </x-hc.card>

            <x-hc.card :title="__('admin.identities_title')" :flush="true" id="identities">
                <div class="hc-table-wrap">
                <table class="hc-table stack">
                    <thead><tr><th>{{ __('admin.identity') }}</th><th>{{ __('admin.city') }}</th><th>{{ __('admin.staff_role') }}</th><th>{{ __('admin.state') }}</th><th></th></tr></thead>
                    <tbody>
                    @forelse($links as $link)
                    <tr>
                        <td data-label="{{ __('admin.identity') }}"><bdi class="ltr">{{ $link->username }}</bdi> <span class="muted num">#{{ $link->legacy_user_id }}</span> @if($link->credential)<x-hc.badge tone="info">{{ __('admin.credential_link') }}</x-hc.badge>@endif</td>
                        <td data-label="{{ __('admin.city') }}">{{ $cityName($link->city) }}</td>
                        <td data-label="{{ __('admin.staff_role') }}">{{ $roleName($link->role) }}</td>
                        <td data-label="{{ __('admin.state') }}">@if($link->revoked_at)<x-hc.badge tone="danger">{{ __('admin.link_revoked') }}</x-hc.badge>@elseif($link->user_disabled_at)<x-hc.badge tone="warn">{{ __('admin.link_disabled') }}</x-hc.badge>@else<x-hc.badge tone="ok">{{ __('admin.link_active') }}</x-hc.badge>@endif</td>
                        <td>@unless($self || ($link->credential && ! $link->revoked_at))
                            <form method="POST" action="{{ route('admin.accounts.link-state', $base + ['link' => $link->id]) }}">@csrf<input type="hidden" name="revoked" value="{{ $link->revoked_at ? 0 : 1 }}">
                                <x-hc.button type="submit" variant="ghost" size="sm">{{ $link->revoked_at ? __('admin.restore') : __('admin.revoke') }}</x-hc.button></form>
                        @endunless</td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="muted">{{ __('admin.no_links') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
                </div>
                @if($otherLinks)<p class="hc-hint" style="padding:0 20px">{{ __('admin.other_links', ['count' => $otherLinks]) }}</p>@endif
                @if($candidates->isNotEmpty())
                <x-slot:footer>
                <form method="POST" action="{{ route('admin.accounts.link', $base) }}" class="hc-toolbar" style="padding:0">@csrf
                    <x-hc.field :label="__('admin.link_title')" for="identity" :hint="__('admin.link_hint')" class="grow">
                        <select class="hc-select" id="identity" name="identity" required><option value="">{{ __('admin.choose') }}</option>
                            @foreach($candidates as $user)<option value="{{ $user->id }}">{{ $user->username }} — {{ $cityName($user->city_name) }} — {{ $roleName($user->role) }}@if($user->same) ★ {{ __('admin.same_name') }}@endif</option>@endforeach
                        </select>
                    </x-hc.field>
                    <div class="hc-field" style="justify-content:flex-end"><x-hc.button type="submit" variant="secondary">{{ __('admin.link') }}</x-hc.button></div>
                </form>
                </x-slot:footer>
                @endif
            </x-hc.card>

            @unless($self)
            <x-hc.card :title="__('admin.status')" :subtitle="__('admin.disable_note')" id="status">
                @if($canDisable)
                <form method="POST" action="{{ route('admin.accounts.disable', $base) }}" @unless($account->disabled_at) onsubmit="return confirm(@js(__('admin.confirm_disable')))" @endunless>@csrf<input type="hidden" name="disabled" value="{{ $account->disabled_at ? 0 : 1 }}">
                    <x-hc.button type="submit" :variant="$account->disabled_at ? 'primary' : 'danger'">{{ $account->disabled_at ? __('admin.enable') : __('admin.disable') }}</x-hc.button></form>
                @else
                <x-hc.alert type="info">{{ __('admin.cannot_disable') }}</x-hc.alert>
                @endif
            </x-hc.card>
            @endunless
        </div>
    </div>
</x-layouts.hc>
