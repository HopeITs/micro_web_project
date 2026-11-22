@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $name = fn ($row) => $en && $row->name_en ? $row->name_en : $row->name_ar;
@endphp
<div>
    @include('partials.filters-style')
    <div class="hc-chips" role="group" aria-label="{{ __('staff.tasks.title') }}" style="margin-block-end:12px">
        <button type="button" class="hc-chip" wire:click="$set('view', '')" aria-pressed="{{ $mine ? 'true' : 'false' }}">{{ __('staff.tasks.mine') }}</button>
        <button type="button" class="hc-chip" wire:click="$set('view', 'grid')" aria-pressed="{{ $mine ? 'false' : 'true' }}">{{ __('staff.tasks.grid') }}</button>
    </div>
    @if($notice)<x-hc.alert type="success">{{ $notice }}</x-hc.alert>@endif

@if($mine)
    <x-hc.card :flush="true">
        <div class="hc-toolbar"><span class="muted" aria-live="polite">{{ __('staff.tasks.mine_total', ['count' => number_format($total)]) }}</span></div>
        @if($projects->isEmpty())
            <x-hc.empty :title="$responsible ? __('staff.tasks.mine_none') : __('staff.tasks.mine_unassigned')" icon="task">{{ $responsible ? __('staff.tasks.mine_none_hint') : __('staff.tasks.mine_unassigned_hint') }}</x-hc.empty>
        @else
            <div class="hc-table-wrap"><table class="hc-table">
                <thead><tr><th>{{ __('staff.tasks.project') }}</th><th>{{ __('staff.tasks.beneficiary') }}</th><th>{{ __('staff.tasks.open_tasks') }}</th></tr></thead>
                <tbody>
                @foreach($projects as $p)
                    <tr wire:key="mine-{{ $p->id }}">
                        <td><a class="num ltr" href="{{ route('projects.show', ['locale' => $locale, 'project' => $p->id]) }}">{{ $p->display }}</a><div class="muted">{{ $p->name }} · {{ $p->city }}</div></td>
                        <td>{{ $p->beneficiary ?: '—' }}</td>
                        <td><div class="hc-chips">
                            @foreach($p->tasks as $t)
                                <button type="button" class="hc-chip" wire:click="toggle({{ $t->cell }}, false)" wire:confirm="{{ __('staff.tasks.confirm_done', ['task' => $t->label]) }}" title="{{ __('staff.tasks.mark_done') }}">{{ $t->label }} <x-hc.icon name="check" :size="16"/></button>
                            @endforeach
                        </div></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-hc.card>
