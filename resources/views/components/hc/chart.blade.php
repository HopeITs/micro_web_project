{{--
    Chart (smart-search phase 3): <x-hc.chart type="bar" :labels="['Damascus', 'Aleppo']" :datasets="[['label' => 'Projects', 'data' => [12, 7]]]" :height="320" title="Projects by city"/>
      type      bar | stacked | line | pie | doughnut (anything else draws bars). pie / doughnut draw the first dataset only.
      labels    the categories (x axis, or the slices)
      datasets  list of {label, data[]}: one series each; a null value is a gap
      height    pixels of the drawing area (default 320)
      title     the accessible name of the drawing (what it shows)
      percent   true when the values are percentages (shown with a % sign)
    Chart.js is bundled by Vite and imported only when a chart is on the page (resources/js/app.js, hcChart): no CDN, the CSP is untouched.
    Colours are the --chart-1..8 tokens of resources/css/app.css (light and dark), the legend and value labels carry the meaning, the
    drawing follows the page direction (RTL) and reduced-motion, and it is redrawn in place when Livewire changes the data attribute.
    The same numbers are always in the visually hidden table, which is what screen readers read.
--}}
@props(['type' => 'bar', 'labels' => [], 'datasets' => [], 'height' => 320, 'title' => null, 'percent' => false])
@php
    $type = in_array($type, ['bar', 'stacked', 'line', 'pie', 'doughnut'], true) ? $type : 'bar';
    $pie = in_array($type, ['pie', 'doughnut'], true);
    $labels = array_values(array_map('strval', (array) $labels));
    $series = [];
    foreach (array_values((array) $datasets) as $set) {
        $series[] = ['label' => (string) ($set['label'] ?? ''), 'data' => array_map(fn ($v) => $v === null || $v === '' ? null : (float) $v, array_values((array) ($set['data'] ?? [])))];
    }
    if ($pie) {
        $series = array_slice($series, 0, 1);
    }
    $config = ['type' => $type, 'labels' => $labels, 'datasets' => $series, 'percent' => (bool) $percent];
    $show = fn ($v) => $v === null ? '—' : number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 2).($percent ? '%' : '');
@endphp
<figure {{ $attributes->merge(['class' => 'hc-chart']) }} x-data="hcChart" data-chart="{{ json_encode($config, JSON_UNESCAPED_UNICODE) }}">
    <div class="hc-chart-canvas" wire:ignore style="height:{{ max(120, (int) $height) }}px">
        <canvas role="img" @if($title) aria-label="{{ $title }}" @endif></canvas>
    </div>
    {{-- The same numbers as text: read by screen readers, and what shows when scripts cannot run. --}}
    <table class="sr-only">
        @if($title)<caption>{{ $title }}</caption>@endif
        <thead>
            <tr>
                <th scope="col">&nbsp;</th>
                @foreach($series as $set)<th scope="col">{{ $set['label'] }}</th>@endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($labels as $i => $label)
                <tr>
                    <th scope="row">{{ $label }}</th>
                    @foreach($series as $set)<td>{{ $show($set['data'][$i] ?? null) }}</td>@endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</figure>
