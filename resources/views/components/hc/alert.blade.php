@props(['type' => 'info', 'title' => null])
@php($icon = ['info' => 'info', 'success' => 'check', 'warning' => 'warning', 'danger' => 'warning'][$type] ?? 'info')
<div {{ $attributes->merge(['class' => 'hc-alert hc-alert-'.$type]) }} role="{{ in_array($type, ['danger', 'warning']) ? 'alert' : 'status' }}">
    <x-hc.icon :name="$icon"/>
    <div>@if($title)<strong>{{ $title }}</strong>@endif<p>{{ $slot }}</p></div>
</div>