@else
    <x-hc.card :flush="true">
        <div class="hc-filters"><div class="hc-filters-grid" style="--cols:4">
            <div class="hc-field"><label class="hc-label" for="tb-kind">{{ __('staff.tasks.kind') }}</label>
                <select id="tb-kind" class="hc-select" wire:model.live="kind"><option value="f">{{ __('staff.tasks.funded') }}</option><option value="a">{{ __('staff.tasks.applications') }}</option></select></div>
            <div class="hc-field"><label class="hc-label" for="tb-state">{{ __('staff.tasks.state') }}</label>
                <select id="tb-state" class="hc-select" wire:model.live="state"><option value="">{{ __('staff.tasks.any') }}</option>@foreach($options['states'] as $s)<option value="{{ $s->id }}">{{ $name($s) }}</option>@endforeach</select></div>
            <div class="hc-field"><label class="hc-label" for="tb-donor">{{ __('staff.tasks.donor') }}</label>
                <select id="tb-donor" class="hc-select" wire:model.live="donor"><option value="">{{ __('staff.tasks.any') }}</option>@foreach($options['donors'] as $d)<option value="{{ $d->id }}">{{ $d->abbreviation ?: $name($d) }}</option>@endforeach</select></div>
            <div class="hc-field"><label class="hc-label" for="tb-type">{{ __('staff.tasks.type') }}</label>
                <select id="tb-type" class="hc-select" wire:model.live="type"><option value="">{{ __('staff.tasks.any') }}</option>@foreach($options['types'] as $t)<option value="{{ $t->id }}">{{ $name($t) }}</option>@endforeach</select></div>
            <div class="hc-field grow"><label class="hc-label" for="tb-q">{{ __('staff.tasks.search') }}</label>
                <input id="tb-q" class="hc-input" type="search" wire:model.live.debounce.400ms="q" maxlength="100" autocomplete="off"></div>
        </div>
        <details style="margin-block-start:12px"><summary>{{ __('staff.tasks.task_filters') }}</summary>
            <div class="hc-filters-grid" style="--cols:4;margin-block-start:8px">
                @foreach($columns as $c)
                    <div class="hc-field"><label class="hc-label" for="tb-t{{ $c->id }}">{{ $c->label }}</label>
                        <select id="tb-t{{ $c->id }}" class="hc-select" wire:model.live="tasks.{{ $c->id }}"><option value="">{{ __('staff.tasks.any') }}</option><option value="1">{{ __('staff.tasks.done') }}</option><option value="0">{{ __('staff.tasks.open') }}</option></select></div>
                @endforeach
            </div>
        </details></div>
        <div class="hc-toolbar" style="align-items:center;flex-wrap:wrap">
            @if($permitted->count() > 1)
                <div class="hc-chips" role="group" aria-label="{{ __('staff.tasks.city') }}">
                    @foreach($permitted as $c)<button type="button" class="hc-chip" wire:click="toggleCity({{ $c->id }})" aria-pressed="{{ in_array((int) $c->id, array_map('intval', $this->cities), true) ? 'true' : 'false' }}">{{ $en ? $c->name_en : $c->name_ar }}</button>@endforeach
                </div>
            @endif
            <span class="muted" style="margin-inline-start:auto" aria-live="polite">{{ __('staff.tasks.total', ['count' => number_format($rows->total())]) }}</span>
            @if($canExport)
                <x-hc.button variant="ghost" size="sm" icon="file" :href="route('tasks.board.export', ['locale' => $locale] + $query + ['format' => 'xlsx'])">Excel</x-hc.button>
                <x-hc.button variant="ghost" size="sm" icon="file" :href="route('tasks.board.export', ['locale' => $locale] + $query + ['format' => 'csv'])">CSV</x-hc.button>
            @endif
            <x-hc.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('projects.clear_filters') }}</x-hc.button>
        </div>
        <div class="hc-table-wrap" wire:loading.class="opacity-60">
            @if($rows->total() === 0)
                <x-hc.empty :title="__('staff.tasks.empty')" icon="task"/>
            @else
                <table class="hc-table">
                    <thead><tr>
                        <th>{{ __('staff.tasks.project') }}</th><th>{{ __('staff.tasks.beneficiary') }}</th><th>{{ __('staff.tasks.state') }}</th>
                        @if($kind === 'f')<th>{{ __('staff.tasks.loan_date') }}</th>@endif
                        @foreach($columns as $c)<th style="min-width:6rem" title="{{ collect($responsible)->flatMap(fn ($byTask) => $byTask[$c->id] ?? [])->unique()->implode('، ') }}">{{ $c->label }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                    @foreach($rows as $row)
                        <tr wire:key="tb-{{ $row->id }}">
                            <td><a class="num ltr" href="{{ route('projects.show', ['locale' => $locale, 'project' => $row->id]) }}">{{ $row->display }}</a><div class="muted">{{ $row->name }}</div></td>
                            <td>{{ implode('، ', $row->people) ?: '—' }}@if($row->mobile)<div class="muted num ltr">{{ $row->mobile }}</div>@endif</td>
                            <td>{{ $row->state_name ?? '—' }}</td>
                            @if($kind === 'f')<td class="num ltr">{{ $row->loan_day ?? '—' }}</td>@endif
                            @foreach($columns as $c)
                                @php($cell = $row->cells[$c->id])
                                <td>
                                    @if(! $cell['exists'])<span class="muted" title="{{ __('staff.tasks.not_assigned') }}">—</span>
                                    @elseif($cell['manageable'])
                                        <button type="button" class="hc-chip" wire:click="toggle({{ $cell['id'] }}, {{ $cell['done'] ? 'true' : 'false' }})" aria-pressed="{{ $cell['done'] ? 'true' : 'false' }}" title="{{ $cell['done'] ? __('staff.tasks.mark_undone') : __('staff.tasks.mark_done') }}">{{ $cell['done'] ? '✓ '.__('staff.tasks.done') : __('staff.tasks.open') }}</button>
                                    @else<span class="{{ $cell['done'] ? 'text-success' : 'muted' }}">{{ $cell['done'] ? '✓' : '○' }}<span class="sr-only"> {{ $cell['done'] ? __('staff.tasks.done') : __('staff.tasks.open') }}</span></span>@endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        @if($rows->lastPage() > 1)
        <div class="hc-pagination">
            <span>{{ __('hc.page', ['page' => $rows->currentPage(), 'pages' => $rows->lastPage()]) }}</span>
            <div style="display:flex;gap:8px">
                @if(! $rows->onFirstPage())<x-hc.button variant="secondary" size="sm" wire:click="previousPage">{{ __('hc.previous') }}</x-hc.button>@endif
                @if($rows->hasMorePages())<x-hc.button variant="secondary" size="sm" wire:click="nextPage">{{ __('hc.next') }}</x-hc.button>@endif
            </div>
        </div>
        @endif
    </x-hc.card>
@endif
</div>
