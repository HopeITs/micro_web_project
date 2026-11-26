@php
    $en = app()->getLocale() === 'en';
    $name = fn ($ar, $enName) => $en && $enName ? $enName : $ar;
    $locale = app()->getLocale();
@endphp
<div>
    @include('partials.filters-style')
    @if($notice !== '')<x-hc.alert type="success" style="margin-block-end:12px">{{ $notice }}</x-hc.alert>@endif
    <x-hc.card :flush="true">
        <div class="hc-filters"><div class="hc-filters-grid" style="--cols:5">
            <div class="hc-field">
                <label class="hc-label" for="vs-year">{{ __('visits.schedule.year') }}</label>
                <select id="vs-year" class="hc-select" wire:model.live="year">
                    <option value="">{{ __('visits.schedule.all') }}</option>
                    @for($y = now()->year + 2; $y >= 2018; $y--)<option value="{{ $y }}">{{ $y }}</option>@endfor
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="vs-kind">{{ __('visits.schedule.kind') }}</label>
                <select id="vs-kind" class="hc-select" wire:model.live="kind">
                    <option value="">{{ __('visits.schedule.all') }}</option>
                    <option value="V">{{ __('visits.schedule.vehicles') }}</option>
                    <option value="O">{{ __('visits.schedule.others') }}</option>
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="vs-visit">{{ __('visits.schedule.visit') }}</label>
                <select id="vs-visit" class="hc-select" wire:model.live="visit">
                    <option value="">{{ __('visits.schedule.all') }}</option>
                    @foreach(\App\Services\Field\VisitScheduleService::VISITS as $v)<option value="{{ $v }}">{{ __('visits.schedule.visits.'.$v) }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="vs-street">{{ __('visits.schedule.street') }}</label>
                <select id="vs-street" class="hc-select" wire:model.live="street">
                    <option value="">{{ __('visits.schedule.all') }}</option>
                    @foreach($streets as $s)<option value="{{ $s->id }}">{{ $name($s->name_ar, $s->name_en) }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field">
                <label class="hc-label" for="vs-type">{{ __('visits.schedule.type') }}</label>
                <select id="vs-type" class="hc-select" wire:model.live="type">
                    <option value="">{{ __('visits.schedule.all') }}</option>
                    @foreach($types as $t)<option value="{{ $t->id }}">{{ $name($t->name_ar, $t->name_en) }}</option>@endforeach
                </select>
            </div>
            <div class="hc-field grow">
                <label class="hc-label" for="vs-q">{{ __('visits.schedule.q') }}</label>
                <input id="vs-q" class="hc-input" type="search" wire:model.live.debounce.400ms="q" placeholder="{{ __('visits.schedule.q_hint') }}" maxlength="100" autocomplete="off">
            </div>
            <div class="hc-field" style="align-self:center">
                <label class="hc-check"><input type="checkbox" wire:model.live="hidden"> {{ __('visits.schedule.hidden') }}</label>
            </div>
        </div></div>
        <div class="hc-toolbar" style="align-items:center;flex-wrap:wrap">
            <span class="hc-label">{{ __('visits.schedule.months') }}</span>
            <div class="hc-chips" role="group" aria-label="{{ __('visits.schedule.months') }}">
                @for($m = 1; $m <= 12; $m++)
                    <button type="button" class="hc-chip" wire:click="toggleMonth({{ $m }})" aria-pressed="{{ in_array($m, array_map('intval', $this->months), true) ? 'true' : 'false' }}">{{ $m }}</button>
                @endfor
            </div>
        </div>
        <div class="hc-toolbar" style="align-items:center;flex-wrap:wrap">
            @if($cityOptions->count() > 1)
                <span class="hc-label">{{ __('visits.schedule.cities') }}</span>
                <div class="hc-chips" role="group" aria-label="{{ __('visits.schedule.cities') }}">
                    @foreach($cityOptions as $c)
                        <button type="button" class="hc-chip" wire:click="toggleCity({{ $c->id }})" aria-pressed="{{ in_array((int) $c->id, array_map('intval', $this->cities), true) ? 'true' : 'false' }}">{{ $en ? $c->name_en : $c->name_ar }} <span class="muted ltr">{{ $c->code }}</span></button>
                    @endforeach
                </div>
            @endif
            <span class="muted" style="margin-inline-start:auto" aria-live="polite">{{ __('hc.results', ['count' => number_format($rows->total())]) }}</span>
            @if($canExport)
                <x-hc.export-buttons to="visits.schedule.export" :query="$filters" :private="$searching" :xlsx="__('visits.schedule.export_xlsx')" :csv="__('visits.schedule.export_csv')"/>
            @endif
            <x-hc.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('visits.schedule.clear') }}</x-hc.button>
        </div>

        <div class="hc-table-wrap" wire:loading.class="opacity-60">
            @if($rows->isEmpty())
                <x-hc.empty :title="__('visits.schedule.none')" icon="calendar">{{ __('visits.schedule.none_hint') }}</x-hc.empty>
            @else
            @php($sortable = \App\Services\Field\VisitScheduleService::SORT_KEYS)
            <x-hc.sort-select :columns="collect($sortable)->mapWithKeys(fn ($col) => [$col => __('visits.schedule.columns.'.$col)])->all()" :sort="$sortKey" :dir="$sortDir"/>
            <table class="hc-table stack" data-smart="off">
                <thead><tr>
                    @foreach(['number', 'project', 'beneficiary', 'state', 'donor', 'type', 'visit', 'date', 'home_area', 'project_area', 'mobile', 'next_due', 'status', 'note'] as $col)@if(in_array($col, $sortable, true))<x-hc.sort-th :column="$col" :label="__('visits.schedule.columns.'.$col)" :sort="$sortKey" :dir="$sortDir"/>@else<th>{{ __('visits.schedule.columns.'.$col) }}</th>@endif @endforeach
                    <th></th>
                </tr></thead>
                <tbody>
                @foreach($rows as $r)
                    @php($manage = in_array((int) $r->city_id, $manageCities, true))
                    <tr wire:key="notice-{{ $r->id }}">
                        <td data-label="{{ __('visits.schedule.columns.number') }}"><span class="ltr num">{{ $r->display_number }}</span></td>
                        <td data-label="{{ __('visits.schedule.columns.project') }}">{{ $r->project_name ?: '—' }}</td>
                        <td data-label="{{ __('visits.schedule.columns.beneficiary') }}">{{ $r->title ?: '—' }}</td>
                        <td data-label="{{ __('visits.schedule.columns.state') }}">{{ $r->state_ar ? $name($r->state_ar, $r->state_en) : '—' }}</td>
                        <td data-label="{{ __('visits.schedule.columns.donor') }}">{{ $r->donor_ar ? $name($r->donor_ar, $r->donor_en) : '—' }}</td>
                        <td data-label="{{ __('visits.schedule.columns.type') }}">{{ $r->type_ar ? $name($r->type_ar, $r->type_en) : '—' }}</td>
                        <td data-label="{{ __('visits.schedule.columns.visit') }}"><span class="ltr">{{ $r->body }}</span> @if($r->seen)<x-hc.badge tone="neutral">{{ __('visits.schedule.hidden_badge') }}</x-hc.badge>@endif</td>
                        <td data-label="{{ __('visits.schedule.columns.date') }}" class="num">{{ $r->notice_date }}@if($r->original_date)<br><small class="muted">{{ __('visits.schedule.original', ['date' => $r->original_date]) }}</small>@endif</td>
                        <td data-label="{{ __('visits.schedule.columns.home_area') }}">{{ $r->home_area ?: '—' }}</td>
                        <td data-label="{{ __('visits.schedule.columns.project_area') }}">{{ $r->project_area ?: '—' }}</td>
                        <td data-label="{{ __('visits.schedule.columns.mobile') }}" class="ltr">{{ $r->mobile ?: '—' }}</td>
                        <td data-label="{{ __('visits.schedule.columns.next_due') }}" class="num">{{ $r->next_due ?: '—' }}</td>
                        <td data-label="{{ __('visits.schedule.columns.status') }}">@if($r->status === 'done')<x-hc.badge tone="ok">{{ __('visits.schedule.done') }}</x-hc.badge>@elseif($r->status === 'na') — @else<x-hc.badge tone="warn">{{ __('visits.schedule.not_yet') }}</x-hc.badge>@endif</td>
                        <td data-label="{{ __('visits.schedule.columns.note') }}">{{ $r->note ?: '—' }}@if($r->change_reason)<br><small class="muted">{{ $r->change_reason }}</small>@endif</td>
                        <td class="full">
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                @if($r->project_id)<a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('visits.hub', ['locale' => $locale, 'project' => $r->project_id]) }}"><x-hc.icon name="map" :size="16"/>{{ __('visits.schedule.open_project') }}</a>@endif
                                @if($manage)
                                    @if($r->seen)
                                        <x-hc.button size="sm" variant="ghost" wire:click="setHidden({{ $r->id }}, false)" wire:confirm="{{ __('visits.schedule.restore_confirm') }}">{{ __('visits.schedule.restore') }}</x-hc.button>
                                    @else
                                        <x-hc.button size="sm" variant="ghost" wire:click="setHidden({{ $r->id }}, true)" wire:confirm="{{ __('visits.schedule.hide_confirm') }}">{{ __('visits.schedule.hide') }}</x-hc.button>
                                        @if($r->kind === 'visit')<x-hc.button size="sm" variant="ghost" wire:click="edit({{ $r->id }}, 'reschedule')">{{ __('visits.schedule.reschedule') }}</x-hc.button>@endif
                                    @endif
                                    <x-hc.button size="sm" variant="ghost" wire:click="edit({{ $r->id }}, 'note', @js((string) $r->note))">{{ __('visits.schedule.note') }}</x-hc.button>
                                @endif
                            </div>
                            @if($editing === (int) $r->id && $mode === 'note')
                                <form wire:submit="saveNote" class="hc-grid" style="gap:8px;margin-block-start:8px">
                                    <textarea class="hc-textarea" rows="2" wire:model="noteText" aria-label="{{ __('visits.schedule.note') }}"></textarea>
                                    @error('note')<p class="hc-error" role="alert">{{ $message }}</p>@enderror
                                    <div style="display:flex;gap:6px"><x-hc.button size="sm" type="submit" icon="check">{{ __('visits.schedule.save_note') }}</x-hc.button><x-hc.button size="sm" variant="ghost" wire:click="cancel">{{ __('visits.schedule.cancel') }}</x-hc.button></div>
                                </form>
                            @elseif($editing === (int) $r->id && $mode === 'reschedule')
                                <form wire:submit="saveReschedule" class="hc-grid" style="gap:8px;margin-block-start:8px">
                                    <x-hc.field :label="__('visits.schedule.new_date')" for="vs-date-{{ $r->id }}" :error="$errors->first('date')" required>
                                        <input class="hc-input num ltr" type="date" id="vs-date-{{ $r->id }}" wire:model="newDate">
                                    </x-hc.field>
                                    <x-hc.field :label="__('visits.schedule.reason')" for="vs-reason-{{ $r->id }}" :error="$errors->first('reason')" required>
                                        <input class="hc-input" id="vs-reason-{{ $r->id }}" wire:model="reason" maxlength="500">
                                    </x-hc.field>
                                    <label class="hc-check"><input type="checkbox" wire:model="shiftLater"> {{ __('visits.schedule.shift_later') }}</label>
                                    <div style="display:flex;gap:6px"><x-hc.button size="sm" type="submit" icon="check">{{ __('visits.schedule.reschedule') }}</x-hc.button><x-hc.button size="sm" variant="ghost" wire:click="cancel">{{ __('visits.schedule.cancel') }}</x-hc.button></div>
                                </form>
                            @endif
                        </td>
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
</div>
