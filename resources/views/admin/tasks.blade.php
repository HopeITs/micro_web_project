@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $cityName = fn ($c) => $c ? ($en ? $c->name_en : $c->name_ar) : '';
    $groups = collect($tasks)->groupBy(fn ($t) => in_array($t->type, ['f', 'a'], true) ? $t->type : 'o');
@endphp
<x-layouts.hc :title="__('admin.tasks_title')">
    <div class="hc-page-head"><div><p class="hc-eyebrow">{{ __('admin.title') }}</p><h1>{{ __('admin.tasks_title') }}</h1><p>{{ __('admin.tasks_intro') }}</p></div></div>
    @include('admin._nav')

    <div class="hc-chips" role="group" aria-label="{{ __('admin.city') }}" style="margin-block-end:16px">
        @foreach($cities as $c)
            <a class="hc-chip" href="{{ route('admin.tasks', ['locale' => $locale, 'city' => $c->id]) }}" aria-pressed="{{ (int) $c->id === (int) $city->id ? 'true' : 'false' }}">{{ $cityName($c) }} <span class="muted ltr">{{ $c->code }}</span></a>
        @endforeach
    </div>

    @if($accounts->isEmpty())
        <x-hc.alert type="info">{{ __('admin.tasks_no_accounts') }}</x-hc.alert>
    @else
    <form method="POST" action="{{ route('admin.tasks.save', ['locale' => $locale]) }}">@csrf
        <input type="hidden" name="city" value="{{ $city->id }}">
        <div class="hc-grid">
        @foreach(['f' => 'admin.tasks_funded', 'a' => 'admin.tasks_application', 'o' => 'admin.tasks_other'] as $type => $label)
            @continue(! $groups->has($type))
            <x-hc.card :title="__($label)" :subtitle="$cityName($city)" :flush="true">
                <div class="hc-table-wrap">
                <table class="hc-table">
                    <thead><tr><th style="min-width:200px">{{ __('admin.task') }}</th>@foreach($accounts as $a)<th class="ltr" style="text-align:center;white-space:nowrap">{{ $a->username }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach($groups[$type] as $task)
                        <tr>
                            <th scope="row" style="font-weight:500">{{ $en && $task->name_en ? $task->name_en : $task->name_ar }}</th>
                            @foreach($accounts as $a)
                                <td style="text-align:center"><input type="checkbox" style="accent-color:var(--brand);width:18px;height:18px" name="assign[{{ $task->id }}][]" value="{{ $a->id }}" @checked(in_array($a->id, $task->accounts, true)) aria-label="{{ ($en && $task->name_en ? $task->name_en : $task->name_ar) }} — {{ $a->username }}"></td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </x-hc.card>
        @endforeach
        </div>
        <x-hc.action-bar><span class="muted">{{ __('admin.tasks_hint', ['city' => $cityName($city)]) }}</span><span class="spacer"></span><x-hc.button type="submit" icon="check">{{ __('admin.save') }}</x-hc.button></x-hc.action-bar>
    </form>
    @endif
</x-layouts.hc>
