@props(['name', 'value' => null, 'model' => null])
{{-- Yes/No as two radio buttons (the paper form's question style), with an empty "not answered" state. --}}
<div class="hc-choice" role="radiogroup">
    @foreach(['1' => __('hc.yes'), '0' => __('hc.no')] as $v => $text)
    <label><input type="radio" name="{{ $name }}" value="{{ $v }}" @if($model) wire:model.live="{{ $model }}" @endif @checked((string) $value === $v)> {{ $text }}</label>
    @endforeach
</div>
