{{-- A name inside running text: bold when known, otherwise a dotted blank of $w millimetres. --}}
@if((string) $value !== '')<span class="filled"><bdi>{{ $value }}</bdi></span>@else<x-paper.fill :w="$w ?? 46"/>@endif
