{{-- One saved view or report as a live card: a view shows its current row count, a report its top five rows. --}}
@php($fmt = fn ($v) => number_format((float) $v, fmod((float) $v, 1.0) === 0.0 ? 0 : 2))
<x-hc.card :title="$card['name']" wire:key="saved-{{ $card['id'] }}" style="min-width:0" data-desk-card="{{ $card['id'] }}">
    <x-slot:actions>
        @if($card['default'])<span class="hc-badge">{{ __('desk.default') }}</span>@endif
    </x-slot:actions>
    <p class="muted" style="font-size:.85rem;margin-block-end:8px">{{ $card['kind'] === 'report' ? __('desk.kind_report') : __('desk.kind_view') }}</p>
    @if($card['kind'] === 'view' && $card['count'] !== null)
        <p style="margin:0"><strong class="num" style="font-size:1.8rem">{{ number_format($card['count']) }}</strong> <span class="muted">{{ __('desk.rows') }}</span></p>
    @elseif($card['kind'] === 'report' && $card['rows'] !== null)
        @if($card['rows'] === [])
            <p class="muted">{{ __('desk.report_empty') }}</p>
        @else
            <ol style="list-style:none;margin:0;padding:0;display:grid;gap:6px" aria-label="{{ $card['name'] }}">
                @foreach($card['rows'] as $row)
                    <li style="min-width:0">
                        <div style="display:flex;justify-content:space-between;gap:8px;min-width:0"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $row['label'] }}</span><span class="num">{{ $fmt($row['value']) }}@if($card['currency']) <span class="muted">{{ $card['currency'] }}</span>@endif</span></div>
                        <div class="hc-progress" role="img" aria-label="{{ $row['share'] }}%"><span style="width: {{ $row['share'] }}%"></span></div>
                    </li>
                @endforeach
            </ol>
        @endif
    @endif
    <x-slot:footer><a href="{{ $card['href'] }}">{{ __('desk.open') }}</a></x-slot:footer>
</x-hc.card>
