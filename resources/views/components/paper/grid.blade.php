{{-- A bordered table: $columns is [key => label] or [key => [label, width%]]; $rows are display arrays; at least $min rows are drawn (blank ones when the data is shorter). --}}
@props(['columns', 'rows' => [], 'min' => 2, 'fmt' => []])
@php
    $yes = __('people.yes'); $no = __('people.no');
    $cell = function ($key, $row) use ($fmt, $yes, $no) {
        $v = $fmt[$key] ?? null ? ($fmt[$key])($row) : ($row[$key] ?? '');

        return $v === 'yes' ? $yes : ($v === 'no' ? $no : $v);
    };
    $blank = max(0, $min - count($rows));
@endphp
<table class="g">
    <thead><tr>@foreach($columns as $key => $col)<th @if(is_array($col) && isset($col[1])) style="width:{{ $col[1] }}%" @endif>{{ is_array($col) ? $col[0] : $col }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach($rows as $row)<tr>@foreach($columns as $key => $col)<td>{{ $cell($key, $row) }}</td>@endforeach</tr>@endforeach
    @for($i = 0; $i < $blank; $i++)<tr>@foreach($columns as $col)<td></td>@endforeach</tr>@endfor
    </tbody>
</table>
