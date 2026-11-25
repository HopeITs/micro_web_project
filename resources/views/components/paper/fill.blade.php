{{-- An inline dotted blank inside running text, holding a value when there is one ($w = minimum width in mm). --}}
@props(['value' => null, 'w' => 28])
<span class="fill" style="min-width:{{ $w }}mm">{{ $value }}</span>
