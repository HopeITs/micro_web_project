@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $status = \App\Support\ProjectStatus::class;
    $chosen = array_map('intval', $filters['cities'] ?? []);
    $canAdd = app(\App\Services\AccountAccess::class)->cities(auth()->user(), 'assessments.manage')->isNotEmpty();
    $sortable = \App\Services\Assessments\ChecklistListService::SORT_KEYS;
    [$sort, $dir] = \App\Support\TableTools\TableSort::clean($filters['sort'] ?? null, $filters['dir'] ?? null, $sortable);
    $headers = array_combine($sortable, array_map(fn ($key) => __('checklists.list.'.$key), $sortable));
@endphp
<x-layouts.hc :title="__('checklists.list.title')">
    <div class="hc-page-head">
        <div><h1>{{ __('checklists.list.title') }}</h1><p>{{ __('checklists.list.intro') }}</p></div>
        {{-- Start a checklist from here (owner request 22 Nov 2026): choose the project by number or beneficiary name. --}}
        @if($canAdd)<livewire:project-picker target="checklists.show" capability="assessments.manage" :label="__('checklists.list.add')" />@endif
    </div>

    <x-hc.card :flush="true">
        <form class="hc-toolbar" method="GET" action="{{ route('checklists.index', ['locale' => $locale]) }}" style="align-items:flex-end;flex-wrap:wrap">
            @if($sort)<input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">@endif
            <div class="hc-field grow">
                <label class="hc-label" for="cl-q">{{ __('checklists.list.q') }}</label>
                <input id="cl-q" name="q" class="hc-input" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('checklists.list.q_placeholder') }}" autocomplete="off">
            </div>
            <div class="hc-field">
                <label class="hc-label" for="cl-state">{{ __('tables.state') }}</label>
                <select id="cl-state" name="state" class="hc-select"><option value="">{{ __('tables.any') }}</option>
                    @foreach($states as $s)<option value="{{ $s->id }}" @selected((int) ($filters['state'] ?? 0) === (int) $s->id)>{{ $en && $s->name_en ? $s->name_en : $s->name_ar }}</option>@endforeach</select>
            </div>
            @if($cities->count() > 1)
                <div class="hc-chips" role="group" aria-label="{{ __('checklists.list.city') }}">
                    @foreach($cities as $c)
                        <label class="hc-chip" style="cursor:pointer"><input type="checkbox" name="cities[]" value="{{ $c->id }}" @checked(in_array((int) $c->id, $chosen, true)) style="margin-inline-end:6px">{{ $en ? $c->name_en : $c->name_ar }}</label>
                    @endforeach
                </div>
            @endif
            <x-hc.button type="submit" variant="secondary" icon="search">{{ __('checklists.list.filter') }}</x-hc.button>
            @if($filters !== [])<x-hc.button variant="ghost" icon="x" :href="route('checklists.index', ['locale' => $locale])">{{ __('checklists.list.clear') }}</x-hc.button>@endif
        </form>
        <div class="hc-toolbar" style="padding-block:0 12px"><span class="muted" aria-live="polite">{{ __('checklists.list.total', ['count' => number_format($rows->total())]) }}</span></div>

        <div class="hc-table-wrap">
            @if($rows->total() === 0)
                <x-hc.empty :title="__('checklists.list.none')" icon="clipboard">{{ __('checklists.list.none_hint') }}</x-hc.empty>
            @else
            <x-hc.sort-select :columns="$headers" :sort="$sort" :dir="$dir" link/>
            <table class="hc-table stack" data-smart="off">
                <thead><tr>@foreach($headers as $key => $title)<x-hc.sort-th :column="$key" :label="$title" :sort="$sort" :dir="$dir" link/>@endforeach</tr></thead>
                <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td data-label="{{ __('checklists.list.number') }}" class="num ltr"><a class="row-link" href="{{ route('checklists.show', ['locale' => $locale, 'project' => $row->project_id]) }}">{{ $row->number }}</a></td>
                        <td data-label="{{ __('checklists.list.beneficiary') }}">{{ $row->beneficiary ?: '—' }}</td>
                        <td data-label="{{ __('checklists.list.project') }}">{{ $row->name ?: '—' }}</td>
                        <td data-label="{{ __('checklists.list.state') }}">@if($row->state_ar)<x-hc.badge :tone="$status::tone($row->state_en)" :icon="$status::icon($row->state_en)">{{ $en && $row->state_en ? $row->state_en : $row->state_ar }}</x-hc.badge>@else — @endif</td>
                        <td data-label="{{ __('checklists.list.city') }}">{{ $row->city }}</td>
                        <td data-label="{{ __('checklists.list.home_visit') }}" class="num ltr">{{ $row->home_visit ?? '—' }}</td>
                        <td data-label="{{ __('checklists.list.project_visit') }}" class="num ltr">{{ $row->project_visit ?? '—' }}</td>
                        <td data-label="{{ __('checklists.list.team') }}" class="num ltr">{{ $row->team ?? '—' }}</td>
                        <td data-label="{{ __('checklists.list.committee') }}" class="num ltr">{{ $row->committee ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
        @include('admin._audit-pages')
    </x-hc.card>
</x-layouts.hc>
