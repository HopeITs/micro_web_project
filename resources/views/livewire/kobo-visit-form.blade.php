@php
    $en = app()->getLocale() === 'en';
    $h = $form['header'];
    $manage = $form['canManage'];
    $f = $form['form'];
    $spec = $form['spec'];
    $opening = $f && $f->visit_type === 'opening';
    $submission = $form['submission'];
    $category = fn ($c) => trans()->has('kobo.categories.'.$c) ? __('kobo.categories.'.$c) : $c;
    $stageNote = $f && ! $opening && ($f->visit_type === 'final' || $f->fixed_stage !== null || in_array($f->visit_category, ['agriculture', 'livestock'], true));
@endphp
<div class="hc-grid">
    @if($notice !== '')<x-hc.alert type="success">{{ $notice }}</x-hc.alert>@endif
    @foreach(['version', 'visit', 'form', 'person', 'visitors', 'date', 'r'] as $key)
        @error($key)<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror
    @endforeach
    @error('a')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror
    @if($form['locked'])<x-hc.alert type="info">{{ __('kobo.visit.synced_locked') }}</x-hc.alert>
    @elseif(! $manage && $f)<x-hc.alert type="info">{{ __('visits.read_only') }}</x-hc.alert>@endif

    <x-hc.card>
        <dl class="hc-dl">
            <div><dt>{{ __('visits.header.number') }}</dt><dd class="ltr num">{{ $h['number'] }}</dd></div>
            <div><dt>{{ __('visits.header.beneficiary') }}</dt><dd>{{ $h['beneficiary'] ?: '—' }}</dd></div>
            <div><dt>{{ __('visits.header.project') }}</dt><dd>{{ $h['name'] ?: '—' }}</dd></div>
            <div><dt>{{ __('visits.header.phone') }}</dt><dd class="ltr">{{ $h['phone'] ?: '—' }}</dd></div>
            <div><dt>{{ __('visits.header.amount') }}</dt><dd class="num">{{ $h['amount'] ?? __('visits.header.no_loan') }}</dd></div>
            <div><dt>{{ __('visits.header.loan_date') }}</dt><dd class="num">{{ $h['loan_date'] ?: '—' }}</dd></div>
        </dl>
        @if($submission)<p class="hc-hint" style="margin:12px 0 0">{{ __('kobo.submitted', ['by' => $submission->submitted_by ?: '—', 'at' => substr((string) $submission->submitted_at, 0, 16)]) }} @if($form['synced'])· {{ __('kobo.visit.from_kobo') }}@else · {{ __('kobo.visit.from_web') }}@endif</p>@endif
    </x-hc.card>

    @if(! $submission)
        <x-hc.card :title="__('kobo.visit.choose_form')">
            @if($form['forms'] === [])
                <x-hc.empty :title="__('kobo.visit.no_forms')">{{ __('kobo.visit.no_forms_hint') }}</x-hc.empty>
            @else
                <x-hc.field :label="__('kobo.form')" for="kv-form">
                    <select class="hc-select" id="kv-form" wire:model.live="formUid">
                        <option value="">—</option>
                        @foreach($form['forms'] as $option)
                            <option value="{{ $option->uid }}">{{ $option->name }} · {{ __('kobo.types.'.$option->type) }} · {{ $category($option->category) }}@if($option->suggested) ★@endif</option>
                        @endforeach
                    </select>
                </x-hc.field>
                <p class="hc-hint" style="margin:8px 0 0">{{ __('kobo.visit.choose_hint') }}</p>
            @endif
        </x-hc.card>
    @else
        <x-hc.card>
            <p style="margin:0"><strong>{{ $submission->form_name ?: $f?->form_name }}</strong>
                @if($f)<span class="muted"> · {{ __('kobo.types.'.$f->visit_type) }} · {{ $category($f->visit_category) }}</span>@endif</p>
        </x-hc.card>
    @endif

    @if($f && $spec)
    <form wire:submit="save" class="hc-grid">
        <x-hc.card :title="__('kobo.visit.basics')">
            <div class="hc-form-grid">
                <x-hc.field :label="__('visits.fields.date')" for="kv-date" :error="$errors->first('date')" required>
                    <input class="hc-input num ltr" type="date" id="kv-date" wire:model="date" max="{{ now()->toDateString() }}" @disabled(! $manage)>
                </x-hc.field>
                @if(! $opening)
                    @if($submission)
                        <div class="hc-field"><span class="hc-label">{{ __('kobo.visit.person') }}</span><p style="margin:0">{{ $form['people']->firstWhere('id', (int) $person)->name ?? '—' }}</p></div>
                    @else
                        <x-hc.field :label="__('kobo.visit.person')" for="kv-person" :error="$errors->first('person')" required>
                            <select class="hc-select" id="kv-person" wire:model="person">
                                @foreach($form['people'] as $p)<option value="{{ $p->id }}">{{ $p->name }}@if(! $p->beneficiary) · {{ $p->role ?: __('visits.me.partner') }}@endif</option>@endforeach
                            </select>
                        </x-hc.field>
                    @endif
                @endif
            </div>
            @if($stageNote)<p class="hc-hint" style="margin:8px 0 0">{{ in_array($f->visit_category, ['agriculture', 'livestock'], true) && $f->visit_type !== 'final' ? __('kobo.visit.stage_production') : __('kobo.visit.stage_fixed') }}</p>@endif
        </x-hc.card>

        @php $section = null; $shown = []; @endphp
        @foreach($spec['fields'] as $field)
            @continue(in_array($field['role'], ['project', 'person', 'visitor'], true) || ($field['group'] !== null && isset($shown[$field['group']])))
            @if($field['section'] !== $section)
                @if($section !== null)</div></div></section>@endif
                @php $section = $field['section']; @endphp
                <section class="hc-card"><header class="hc-card-head"><div><h2>{{ $section ?: __('kobo.visit.other_section') }}</h2></div></header><div class="hc-card-body"><div class="hc-form-grid">
            @endif
            @if($field['group'] === null)
                @include('livewire.partials.kobo-field', ['field' => $field, 'model' => 'a.'.$field['index'], 'manage' => $manage])
            @else
                @php $g = $spec['groups'][$field['group']]; $shown[$field['group']] = true; $groupFields = array_values(array_filter($spec['fields'], fn ($x) => $x['group'] === $field['group'])); @endphp
                <div style="grid-column:1/-1" class="hc-grid" wire:key="group-{{ $g }}">
                    @foreach(($r[$g] ?? []) as $row => $values)
                        <div class="hc-card" style="padding:12px" wire:key="group-{{ $g }}-{{ $row }}">
                            <div class="hc-form-grid">
                                @foreach($groupFields as $gf)
                                    @include('livewire.partials.kobo-field', ['field' => $gf, 'model' => 'r.'.$g.'.'.$row.'.'.$gf['index'], 'manage' => $manage])
                                @endforeach
                            </div>
                            @if($manage)<x-hc.button variant="ghost" size="sm" icon="x" type="button" wire:click="removeRow({{ $g }}, {{ $row }})">{{ __('kobo.visit.remove_row') }}</x-hc.button>@endif
                        </div>
                    @endforeach
                    @if($manage)<div><x-hc.button variant="ghost" size="sm" icon="plus" type="button" wire:click="addRow({{ $g }})">{{ __('kobo.visit.add_row') }}</x-hc.button></div>@endif
                </div>
            @endif
        @endforeach
        @if($section !== null)</div></div></section>@endif

        <x-hc.card :title="__('visits.fields.visitors')">
            <div class="hc-form-grid">
                @foreach(range(0, \App\Services\Kobo\KoboVisitService::MAX_VISITORS - 1) as $i)
                    <x-hc.field :label="__('visits.fields.visitor', ['n' => $i + 1])" :for="'kv-visitor-'.$i" :required="$i === 0">
                        <select class="hc-select" id="kv-visitor-{{ $i }}" wire:model="visitors.{{ $i }}" @disabled(! $manage)>
                            <option value="">—</option>
                            @foreach($form['visitorOptions'] as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach
                        </select>
                    </x-hc.field>
                @endforeach
            </div>
        </x-hc.card>

        @if($manage)
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <x-hc.button type="submit" icon="check" wire:loading.attr="disabled" wire:target="save">{{ __('visits.save') }}</x-hc.button>
                @if($form['canDelete'])
                    <x-hc.button variant="ghost" icon="x" type="button" wire:click="delete" wire:confirm="{{ __('kobo.visit.delete_confirm') }}" wire:loading.attr="disabled" wire:target="delete">{{ __('visits.delete') }}</x-hc.button>
                @endif
            </div>
            @if($form['canDelete'])<p class="hc-hint" style="margin:0">{{ __('visits.delete_hint') }}</p>@endif
        @endif
    </form>
    @endif
</div>
