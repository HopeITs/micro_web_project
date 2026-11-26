@php
    $en = app()->getLocale() === 'en';
    $name = fn ($ar, $enName) => $en && $enName ? $enName : $ar;
    $current = $panel['current'];
@endphp
<div id="lifecycle">
<x-hc.card :title="__('lifecycle.title')">
    @if($notice !== '')<x-hc.alert type="success" style="margin-block-end:12px">{{ $notice }}</x-hc.alert>@endif
    @error('version')<x-hc.alert type="danger" style="margin-block-end:12px">{{ $message }}</x-hc.alert>@enderror

    @if($panel['canManage'] && $panel['undo'])
        @php($back = $name($panel['undo']['back_ar'], $panel['undo']['back_en']))
        <div class="hc-undo" style="margin-block-end:12px">
            @if($panel['undo']['blocked'])
                <p class="muted" style="margin:0">{{ __('lifecycle.undo.'.$panel['undo']['blocked']) }}</p>
            @else
                <x-hc.button variant="secondary" size="sm" icon="arrow" wire:click="undoLast" wire:confirm="{{ __('lifecycle.undo.confirm', ['state' => $back]) }}" wire:loading.attr="disabled" wire:target="undoLast">{{ __('lifecycle.undo.button', ['state' => $back]) }}</x-hc.button>
                <p class="hc-hint" style="margin:6px 0 0">{{ __('lifecycle.undo.hint') }}</p>
            @endif
            @error('undo')<p class="hc-error" role="alert">{{ $message }}</p>@enderror
        </div>
    @endif
    @if($panel['canManage'])
        <x-hc.section :title="__('lifecycle.change')" :open="$errors->has('change.*')">
            <form wire:submit="changeState" class="hc-grid" style="gap:12px" x-data="{ state: $wire.entangle('change.state_id') }">
                <x-hc.field :label="__('lifecycle.new_state')" for="lc-state" :error="$errors->first('change.state_id')" :hint="__('lifecycle.rules')" required>
                    <select class="hc-select" id="lc-state" x-model="state" required>
                        <option value="">—</option>
                        @foreach($panel['targets'] as $state)<option value="{{ $state->id }}">{{ $name($state->name_ar, $state->name_en) }}</option>@endforeach
                    </select>
                </x-hc.field>
                    <x-hc.field x-show="state === '{{ $accepted }}'" x-cloak :label="__('lifecycle.donor')" for="lc-donor" :error="$errors->first('change.donor_id')" :hint="__('lifecycle.donor_hint')" required>
                        <select class="hc-select" id="lc-donor" wire:model="change.donor_id" x-bind:required="state === '{{ $accepted }}'">
                            <option value="">—</option>
                            @foreach($panel['donors'] as $donor)<option value="{{ $donor->id }}">{{ $name($donor->name_ar, $donor->name_en) }}</option>@endforeach
                        </select>
                    </x-hc.field>
                <x-hc.field :label="__('lifecycle.reason')" for="lc-reason" :error="$errors->first('change.reason_id')">
                    <select class="hc-select" id="lc-reason" wire:model="change.reason_id">
                        <option value="">{{ __('hc.not_set') }}</option>
                        @foreach($panel['reasons'] as $reason)<option value="{{ $reason->id }}">{{ $name($reason->name_ar, $reason->name_en) }}</option>@endforeach
                    </select>
                </x-hc.field>
                <x-hc.field :label="__('lifecycle.note')" for="lc-note" :error="$errors->first('change.note')">
                    <textarea class="hc-textarea" id="lc-note" wire:model="change.note" maxlength="5000" rows="2"></textarea>
                </x-hc.field>
                <div><x-hc.button type="submit" icon="check" wire:loading.attr="disabled" wire:target="changeState">{{ __('lifecycle.change') }}</x-hc.button></div>
            </form>
        </x-hc.section>

        @if($current)
        <x-hc.section :title="__('lifecycle.edit_current')" :open="$errors->has('currentForm.*')">
            <form wire:submit="saveCurrent" class="hc-grid" style="gap:12px">
                <x-hc.field :label="__('lifecycle.reason')" for="lc-cur-reason" :error="$errors->first('currentForm.reason_id')">
                    <select class="hc-select" id="lc-cur-reason" wire:model="currentForm.reason_id">
                        <option value="">{{ __('hc.not_set') }}</option>
                        @foreach($panel['reasons'] as $reason)<option value="{{ $reason->id }}">{{ $name($reason->name_ar, $reason->name_en) }}</option>@endforeach
                    </select>
                </x-hc.field>
                <x-hc.field :label="__('lifecycle.note')" for="lc-cur-note" :error="$errors->first('currentForm.note')">
                    <textarea class="hc-textarea" id="lc-cur-note" wire:model="currentForm.note" maxlength="5000" rows="2"></textarea>
                </x-hc.field>
                <div><x-hc.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="saveCurrent">{{ __('lifecycle.save') }}</x-hc.button></div>
            </form>
        </x-hc.section>
        @endif
    @endif

    <h3 style="font-size:.95rem;margin:16px 0 10px">{{ __('lifecycle.history') }}</h3>
    @if($panel['history']->isEmpty())
        <p class="muted">{{ __('lifecycle.none') }}</p>
    @else
        <ol class="hc-timeline">
            @foreach($panel['history'] as $h)
                <li @class(['current' => $current && $current->id === $h->id])>
                    {{-- The name of the state in force is shown once, at the top of the project page. --}}
                    <strong>{{ $current && $current->id === $h->id ? __('project_page.current_state') : $name($h->state_ar, $h->state_en) }}</strong>
                    <br><span class="muted num">{{ $h->changed_on ? \Illuminate\Support\Str::limit((string) $h->changed_on, 10, '') : __('hc.not_set') }}</span>
                    @if($h->reason_ar)<br><span>{{ __('lifecycle.reason') }}: {{ $name($h->reason_ar, $h->reason_en) }}</span>@endif
                    @if($h->note)<br><span class="muted">{{ $h->note }}</span>@endif
                </li>
            @endforeach
        </ol>
    @endif
</x-hc.card>
</div>
