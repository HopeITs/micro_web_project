@php
    $en = app()->getLocale() === 'en';
    $h = $page['header'];
    $status = \App\Support\ProjectStatus::class;
    $current = $page['current'];
    $manage = $page['canManage'];
@endphp
<div class="hc-grid">
    @error('version')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror
    @error('case')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror
    @unless($manage)<x-hc.alert type="info">{{ __('special_cases.read_only') }}</x-hc.alert>@endunless

    <x-hc.card>
        <dl class="hc-dl">
            <div><dt>{{ __('visits.header.number') }}</dt><dd class="ltr num">{{ $h['number'] }}</dd></div>
            <div><dt>{{ __('visits.header.beneficiary') }}</dt><dd>{{ $h['beneficiary'] ?: '—' }}</dd></div>
            <div><dt>{{ __('visits.header.project') }}</dt><dd>{{ $h['name'] ?: '—' }}</dd></div>
            <div><dt>{{ __('finance.state') }}</dt><dd>@if($current)<x-hc.badge :tone="$status::tone($current->state_en)" :icon="$status::icon($current->state_en)">{{ $en && $current->state_en ? $current->state_en : $current->state_ar }}</x-hc.badge>@else — @endif</dd></div>
            <div><dt>{{ __('visits.header.phone') }}</dt><dd class="ltr">{{ $h['phone'] ?: '—' }}</dd></div>
            <div><dt>{{ __('visits.header.address') }}</dt><dd>{{ $h['address'] ?: '—' }}</dd></div>
            <div><dt>{{ __('visits.header.amount') }}</dt><dd class="num">{{ $h['amount'] ?? '—' }}</dd></div>
            <div><dt>{{ __('visits.header.loan_date') }}</dt><dd class="num">{{ $h['loan_date'] ?: '—' }}</dd></div>
        </dl>
        @if($h['amount'] === null)<x-hc.alert type="info" style="margin-block-start:12px">{{ __('visits.header.no_loan') }}</x-hc.alert>@endif
    </x-hc.card>

    @if($manage)
    <form wire:submit="save" class="hc-grid" id="sc-form" x-data x-on:case-saved.window="setTimeout(() => document.getElementById('sc-reason')?.focus(), 150)" @if($focusNew) x-init="$nextTick(() => { $el.scrollIntoView({ block: 'start' }); document.getElementById('sc-reason')?.focus() })" @endif>
        <x-hc.card :title="$editing ? __('special_cases.edit_title') : __('special_cases.new')">
            @if($editing)
                <x-slot:actions><x-hc.button type="button" variant="ghost" size="sm" icon="plus" wire:click="startNew">{{ __('special_cases.new') }}</x-hc.button></x-slot:actions>
            @endif
            @if($notice !== '')<x-hc.alert type="success" style="margin-block-end:16px">{{ $notice }}</x-hc.alert>@endif
            <div class="hc-form-grid">
                <x-hc.field :label="__('special_cases.fields.date')" for="sc-date" :error="$errors->first('date')" required>
                    <input class="hc-input num ltr" type="date" id="sc-date" wire:model="date" max="{{ now()->toDateString() }}">
                </x-hc.field>
                <x-hc.field :label="__('special_cases.fields.recorder')" for="sc-recorder" :error="$errors->first('recorder')" required>
                    <select class="hc-select" id="sc-recorder" wire:model="recorder">
                        <option value="">—</option>
                        @foreach($page['recorders'] as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach
                    </select>
                </x-hc.field>
                <x-hc.field :label="__('special_cases.fields.type')" for="sc-type" :error="$errors->first('type')" required>
                    <select class="hc-select" id="sc-type" wire:model="type">
                        <option value="">—</option>
                        @foreach($page['types'] as $t)<option value="{{ $t->id }}">{{ $t->name }}@unless($t->active) ({{ __('special_cases.hidden_type') }})@endunless</option>@endforeach
                    </select>
                </x-hc.field>
            </div>
            <div class="hc-grid" style="gap:16px;margin-block-start:16px">
                <x-hc.field :label="__('special_cases.fields.reason')" for="sc-reason" :error="$errors->first('reason')" required>
                    <input class="hc-input" id="sc-reason" wire:model="reason" maxlength="{{ \App\Services\Field\SpecialCaseService::REASON_MAX }}" autocomplete="off">
                </x-hc.field>
                <x-hc.field :label="__('special_cases.fields.description')" for="sc-description" :error="$errors->first('description')">
                    <textarea class="hc-textarea" id="sc-description" wire:model="description" rows="4"></textarea>
                </x-hc.field>
                <x-hc.field :label="__('special_cases.fields.action')" for="sc-action" :error="$errors->first('action')">
                    <textarea class="hc-textarea" id="sc-action" wire:model="action" rows="3"></textarea>
                </x-hc.field>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-block-start:16px">
                <x-hc.button type="submit" icon="check" wire:loading.attr="disabled" wire:target="save">{{ __('special_cases.save') }}</x-hc.button>
                @if($editing)<x-hc.button type="button" variant="ghost" icon="x" wire:click="startNew">{{ __('special_cases.cancel') }}</x-hc.button>@endif
            </div>
        </x-hc.card>
    </form>
    @endif

    <x-hc.card :title="__('special_cases.recorded', ['count' => $page['cases']->count()])" :flush="true">
        {{-- A project may have any number of cases (owner request 22 Nov 2026): add another one at any time. --}}
        @if($manage)
            <x-slot:actions>
                <x-hc.button type="button" size="sm" icon="plus" wire:click="startNew"
                             x-on:click="$nextTick(() => { document.getElementById('sc-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); setTimeout(() => document.getElementById('sc-reason')?.focus(), 250) })">{{ __('special_cases.add_another') }}</x-hc.button>
            </x-slot:actions>
        @endif
        @forelse($page['cases'] as $case)
            <article style="padding:16px 20px;border-block-end:1px solid var(--line)" wire:key="case-{{ $case->id }}" id="case-{{ $case->id }}" @class(['is-current' => $editing === $case->id])>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between">
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                        <strong class="num ltr">{{ $case->date ?: '—' }}</strong>
                        @if($case->type !== '')<x-hc.badge tone="info" :icon="null">{{ $case->type }}</x-hc.badge>@endif
                        <span class="muted">{{ __('special_cases.fields.recorder') }}: {{ $case->recorder ?: '—' }}</span>
                    </div>
                    @if($manage || $page['canDelete'])
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        @if($manage)<x-hc.button size="sm" variant="secondary" icon="settings" wire:click="edit({{ $case->id }})">{{ __('special_cases.edit') }}</x-hc.button>@endif
                        @if($page['canDelete'])<x-hc.button size="sm" variant="ghost" icon="x" wire:click="delete({{ $case->id }})" wire:confirm="{{ __('special_cases.delete_confirm') }}">{{ __('special_cases.delete') }}</x-hc.button>@endif
                    </div>
                    @endif
                </div>
                <dl class="hc-dl" style="margin-block-start:10px">
                    <div style="grid-column:1/-1"><dt>{{ __('special_cases.fields.reason') }}</dt><dd>{{ $case->reason ?: '—' }}</dd></div>
                    @if($case->description !== '')<div style="grid-column:1/-1"><dt>{{ __('special_cases.fields.description') }}</dt><dd style="white-space:pre-line">{{ $case->description }}</dd></div>@endif
                    @if($case->action !== '')<div style="grid-column:1/-1"><dt>{{ __('special_cases.fields.action') }}</dt><dd style="white-space:pre-line">{{ $case->action }}</dd></div>@endif
                </dl>
            </article>
        @empty
            <x-hc.empty :title="__('special_cases.none')" icon="clipboard">{{ $manage ? __('special_cases.none_hint') : '' }}</x-hc.empty>
        @endforelse
    </x-hc.card>
</div>
