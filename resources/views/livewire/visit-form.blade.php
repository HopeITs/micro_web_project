@php
    use App\Services\Field\VisitKinds;
    $en = app()->getLocale() === 'en';
    $h = $form['header'];
    $def = $form['definition'];
    $rehab = $kind === VisitKinds::REHAB;
    $manage = $form['canManage'];
    $visit = $form['visit'];
@endphp
<div class="hc-grid">
    @if($notice !== '')<x-hc.alert type="success">{{ $notice }}</x-hc.alert>@endif
    @error('version')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror
    @error('visit')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror
    @error('answers')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror
    @if($form['kobo'])<x-hc.alert type="info">{{ __('visits.kobo_read_only') }}</x-hc.alert>
    @elseif(! $manage)<x-hc.alert type="info">{{ __('visits.read_only') }}</x-hc.alert>@endif

    <x-hc.card>
        <dl class="hc-dl">
            <div><dt>{{ __('visits.header.number') }}</dt><dd class="ltr num">{{ $h['number'] }}</dd></div>
            <div><dt>{{ __('visits.header.beneficiary') }}</dt><dd>{{ $h['beneficiary'] ?: '—' }}</dd></div>
            @unless($rehab)
            <div><dt>{{ __('visits.header.project') }}</dt><dd>{{ $h['name'] ?: '—' }}</dd></div>
            <div><dt>{{ __('visits.header.phone') }}</dt><dd class="ltr">{{ $h['phone'] ?: '—' }}</dd></div>
            <div><dt>{{ __('visits.header.address') }}</dt><dd>{{ $h['address'] ?: '—' }}</dd></div>
            @endunless
            <div><dt>{{ __('visits.header.amount') }}</dt><dd class="num">{{ $h['amount'] ?? '—' }}</dd></div>
            @unless($rehab)<div><dt>{{ __('visits.header.loan_date') }}</dt><dd class="num">{{ $h['loan_date'] ?: '—' }}</dd></div>@endunless
        </dl>
        @if($h['amount'] === null)<x-hc.alert type="info" style="margin-block-start:12px">{{ __('visits.header.no_loan') }}</x-hc.alert>@endif
        @if($visit)
            <p class="hc-hint" style="margin:12px 0 0">{{ __('visits.fields.created_by') }}: {{ $visit->created_by_name ?: '—' }} @if($visit->edited_by_name) · {{ __('visits.fields.edited_by') }}: {{ $visit->edited_by_name }}@endif</p>
        @endif
    </x-hc.card>

    <form wire:submit="save" class="hc-grid">
        <x-hc.card>
            <div class="hc-form-grid">
                <x-hc.field :label="__('visits.fields.date')" for="v-date" :error="$errors->first('date')" required>
                    <input class="hc-input num ltr" type="date" id="v-date" wire:model="date" max="{{ now()->toDateString() }}" @disabled(! $manage)>
                </x-hc.field>
                @if($def['vehicle'] ?? false)
                    <x-hc.field :label="__('visits.fields.vehicle')" for="v-plate" :error="$errors->first('vehicle')" required>
                        <input class="hc-input ltr" id="v-plate" wire:model="vehicle" maxlength="30" autocomplete="off" @disabled(! $manage)>
                    </x-hc.field>
                @endif
                @if($def['place'] ?? false)
                    <x-hc.field :label="__('visits.fields.street')" for="v-street" :error="$errors->first('street_id')">
                        <select class="hc-select" id="v-street" wire:model="street_id" @disabled(! $manage)>
                            <option value="">—</option>
                            @foreach($form['streets'] as $s)<option value="{{ $s->id }}">{{ $en && $s->name_en ? $s->name_en : $s->name_ar }}</option>@endforeach
                        </select>
                    </x-hc.field>
                    <x-hc.field :label="__('visits.fields.place_note')" for="v-place" :error="$errors->first('place_note')">
                        <input class="hc-input" id="v-place" wire:model="place_note" maxlength="255" @disabled(! $manage)>
                    </x-hc.field>
                @endif
            </div>
        </x-hc.card>

        @if($rehab)
            @foreach([1 => [1, 2, 3, 4], 2 => [5, 6, 7, 8, 9, 10, 11, 12], 3 => [13], 4 => [14], 5 => [15]] as $section => $slots)
                <x-hc.card :title="__('visits.rehab.section.'.$section)">
                    <div class="hc-grid" style="gap:14px">
                    @foreach($slots as $slot)
                        @include('livewire.partials.visit-question', ['label' => count($slots) > 1 ? __('visits.rehab.q.'.$slot) : null, 'slot' => $slot, 'model' => 'q', 'domain' => VisitKinds::REHAB_QUESTIONS[$slot], 'manage' => $manage])
                    @endforeach
                    </div>
                </x-hc.card>
            @endforeach
        @else
            <x-hc.card>
                <div class="hc-grid" style="gap:18px">
                @foreach($def['questions'] as $slot => $domain)
                    @include('livewire.partials.visit-question', ['label' => VisitKinds::questionNumber($kind, $slot).') '.$form['questions'][$slot], 'slot' => $slot, 'model' => 'q', 'domain' => $domain, 'manage' => $manage])
                    @if($def['phase'] === 'closing' && $slot === 5)
                        {{-- Question 6: the four office ratings (slot 6 of the visit holds question 7). --}}
                        <fieldset class="hc-fieldset" style="border:0;padding:0;margin:0">
                            <legend class="hc-label" style="margin-block-end:8px">{{ __('visits.office.title_closing') }}</legend>
                            <div class="hc-grid" style="gap:12px">
                            @foreach(range(1, $def['office']) as $o)
                                @include('livewire.partials.visit-question', ['label' => __('visits.office.closing.'.$o), 'slot' => $o, 'model' => 'office', 'domain' => VisitKinds::RATING, 'manage' => $manage])
                            @endforeach
                            </div>
                        </fieldset>
                    @endif
                @endforeach
                @if($def['phase'] === 'closing')
                    <x-hc.field :label="$form['questions']['indicators']" for="v-indicators" :error="$errors->first('indicators')">
                        <textarea class="hc-textarea" id="v-indicators" wire:model="indicators" rows="3" @disabled(! $manage)></textarea>
                    </x-hc.field>
                @endif
                </div>
            </x-hc.card>
            @if($def['phase'] === 'opening')
                <x-hc.card :title="__('visits.office.title_opening')">
                    <div class="hc-grid" style="gap:12px">
                    @foreach(range(1, $def['office']) as $o)
                        @include('livewire.partials.visit-question', ['label' => __('visits.office.'.$def['office_labels'].'.'.$o), 'slot' => $o, 'model' => 'office', 'domain' => VisitKinds::RATING, 'manage' => $manage])
                    @endforeach
                    <x-hc.field :label="__('visits.fields.office_comments')" for="v-comments" :error="$errors->first('office_comments')">
                        <textarea class="hc-textarea" id="v-comments" wire:model="office_comments" rows="2" @disabled(! $manage)></textarea>
                    </x-hc.field>
                    <x-hc.field :label="__('visits.fields.indicators')" for="v-indicators" :error="$errors->first('indicators')">
                        <textarea class="hc-textarea" id="v-indicators" wire:model="indicators" rows="2" @disabled(! $manage)></textarea>
                    </x-hc.field>
                    </div>
                </x-hc.card>
            @endif
        @endif

        <x-hc.card :title="__('visits.fields.visitors')">
            @error('visitors')<x-hc.alert type="danger" style="margin-block-end:12px">{{ $message }}</x-hc.alert>@enderror
            @if($form['kobo'] && $form['koboVisitors'] !== [] && $visitors === ['', '', '', ''])
                <p>{{ implode('، ', $form['koboVisitors']) }}</p>
            @else
            <div class="hc-form-grid">
                @foreach(range(0, \App\Services\Field\VisitKinds::MAX_VISITORS - 1) as $i)
                    <x-hc.field :label="__('visits.fields.visitor', ['n' => $i + 1])" :for="'v-visitor-'.$i" :required="$i === 0">
                        <select class="hc-select" id="v-visitor-{{ $i }}" wire:model="visitors.{{ $i }}" @disabled(! $manage)>
                            <option value="">—</option>
                            @foreach($form['visitorOptions'] as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach
                        </select>
                    </x-hc.field>
                @endforeach
            </div>
            @endif
        </x-hc.card>

        @if($manage)
            @if(! $visit && ($form['phase'] === 'closing'))<p class="hc-hint" style="margin:0">{{ __('visits.closing_effect') }}</p>@endif
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <x-hc.button type="submit" icon="check" wire:loading.attr="disabled" wire:target="save">{{ __('visits.save') }}</x-hc.button>
                @if($form['canDelete'])
                    <x-hc.button variant="ghost" icon="x" wire:click="delete" wire:confirm="{{ __('visits.delete_confirm') }}" wire:loading.attr="disabled" wire:target="delete">{{ __('visits.delete') }}</x-hc.button>
                @endif
            </div>
            @if($form['canDelete'])<p class="hc-hint" style="margin:0">{{ __('visits.delete_hint') }} @if($form['phase'] === 'closing'){{ __('visits.finished_warning') }}@endif</p>@endif
        @endif
    </form>
</div>
