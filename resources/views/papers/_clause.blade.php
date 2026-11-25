{{-- A clause of a deed or contract: $text carries {name} blanks; a filled blank flows with the text, an empty one stays a dotted line. --}}
@php
    $widths = ['rep' => 42, 'instalment' => 96, 'grace' => 34, 'ratio' => 22, 'season' => 74, 'season_start' => 44, 'medicines' => 78, 'month' => 24, 'year' => 28, 'system' => 66, 'vehicle' => 40,
        'car' => 40, 'subject' => 120, 'price' => 80, 'notice' => 170, 'handover_on' => 42, 'handover_time' => 34, 'court' => 30, 'blank' => 44];
@endphp
@foreach(preg_split('/(\{[a-z_]+\})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $part)
    @if(preg_match('/^\{([a-z_]+)\}$/', $part, $m))
        @if($m[1] === 'centre')<b>{{ __('paper_deeds.centre') }}</b>
        @elseif((string) ($values[$m[1]] ?? '') !== '')<span class="filled">{{ $values[$m[1]] }}</span>
        @else<x-paper.fill :w="$widths[$m[1]] ?? 30"/>
        @endif
    @else{{ $part }}@endif
@endforeach
