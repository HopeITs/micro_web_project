@php
    $id = 'k-'.str_replace('.', '-', $model);
    $kind = $field['kind'];
    $rule = \App\Services\Kobo\KoboRules::for($uid, $field['path']);
    $required = ($rule['required'] ?? false) && $kind !== 'geopoint';
    $appearance = (string) ($rule['appearance'] ?? '');
    $multiline = $kind === 'text' && str_contains($appearance, 'multiline');
    $likert = $kind === 'select_one' && str_contains($appearance, 'likert');
    $live = isset(\App\Services\Kobo\KoboRules::controlling($uid)[$field['path']]) && in_array($kind, ['select_one', 'select_multiple', 'date'], true);
    $bind = $live ? 'wire:model.live' : 'wire:model';
    $digits = $kind === 'text' && str_contains($appearance, 'numbers');
@endphp
<div class="hc-field" wire:key="{{ $id }}">
    @if($kind === 'select_multiple')
        <span class="hc-label">{{ $field['label'] }}@if($required)<span class="req" aria-hidden="true">*</span>@endif</span>
        <div class="hc-choice" style="display:flex;flex-wrap:wrap;gap:6px 16px">
            @foreach($field['choices'] as $c)
                <label><input type="checkbox" value="{{ $c['code'] }}" {{ $bind }}="{{ $model }}" @disabled(! $manage)> {{ $c['label'] }}</label>
            @endforeach
        </div>
    @elseif($likert && $field['choices'] !== [])
        <span class="hc-label">{{ $field['label'] }}@if($required)<span class="req" aria-hidden="true">*</span>@endif</span>
        <div class="hc-choice" style="display:flex;flex-wrap:wrap;gap:6px 16px" role="radiogroup">
            @foreach($field['choices'] as $c)
                <label><input type="radio" name="{{ $id }}" value="{{ $c['code'] }}" {{ $bind }}="{{ $model }}" @disabled(! $manage)> {{ $c['label'] }}</label>
            @endforeach
        </div>
    @else
        <label class="hc-label" for="{{ $id }}">{{ $field['label'] }}@if($required)<span class="req" aria-hidden="true">*</span>@endif</label>
        @if($kind === 'select_one' && $field['choices'] !== [])
            <select class="hc-select" id="{{ $id }}" {{ $bind }}="{{ $model }}" @disabled(! $manage)>
                <option value="">—</option>
                @foreach($field['choices'] as $c)<option value="{{ $c['code'] }}">{{ $c['label'] }}</option>@endforeach
            </select>
        @elseif($kind === 'integer' || $kind === 'decimal')
            <input class="hc-input num ltr" id="{{ $id }}" inputmode="decimal" wire:model="{{ $model }}" autocomplete="off" @disabled(! $manage)>
        @elseif($kind === 'date')
            <input class="hc-input num ltr" type="date" id="{{ $id }}" {{ $bind }}="{{ $model }}" @disabled(! $manage)>
        @elseif($kind === 'geopoint')
            <div style="display:flex;gap:8px;align-items:center" x-data="{ busy: false, failed: false, locate() { this.busy = true; this.failed = false; navigator.geolocation ? navigator.geolocation.getCurrentPosition(p => { $wire.set('{{ $model }}', p.coords.latitude.toFixed(6) + ' ' + p.coords.longitude.toFixed(6)); this.busy = false; }, () => { this.failed = true; this.busy = false; }, { enableHighAccuracy: true, timeout: 15000 }) : (this.failed = true, this.busy = false); } }">
                <input class="hc-input ltr" id="{{ $id }}" wire:model="{{ $model }}" placeholder="{{ __('kobo.visit.geopoint') }}" @disabled(! $manage) style="flex:1">
                @if($manage)<button type="button" class="hc-btn hc-btn-ghost hc-btn-sm" @click="locate()" :disabled="busy">{{ __('kobo.visit.locate') }}</button>@endif
                <span class="hc-error" x-show="failed" x-cloak>{{ __('kobo.visit.locate_failed') }}</span>
            </div>
        @elseif($multiline)
            <textarea class="hc-textarea" id="{{ $id }}" rows="3" wire:model="{{ $model }}" @disabled(! $manage)></textarea>
        @else
            <input class="hc-input" id="{{ $id }}" wire:model="{{ $model }}" @if($digits) inputmode="numeric" @endif autocomplete="off" @disabled(! $manage)>
        @endif
    @endif
    @if(isset($rule['hint']))<p class="hc-hint" style="margin:4px 0 0">{{ $rule['hint'] }}</p>@endif
    @error($model)<p class="hc-error" role="alert">{{ $message }}</p>@enderror
</div>
