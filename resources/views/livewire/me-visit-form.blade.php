@php
    $en = app()->getLocale() === 'en';
    $h = $form['header'];
    $manage = $form['canManage'];
    $visit = $form['visit'];
    $kind = $form['kind'];
    $evaluation = fn ($e) => $e ? ($en && $e->name_en ? $e->name_en : $e->name_ar) : '—';
@endphp
<div class="hc-grid">
    @if($notice !== '')<x-hc.alert type="success">{{ $notice }}</x-hc.alert>@endif
    @error('version')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror
    @error('visit')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror
    @error('person')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror
    @if($form['kobo'])<x-hc.alert type="info">{{ __('visits.kobo_read_only') }}</x-hc.alert>
    @elseif(! $manage)<x-hc.alert type="info">{{ __('visits.read_only') }}</x-hc.alert>@endif

    <x-hc.card>
        <dl class="hc-dl">
            <div><dt>{{ __('visits.header.number') }}</dt><dd class="ltr num">{{ $h['number'] }}</dd></div>
            <div><dt>{{ __('visits.header.person') }}</dt><dd>{{ $form['person']->name }} @if(! $form['person']->beneficiary)<span class="muted">· {{ $form['person']->role ?: __('visits.me.partner') }}</span>@endif</dd></div>
            <div><dt>{{ __('visits.header.project') }}</dt><dd>{{ $h['name'] ?: '—' }}</dd></div>
            <div><dt>{{ __('visits.header.phone') }}</dt><dd class="ltr">{{ $form['person']->mobile ?: '—' }}</dd></div>
            <div><dt>{{ __('visits.header.amount') }}</dt><dd class="num">{{ $h['amount'] ?? __('visits.header.no_loan') }}</dd></div>
            <div><dt>{{ __('visits.header.loan_date') }}</dt><dd class="num">{{ $h['loan_date'] ?: '—' }}</dd></div>
        </dl>
        @if($visit)<p class="hc-hint" style="margin:12px 0 0">{{ __('visits.fields.created_by') }}: {{ $visit->created_by_name ?: '—' }} @if($visit->edited_by_name) · {{ __('visits.fields.edited_by') }}: {{ $visit->edited_by_name }}@endif</p>@endif
    </x-hc.card>

    <form wire:submit="save" class="hc-grid">
        <x-hc.card>
            <div class="hc-form-grid">
                <x-hc.field :label="__('visits.me.number')" for="me-number" :error="$errors->first('number')" :hint="$visit ? null : __('visits.me.number_hint', ['n' => $form['next']])" required>
                    <input class="hc-input num ltr" id="me-number" inputmode="numeric" wire:model="number" maxlength="1" @disabled(! $manage)>
                </x-hc.field>
                <x-hc.field :label="__('visits.fields.date')" for="me-date" :error="$errors->first('date')" required>
                    <input class="hc-input num ltr" type="date" id="me-date" wire:model="date" max="{{ now()->toDateString() }}" @disabled(! $manage)>
                </x-hc.field>
            </div>
        </x-hc.card>

        @foreach($form['sections'] as $section)
            <x-hc.card :title="__('visits.me.sections.'.$kind.'.'.$section->section)" wire:key="section-{{ $section->section }}">
                <div class="hc-grid" style="gap:16px">
                @foreach($section->questions as $i => $question)
                    <div class="hc-field" wire:key="q-{{ $question->id }}">
                        <label class="hc-label" for="me-a-{{ $question->id }}">{{ $i + 1 }} – {{ $question->name }}<span class="req" aria-hidden="true">*</span></label>
                        <select class="hc-select" id="me-a-{{ $question->id }}" wire:model.live="a.{{ $question->id }}" @disabled(! $manage)>
                            <option value="">—</option>
                            @foreach($question->answers as $answer)<option value="{{ $answer->id }}">{{ $answer->name }}</option>@endforeach
                        </select>
                        @error('a.'.$question->id)<p class="hc-error" role="alert">{{ $message }}</p>@enderror
                        <input class="hc-input" style="margin-block-start:6px" placeholder="{{ __('visits.fields.note') }}" aria-label="{{ __('visits.fields.note') }}" wire:model="n.{{ $question->id }}" @disabled(! $manage)>
                    </div>
                @endforeach
                @if($section->section === 1)
                    <x-hc.field :label="__('visits.me.answer_1_'.$kind)" for="me-ans1" :error="$errors->first('answer_1')">
                        <textarea class="hc-textarea" id="me-ans1" wire:model="answer_1" rows="2" @disabled(! $manage)></textarea>
                    </x-hc.field>
                @endif
                </div>
            </x-hc.card>
            @if($section->section === 2)
                <x-hc.card :title="__('visits.me.profit_section')">
                    <x-hc.field :label="__('visits.me.profit')" for="me-profit" :error="$errors->first('profit')" required>
                        <input class="hc-input num ltr" id="me-profit" inputmode="decimal" wire:model.blur="profit" autocomplete="off" @disabled(! $manage)>
                    </x-hc.field>
                </x-hc.card>
            @endif
        @endforeach

        <x-hc.card>
            <div class="hc-fin-totals" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
                <x-hc.stat :label="__('visits.me.result')" :value="$preview ?? ($visit && $visit->result !== null ? $visit->result : '—')" :note="__('visits.me.result_hint')"/>
                <x-hc.stat :label="__('visits.me.evaluation')" :value="$evaluation($preview !== null ? $previewEvaluation : $form['evaluation'])"/>
            </div>
            <div class="hc-form-grid" style="margin-block-start:16px">
                <x-hc.field :label="__('visits.fields.indicators')" for="me-ind" :error="$errors->first('indicators')">
                    <textarea class="hc-textarea" id="me-ind" wire:model="indicators" rows="2" @disabled(! $manage)></textarea>
                </x-hc.field>
                <x-hc.field :label="__('visits.me.notes')" for="me-notes" :error="$errors->first('notes')">
                    <textarea class="hc-textarea" id="me-notes" wire:model="notes" rows="2" @disabled(! $manage)></textarea>
                </x-hc.field>
            </div>
        </x-hc.card>

        <x-hc.card :title="__('visits.fields.visitors')">
            @error('visitors')<x-hc.alert type="danger" style="margin-block-end:12px">{{ $message }}</x-hc.alert>@enderror
            @if($form['kobo'] && $form['koboVisitors'] !== [] && $form['visitors'] === [])
                <p>{{ implode('، ', $form['koboVisitors']) }}</p>
            @else
            <div class="hc-form-grid">
                @foreach(range(0, max(\App\Services\Field\VisitKinds::MAX_ME_VISITORS, count($visitors)) - 1) as $i)
                    <x-hc.field :label="__('visits.fields.visitor', ['n' => $i + 1])" :for="'me-visitor-'.$i" :required="$i === 0">
                        <select class="hc-select" id="me-visitor-{{ $i }}" wire:model="visitors.{{ $i }}.account" @disabled(! $manage)>
                            <option value="">—</option>
                            @foreach($form['visitorOptions'] as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach
                        </select>
                    </x-hc.field>
                    <x-hc.field :label="__('visits.me.mark')" :for="'me-mark-'.$i">
                        <select class="hc-select" id="me-mark-{{ $i }}" wire:model="visitors.{{ $i }}.mark" @disabled(! $manage)>
                            <option value="">—</option>
                            @foreach(array_unique([...\App\Services\Field\VisitKinds::MARKS, ...array_filter([$visitors[$i]['mark'] ?? ''])]) as $mark)<option value="{{ $mark }}">{{ $mark }}</option>@endforeach
                        </select>
                    </x-hc.field>
                @endforeach
            </div>
            @endif
        </x-hc.card>

        @if($manage)
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <x-hc.button type="submit" icon="check" wire:loading.attr="disabled" wire:target="save">{{ __('visits.save') }}</x-hc.button>
                @if($form['canDelete'])
                    <x-hc.button variant="ghost" icon="x" wire:click="delete" wire:confirm="{{ __('visits.delete_confirm') }}" wire:loading.attr="disabled" wire:target="delete">{{ __('visits.delete') }}</x-hc.button>
                @endif
            </div>
            @if($form['canDelete'])<p class="hc-hint" style="margin:0">{{ __('visits.delete_hint') }}</p>@endif
        @endif
    </form>
</div>
