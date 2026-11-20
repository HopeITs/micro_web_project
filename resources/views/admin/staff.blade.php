@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $cityName = fn ($c) => $c ? ($en ? $c->name_en : $c->name_ar) : '';
    $roleName = fn ($id) => $id === null ? '—' : (__('admin.roles.'.$id) !== 'admin.roles.'.$id ? __('admin.roles.'.$id) : __('admin.role_other', ['id' => $id]));
@endphp
<x-layouts.hc :title="__('admin.staff_title')">
    <div class="hc-page-head"><div><p class="hc-eyebrow">{{ __('admin.title') }}</p><h1>{{ __('admin.staff_title') }}</h1><p>{{ __('admin.staff_intro') }}</p></div></div>
    @include('admin._nav')

    <div class="hc-chips" role="group" aria-label="{{ __('admin.city') }}" style="margin-block-end:16px">
        <a class="hc-chip" href="{{ route('admin.staff', ['locale' => $locale]) }}" aria-pressed="{{ empty($filters['city']) ? 'true' : 'false' }}">{{ __('admin.all_cities') }}</a>
        @foreach($cities as $c)
            <a class="hc-chip" href="{{ route('admin.staff', ['locale' => $locale, 'city' => $c->id]) }}" aria-pressed="{{ (int) ($filters['city'] ?? 0) === (int) $c->id ? 'true' : 'false' }}">{{ $cityName($c) }} <span class="muted ltr">{{ $c->code }}</span></a>
        @endforeach
    </div>

    <div class="hc-grid">
    @foreach($groups as $group)
        <x-hc.section :title="$cityName($group['city']).' · '.__('admin.staff_counts', ['accounts' => $group['users']->count(), 'desktop' => $group['desktop']->count()])" :step="$group['city']->code" :open="count($groups) === 1">
            <div class="hc-grid" style="grid-template-columns: repeat(auto-fit, minmax(min(100%, 420px), 1fr)); align-items:start">
                <div>
                    <h3 class="hc-label" style="margin-block-end:8px">{{ __('admin.staff_web') }}</h3>
                    <div class="hc-table-wrap"><table class="hc-table stack">
                        <thead><tr><th>{{ __('admin.username') }}</th><th>{{ __('admin.staff_roles') }}</th><th class="num">{{ __('admin.staff_tasks') }}</th></tr></thead>
                        <tbody>
                        @forelse($group['users'] as $u)
                            <tr>
                                <td data-label="{{ __('admin.username') }}"><a class="row-link ltr" href="{{ route('admin.accounts.show', ['locale' => $locale, 'account' => $u->id]) }}">{{ $u->username }}</a> @if($u->disabled)<x-hc.badge tone="danger">{{ __('admin.disabled') }}</x-hc.badge>@endif</td>
                                <td data-label="{{ __('admin.staff_roles') }}">@forelse($u->roles as $r)<x-hc.badge>{{ __('admin.bundles.'.$r) }}</x-hc.badge> @empty<span class="muted">—</span>@endforelse</td>
                                <td data-label="{{ __('admin.staff_tasks') }}" class="num">{{ $u->tasks }}</td>
                            </tr>
                        @empty<tr><td colspan="3" class="muted">{{ __('admin.none') }}</td></tr>@endforelse
                        </tbody>
                    </table></div>
                </div>
                <div>
                    <h3 class="hc-label" style="margin-block-end:8px">{{ __('admin.staff_desktop') }}</h3>
                    <div class="hc-table-wrap"><table class="hc-table stack">
                        <thead><tr><th>{{ __('admin.staff_user') }}</th><th>{{ __('admin.staff_role') }}</th><th>{{ __('admin.staff_account') }}</th></tr></thead>
                        <tbody>
                        @forelse($group['desktop'] as $d)
                            <tr>
                                <td data-label="{{ __('admin.staff_user') }}"><bdi class="ltr">{{ $d->username }}</bdi> <span class="muted num">#{{ $d->legacy_user_id }}</span> @foreach($d->flags as $f)<x-hc.badge>{{ __('admin.flags.'.$f) }}</x-hc.badge>@endforeach @if($d->disabled)<x-hc.badge tone="danger">{{ __('admin.disabled') }}</x-hc.badge>@endif</td>
                                <td data-label="{{ __('admin.staff_role') }}">{{ $roleName($d->role) }}</td>
                                <td data-label="{{ __('admin.staff_account') }}">@if($d->account_id)<a class="row-link ltr" href="{{ route('admin.accounts.show', ['locale' => $locale, 'account' => $d->account_id]) }}">{{ $d->account }}</a>@if($d->link_revoked) <x-hc.badge tone="danger">{{ __('admin.link_revoked') }}</x-hc.badge>@endif @else<span class="muted">{{ __('admin.unlinked') }}</span>@endif</td>
                            </tr>
                        @empty<tr><td colspan="3" class="muted">{{ __('admin.staff_empty') }}</td></tr>@endforelse
                        </tbody>
                    </table></div>
                </div>
            </div>
        </x-hc.section>
    @endforeach
    </div>
</x-layouts.hc>
