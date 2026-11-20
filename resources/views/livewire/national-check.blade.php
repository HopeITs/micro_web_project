<div>
    @php($locale = app()->getLocale())
    <x-hc.card>
        <form wire:submit="check" class="hc-toolbar" style="padding:0;align-items:end">
            <x-hc.field :label="__('people.fields.national_number')" for="national-number" :error="$errors->first('number')" class="grow">
                <input class="hc-input ltr" id="national-number" wire:model="number" inputmode="numeric" dir="ltr" maxlength="20" autocomplete="off" autofocus placeholder="00000000000">
            </x-hc.field>
            <div class="hc-field" style="flex-direction:row;gap:8px">
                <x-hc.button type="submit" icon="search" wire:loading.attr="disabled">{{ __('national.check') }}</x-hc.button>
                @if($result !== null || $number !== '')<x-hc.button variant="ghost" icon="x" wire:click="clear">{{ __('hc.clear') }}</x-hc.button>@endif
            </div>
        </form>
        <p class="hc-hint" style="margin-block-start:8px" wire:loading.remove>{{ __('national.hint') }}</p>
        <p class="muted" style="margin-block-start:8px" wire:loading>{{ __('national.checking') }}</p>
    </x-hc.card>

    @if($result !== null)
    @php($found = count($result['micro']) + collect($result['external'])->sum(fn ($e) => count($e['matches'])))
    <div style="margin-block:16px">
        @if($found === 0)
            <x-hc.alert type="success" :title="__('national.none_title')">{{ __('national.none') }}</x-hc.alert>
        @else
            <x-hc.alert type="warning" :title="__('national.found_title', ['count' => $found])">{{ __('national.found') }}</x-hc.alert>
        @endif
    </div>

    <div class="hc-grid" style="grid-template-columns: repeat(auto-fit, minmax(min(100%, 460px), 1fr)); align-items:start">
        <x-hc.card :title="__('national.micro')" :flush="true">
            @if($result['micro'] === [])
                <p class="muted" style="padding:16px 20px">{{ __('national.no_match') }}</p>
            @else
            <div class="hc-table-wrap"><table class="hc-table stack">
                <thead><tr><th>{{ __('people.name') }}</th><th>{{ __('hc.city') }}</th><th>{{ __('national.projects_state') }}</th></tr></thead>
                <tbody>
                @foreach($result['micro'] as $m)
                    <tr>
                        <td data-label="{{ __('people.name') }}">
                            @if($m['readable'])<a class="row-link" href="{{ route('people.show', ['locale' => $locale, 'person' => $m['id']]) }}">{{ $m['name'] }}</a>
                            @else{{ $m['name'] }} <x-hc.badge title="{{ __('national.other_city_hint') }}">{{ __('national.other_city') }}</x-hc.badge>@endif
                            <div class="muted num ltr" style="font-size:.85em">{{ $m['national_number'] }}</div>
                        </td>
                        <td data-label="{{ __('hc.city') }}">{{ $m['city'] }}</td>
                        <td data-label="{{ __('national.projects_state') }}">
                            @forelse($m['projects'] as $p)
                                <div>
                                    @if($m['readable'])<a class="row-link ltr" href="{{ route('projects.show', ['locale' => $locale, 'project' => $p['id']]) }}">{{ $p['number'] }}</a>@else<span class="ltr">{{ $p['number'] }}</span>@endif
                                    · <x-hc.badge>{{ $p['state'] }}</x-hc.badge>
                                </div>
                            @empty<span class="muted">{{ __('national.no_project') }}</span>@endforelse
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @endif
        </x-hc.card>

        <x-hc.card :title="__('national.other_programs')" :flush="true">
            <div class="hc-table-wrap"><table class="hc-table stack">
                <thead><tr><th>{{ __('national.program') }}</th><th>{{ __('national.result') }}</th></tr></thead>
                <tbody>
                @foreach($result['external'] as $e)
                    <tr>
                        <td data-label="{{ __('national.program') }}"><strong>{{ strtoupper($e['program']) }}</strong> · {{ __('national.cities.'.$e['city']) }}</td>
                        <td data-label="{{ __('national.result') }}">
                            @switch($e['status'])
                                @case('found')
                                    @foreach($e['matches'] as $x)<div><x-hc.badge tone="warn">{{ __('national.registered') }}</x-hc.badge> {{ $x['name'] }}@if($x['state']) · <x-hc.badge :tone="$x['state'] === __('national.f2f_active') ? 'ok' : null">{{ $x['state'] }}</x-hc.badge>@endif</div>@endforeach
                                    @break
                                @case('none')<span class="muted">{{ __('national.no_match') }}</span>@break
                                @case('unavailable')<x-hc.badge tone="danger">{{ __('national.unavailable') }}</x-hc.badge>@break
                                @default<x-hc.badge>{{ __('national.off') }}</x-hc.badge>
                            @endswitch
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </x-hc.card>
    </div>
    @endif
</div>
