@php
    $id = 'k-'.str_replace('.', '-', $model);
    $kind = $field['kind'];
    $long = $kind === 'text' && preg_match('/ملاحظ|مؤشر|تفاصيل|وصف|اذكر|أذكر|سبب/u', $field['label']) === 1;
@endphp
<div class="hc-field" wire:key="{{ $id }}">
    @if($kind === 'select_multiple')
        <span class="hc-label">{{ $field['label'] }}</span>
        <div class="hc-choice" style="display:flex;flex-wrap:wrap;gap:6px 16px">
            @foreach($field['choices'] as $c)
                <label><input type="checkbox" value="{{ $c['code'] }}" wire:model="{{ $model }}" @disabled(! $manage)> {{ $c['label'] }}</label>
            @endforeach
        </div>
    @else
        <label class="hc-label" for="{{ $id }}">{{ $field['label'] }}</label>
        @if($kind === 'select_one' && $field['choices'] !== [])
            <select class="hc-select" id="{{ $id }}" wire:model="{{ $model }}" @disabled(! $manage)>
                <option value="">—</option>
                @foreach($field['choices'] as $c)<option value="{{ $c['code'] }}">{{ $c['label'] }}</option>@endforeach
            </select>
        @elseif($kind === 'integer' || $kind === 'decimal')
            <input class="hc-input num ltr" id="{{ $id }}" inputmode="decimal" wire:model="{{ $model }}" autocomplete="off" @disabled(! $manage)>
        @elseif($kind === 'date')
            <input class="hc-input num ltr" type="date" id="{{ $id }}" wire:model="{{ $model }}" @disabled(! $manage)>
        @elseif($kind === 'geopoint')
            <input class="hc-input ltr" id="{{ $id }}" wire:model="{{ $model }}" placeholder="{{ __('kobo.visit.geopoint') }}" @disabled(! $manage)>
        @elseif($long)
            <textarea class="hc-textarea" id="{{ $id }}" rows="2" wire:model="{{ $model }}" @disabled(! $manage)></textarea>
        @else
            <input class="hc-input" id="{{ $id }}" wire:model="{{ $model }}" autocomplete="off" @disabled(! $manage)>
        @endif
    @endif
    @error($model)<p class="hc-error" role="alert">{{ $message }}</p>@enderror
</div>
