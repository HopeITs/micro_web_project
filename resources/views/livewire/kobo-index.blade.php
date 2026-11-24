<div>
    @php
        $en = app()->getLocale() === 'en';
        $locale = app()->getLocale();
        $label = fn (string $group, ?string $value) => $value === null ? '' : (trans()->has('kobo.'.$group.'.'.$value) ? __('kobo.'.$group.'.'.$value) : $value);
        $tone = fn (string $status) => ['imported' => 'ok', 'error' => 'danger', 'deleted' => 'warn', 'reviewed' => 'info'][$status] ?? null;
    @endphp

    <div class="hc-chips" role="tablist" style="margin-block-end:16px">
        @foreach(\App\Livewire\KoboIndex::TABS as $t)
            <button type="button" role="tab" class="hc-chip" wire:click="setTab('{{ $t }}')" aria-selected="{{ $tab === $t ? 'true' : 'false' }}" aria-pressed="{{ $tab === $t ? 'true' : 'false' }}">
                {{ __('kobo.tabs.'.$t) }}
                @if($t === 'submissions')<span class="muted num">{{ number_format($counts['all']) }}</span>@endif
                @if($t === 'reviews' && ($counts['conflicts'] + $counts['reviews']) > 0)<x-hc.badge tone="warn">{{ $counts['conflicts'] + $counts['reviews'] }}</x-hc.badge>@endif
            </button>
        @endforeach
    </div>

    <x-hc.alert type="info" style="margin-block-end:16px">{{ __('kobo.read_only') }}</x-hc.alert>

    {{-- Shared filters: cities always; the rest for submissions and results --}}
    <x-hc.card :flush="true" style="margin-block-end:16px">
        <div class="hc-toolbar" style="align-items:center">
            <span class="hc-label">{{ __('hc.cities') }}</span>
            <div class="hc-chips" role="group" aria-label="{{ __('hc.cities') }}">
                @foreach($cityOptions as $c)
                    <button type="button" class="hc-chip" wire:click="toggleCity({{ $c->id }})" aria-pressed="{{ in_array((int) $c->id, $cities, true) ? 'true' : 'false' }}">{{ $en ? $c->name_en : $c->name_ar }} <span class="muted ltr">{{ $c->code }}</span></button>
                @endforeach
            </div>
        </div>
        @if(in_array($tab, ['submissions', 'results'], true))
        <div class="hc-toolbar" style="align-items:end">
            <x-hc.field :label="__('kobo.search')" for="k-q" class="grow"><input id="k-q" class="hc-input" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ $tab === 'results' ? __('kobo.search_number') : __('kobo.search_placeholder') }}" autocomplete="off"></x-hc.field>
            <x-hc.field :label="__('kobo.form')" for="k-form">
                <select id="k-form" class="hc-select" wire:model.live="form"><option value="">{{ __('kobo.all_forms') }}</option>
                    @foreach($forms->groupBy('city_code') as $code => $list)<optgroup label="{{ $code }}">@foreach($list as $f)<option value="{{ $f->form_uid }}">{{ $f->form_name }}</option>@endforeach</optgroup>@endforeach
                </select>
            </x-hc.field>
            @if($tab === 'submissions')
            <x-hc.field :label="__('kobo.type')" for="k-type"><select id="k-type" class="hc-select" wire:model.live="type"><option value="">{{ __('hc.all') }}</option>@foreach(['opening', 'monitoring', 'final'] as $t)<option value="{{ $t }}">{{ __('kobo.types.'.$t) }}</option>@endforeach</select></x-hc.field>
            <x-hc.field :label="__('kobo.status')" for="k-status"><select id="k-status" class="hc-select" wire:model.live="status"><option value="">{{ __('hc.all') }}</option>@foreach(array_keys(\App\Services\Kobo\KoboReadService::STATUSES) as $s)<option value="{{ $s }}">{{ __('kobo.statuses.'.$s) }} ({{ $counts[$s] }})</option>@endforeach</select></x-hc.field>
            @endif
            <x-hc.field :label="__('kobo.from')" for="k-from"><input id="k-from" type="date" class="hc-input ltr" wire:model.live="from"></x-hc.field>
            <x-hc.field :label="__('kobo.to')" for="k-to"><input id="k-to" type="date" class="hc-input ltr" wire:model.live="to"></x-hc.field>
            @if($tab === 'results')
                <div class="hc-choice" style="padding-block-end:6px"><label><input type="checkbox" wire:model.live="last">{{ __('kobo.last_only') }}</label></div>
            @endif
            <x-hc.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">{{ __('hc.clear') }}</x-hc.button>
        </div>
        @endif
    </x-hc.card>

    <div wire:loading.class="opacity-60">
    @if($tab === 'submissions')
        <x-hc.card :flush="true">
            <div class="hc-toolbar"><span class="muted" aria-live="polite">{{ __('hc.results', ['count' => number_format($submissions->total())]) }}</span></div>
            <div class="hc-table-wrap">
                @if($submissions->isEmpty())
                    <x-hc.empty :title="__('kobo.none')">{{ __('kobo.none_hint') }}</x-hc.empty>
                @else
                <table class="hc-table stack">
                    <thead><tr><th>{{ __('kobo.date') }}</th><th>{{ __('kobo.form') }}</th><th>{{ __('hc.number') }}</th><th>{{ __('hc.beneficiary') }}</th><th>{{ __('kobo.stage') }}</th><th>{{ __('kobo.visitors') }}</th><th>{{ __('kobo.status') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach($submissions as $s)
                        <tr wire:key="ks-{{ $s->id }}" @if($open === (int) $s->id) style="background:var(--surface-2)" @endif>
                            <td data-label="{{ __('kobo.date') }}" class="num">{{ $s->day ?? '—' }}</td>
                            <td data-label="{{ __('kobo.form') }}">{{ $s->form_name }} @if($s->visit_type)<span class="muted" style="font-size:.85em">· {{ __('kobo.types.'.$s->visit_type) }}@if($s->visit_category) · {{ $label('categories', $s->visit_category) }}@endif</span>@endif</td>
                            <td data-label="{{ __('hc.number') }}">@if($s->project_id)<a class="row-link ltr" href="{{ route('projects.show', ['locale' => $locale, 'project' => $s->project_id]) }}">{{ $s->number }}</a>@else<span class="muted">—</span>@endif</td>
                            <td data-label="{{ __('hc.beneficiary') }}">{{ $s->person ?? '—' }}</td>
                            <td data-label="{{ __('kobo.stage') }}">{{ $s->visit_number ?: ($s->visit_kind ? __('kobo.types.opening') : '—') }}</td>
                            <td data-label="{{ __('kobo.visitors') }}">{{ $s->visitors ? implode('، ', $s->visitors) : '—' }}</td>
                            <td data-label="{{ __('kobo.status') }}"><x-hc.badge :tone="$tone($s->status)">{{ __('kobo.statuses.'.$s->status) }}</x-hc.badge></td>
                            <td style="white-space:nowrap"><x-hc.button size="sm" variant="ghost" wire:click="show({{ $s->id }})">{{ $open === (int) $s->id ? __("kobo.hide") : __("kobo.answers") }}</x-hc.button>@if($s->project_id && $s->local_table)<a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route("kobo.visit.show", ["locale" => $locale, "submission" => $s->id]) }}">{{ __("kobo.edit") }}</a>@endif</td>
                        </tr>
                        @if($open === (int) $s->id && $detail)
                        <tr wire:key="ks-{{ $s->id }}-detail"><td colspan="8" style="background:var(--surface-2)">
                            @if($detail->error_message)<x-hc.alert type="danger" :title="__('kobo.error')" style="margin-block-end:12px">{{ $detail->error_message }}</x-hc.alert>@endif
                            <p class="muted" style="font-size:.85rem;margin-block-end:8px">{{ __('kobo.submitted', ['by' => $detail->submitted_by ?: '—', 'at' => substr((string) $detail->submitted_at, 0, 16)]) }}</p>
                            @unless($detail->known_form)<p class="hc-hint">{{ __('kobo.unknown_form') }}</p>@endunless
                            <div class="hc-grid" style="grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr)); gap:12px">
                            @foreach($detail->answers as $section)
                                <div><h4 class="hc-label" style="margin-block-end:6px">{{ $section['section'] }}</h4>
                                    <dl class="hc-dl" style="grid-template-columns:1fr">@foreach($section['items'] as $item)<div><dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd></div>@endforeach</dl></div>
                            @endforeach
                            </div>
                        </td></tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
                @endif
            </div>
            @if($submissions->lastPage() > 1)
            <div class="hc-pagination"><span>{{ __('hc.page', ['page' => $submissions->currentPage(), 'pages' => $submissions->lastPage()]) }}</span>
                <nav aria-label="pagination"><x-hc.button variant="secondary" size="sm" wire:click="previousPage" :disabled="$submissions->onFirstPage()">{{ __('hc.previous') }}</x-hc.button>
                <x-hc.button variant="secondary" size="sm" wire:click="nextPage" :disabled="! $submissions->hasMorePages()">{{ __('hc.next') }}</x-hc.button></nav></div>
            @endif
        </x-hc.card>

    @elseif($tab === 'results')
        <div class="hc-chips" role="group" style="margin-block-end:12px">
            <button type="button" class="hc-chip" wire:click="$set('mode', 'counts')" aria-pressed="{{ $mode === 'counts' ? 'true' : 'false' }}">{{ __('kobo.mode_counts') }}</button>
            <button type="button" class="hc-chip" wire:click="$set('mode', 'answers')" aria-pressed="{{ $mode === 'answers' ? 'true' : 'false' }}">{{ __('kobo.mode_answers') }}</button>
            @if($canExport && $mode === 'answers')
                <a class="hc-btn hc-btn-secondary hc-btn-sm" style="margin-inline-start:auto" href="{{ route('kobo.export', array_filter(['locale' => $locale, 'city' => $cities, 'form' => $form, 'from' => $from, 'to' => $to, 'last' => $last ? 1 : null])) }}"><x-hc.icon name="file" :size="16"/>Excel</a>
            @endif
        </div>
        @if($mode === 'counts')
            <p class="muted" style="margin-block-end:8px">{{ __('kobo.confirmed', ['count' => number_format($results['submissions'])]) }}</p>
            @forelse($results['questions'] as $q)
                <x-hc.card style="margin-block-end:12px" :title="$q['title']" :subtitle="($q['multiple'] ? __('kobo.multiple').' · ' : '').__('kobo.answered', ['answered' => $q['answered'], 'blank' => $q['blank']])">
                    <div style="display:grid;gap:6px">
                    @php($max = max(1, ...array_column($q['options'], 'count')))
                    @foreach($q['options'] as $o)
                        <div style="display:grid;grid-template-columns:minmax(120px, 34%) 1fr 48px;gap:10px;align-items:center">
                            <span>{{ $o['label'] }}</span>
                            <span class="hc-progress" aria-hidden="true"><span style="width: {{ round($o['count'] / $max * 100) }}%"></span></span>
                            <span class="num" style="text-align:end">{{ $o['count'] }}</span>
                        </div>
                    @endforeach
                    </div>
                </x-hc.card>
            @empty
                <x-hc.empty :title="__('kobo.none')">{{ __('kobo.none_hint') }}</x-hc.empty>
            @endforelse
        @else
            <x-hc.card :flush="true">
                <div class="hc-toolbar"><span class="muted">{{ __('kobo.rows', ['count' => number_format(count($results['rows'])), 'columns' => count($results['columns'])]) }}</span>
                    @if(count($results['rows']) > 300)<span class="muted">· {{ __('kobo.first_rows') }}</span>@endif</div>
                <div class="hc-table-wrap" style="max-height:70vh;overflow:auto">
                @if($results['rows'] === [])
                    <x-hc.empty :title="__('kobo.none')">{{ __('kobo.none_hint') }}</x-hc.empty>
                @else
                <table class="hc-table" style="font-size:.88rem">
                    <thead><tr>@foreach(['number', 'person', 'subcategory', 'career', 'stage', 'date'] as $c)<th style="white-space:nowrap;position:sticky;top:0;background:var(--surface)">{{ __('kobo.context.'.$c) }}</th>@endforeach
                        @foreach($results['columns'] as $title)<th style="min-width:145px;position:sticky;top:0;background:var(--surface)" title="{{ $title }}">{{ \Illuminate\Support\Str::limit($title, 60) }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach(array_slice($results['rows'], 0, 300) as $r)
                        <tr wire:key="ka-{{ $r['id'] }}">
                            <td><a class="row-link ltr" href="{{ route('projects.show', ['locale' => $locale, 'project' => $r['project_id']]) }}">{{ $r['context']['number'] }}</a></td>
                            @foreach(['person', 'subcategory', 'career', 'stage', 'date'] as $c)<td>{{ $r['context'][$c] ?? '—' }}</td>@endforeach
                            @foreach(array_keys($results['columns']) as $key)<td>{{ $r['answers'][$key] ?? '' }}</td>@endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @endif
                </div>
            </x-hc.card>
        @endif

    @elseif($tab === 'reviews')
        <x-hc.card :title="__('kobo.conflicts', ['count' => $conflicts->count()])" :subtitle="__('kobo.conflicts_hint')" :flush="true" style="margin-block-end:16px">
            @forelse($conflicts as $k)
                <details style="border-block-start:1px solid var(--line);padding:12px 20px" @if($k->state !== 'resolved') open @endif>
                    <summary style="cursor:pointer;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                        <x-hc.badge :tone="$k->state === 'resolved' ? 'ok' : 'warn'">{{ $label('states', $k->state) }}</x-hc.badge>
                        @if($k->decision)<x-hc.badge>{{ $label('decisions', $k->decision) }}</x-hc.badge>@endif
                        <strong>{{ $k->form_name }}</strong>
                        @if($k->project_id)<a class="row-link ltr" href="{{ route('projects.show', ['locale' => $locale, 'project' => $k->project_id]) }}">{{ $k->number }}</a>@endif
                        <span class="muted">{{ __('kobo.differences', ['count' => count($k->differences)]) }}</span>
                    </summary>
                    @if($k->reason)<p style="margin-block:8px"><b>{{ __('kobo.reason') }}:</b> {{ $k->reason }} <span class="muted">— {{ $k->decided_by_name }} {{ substr((string) $k->decided_at, 0, 16) }}</span></p>@endif
                    @if($k->differences)
                    <div class="hc-table-wrap"><table class="hc-table stack" style="font-size:.88rem">
                        <thead><tr><th>{{ __('kobo.question') }}</th><th>{{ __('kobo.incoming') }}</th><th>{{ __('kobo.existing') }}</th></tr></thead>
                        <tbody>@foreach($k->differences as $d)<tr><td data-label="{{ __('kobo.question') }}">{{ $d['label'] }}</td><td data-label="{{ __('kobo.incoming') }}">{{ $d['incoming'] ?? __('kobo.missing') }}</td><td data-label="{{ __('kobo.existing') }}">{{ $d['existing'] ?? __('kobo.missing') }}</td></tr>@endforeach</tbody>
                    </table></div>
                    @endif
                    @if($k->history->isNotEmpty())
                        <ul class="muted" style="font-size:.85rem;margin-block-start:8px">@foreach($k->history as $h)<li>{{ substr((string) $h->recorded_at, 0, 16) }} · {{ $h->actor_name ?: '—' }} · {{ $label('events', $h->event) }}@if($h->decision) · {{ $label('decisions', $h->decision) }}@endif @if($h->reason) — {{ $h->reason }}@endif</li>@endforeach</ul>
                    @endif
                </details>
            @empty
                <p class="muted" style="padding:16px 20px">{{ __('kobo.no_conflicts') }}</p>
            @endforelse
        </x-hc.card>

        <x-hc.card :title="__('kobo.person_reviews', ['count' => $reviews->count()])" :subtitle="__('kobo.person_reviews_hint')" :flush="true">
            @forelse($reviews as $r)
                <div style="border-block-start:1px solid var(--line);padding:12px 20px">
                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                        <x-hc.badge :tone="$r->state === 'confirmed' ? 'ok' : 'warn'">{{ $label('states', $r->state) }}</x-hc.badge>
                        <strong>{{ __('kobo.kobo_name') }}: {{ $r->kobo_name ?: '—' }}</strong>
                        @if($r->project_id)<a class="row-link ltr" href="{{ route('projects.show', ['locale' => $locale, 'project' => $r->project_id]) }}">{{ $r->number }}</a>@endif
                        <span class="muted">{{ $r->form_name }} · {{ substr((string) $r->submitted_at, 0, 10) }}</span>
                    </div>
                    <p style="margin-block:6px"><b>{{ __('kobo.chosen') }}:</b> {{ $r->chosen ?? __('kobo.not_chosen') }}</p>
                    @if($r->candidates->isNotEmpty())<p class="muted" style="font-size:.88rem">{{ __('kobo.candidates') }}: {{ $r->candidates->map(fn ($c) => $c->name.($c->role ? ' ('.$c->role.')' : ''))->implode('، ') }}</p>@endif
                    @foreach($r->history as $h)<p class="muted" style="font-size:.85rem">{{ substr((string) $h->recorded_at, 0, 16) }} · {{ $h->actor_name ?: '—' }} · {{ $h->decision }}@if($h->reason) — {{ $h->reason }}@endif</p>@endforeach
                </div>
            @empty
                <p class="muted" style="padding:16px 20px">{{ __('kobo.no_reviews') }}</p>
            @endforelse
        </x-hc.card>

    @else
        <div class="hc-grid" style="grid-template-columns: repeat(auto-fit, minmax(min(100%, 460px), 1fr)); align-items:start">
            <x-hc.card :title="__('kobo.mappings', ['count' => $visitors['mappings']->count()])" :subtitle="__('kobo.mappings_hint')" :flush="true">
                <div class="hc-table-wrap" style="max-height:70vh;overflow:auto"><table class="hc-table stack" style="font-size:.88rem">
                    <thead><tr><th>{{ __('kobo.kobo_name') }}</th><th>{{ __('kobo.display_name') }}</th><th>{{ __('kobo.account') }}</th><th>{{ __('kobo.form') }}</th></tr></thead>
                    <tbody>@forelse($visitors['mappings'] as $m)
                        <tr @unless($m->is_active) style="opacity:.6" @endunless><td data-label="{{ __('kobo.kobo_name') }}" class="ltr">{{ $m->kobo_name }} <span class="muted">{{ $m->city_code }}</span></td>
                            <td data-label="{{ __('kobo.display_name') }}">@if($m->is_ignored)<x-hc.badge>{{ __('kobo.ignored') }}</x-hc.badge>@else{{ $m->display_name ?: '—' }}@endif</td>
                            <td data-label="{{ __('kobo.account') }}" class="ltr">{{ $m->username ?? '—' }}</td>
                            <td data-label="{{ __('kobo.form') }}">{{ $m->form_uid ? ($m->form_name ?? $m->form_uid) : __('kobo.all_forms') }}</td></tr>
                    @empty<tr><td colspan="4" class="muted">{{ __('kobo.none') }}</td></tr>@endforelse</tbody>
                </table></div>
            </x-hc.card>
            <x-hc.card :title="__('kobo.unlinked', ['count' => $visitors['unlinked']->count()])" :subtitle="__('kobo.unlinked_hint')" :flush="true">
                <div class="hc-table-wrap" style="max-height:70vh;overflow:auto"><table class="hc-table stack" style="font-size:.88rem">
                    <thead><tr><th>{{ __('kobo.display_name') }}</th><th>{{ __('hc.city') }}</th><th class="num">{{ __('kobo.visits') }}</th></tr></thead>
                    <tbody>@forelse($visitors['unlinked'] as $u)<tr><td data-label="{{ __('kobo.display_name') }}">{{ $u->display_name ?: '—' }}</td><td data-label="{{ __('hc.city') }}">{{ $u->city_code }}</td><td data-label="{{ __('kobo.visits') }}" class="num">{{ $u->visits }}</td></tr>
                    @empty<tr><td colspan="3" class="muted">{{ __('kobo.none') }}</td></tr>@endforelse</tbody>
                </table></div>
            </x-hc.card>
        </div>
    @endif
    </div>
</div>
