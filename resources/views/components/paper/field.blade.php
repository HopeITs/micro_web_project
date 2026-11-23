{{-- A label followed by a dotted line that holds the value (printed in bold-ish ink), as every paper field. --}}
@props(['label', 'value' => null])
<div class="f"><span class="l">{{ $label }}</span><span class="v">{{ $value }}</span></div>
