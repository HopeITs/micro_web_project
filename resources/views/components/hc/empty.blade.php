{{-- Empty state: what is missing, one sentence on what to do, and at most one action (slot `action`). --}}
@props(['title', 'icon' => 'search'])
<div {{ $attributes->class('hc-empty') }}><x-hc.icon :name="$icon" :size="40"/><strong>{{ $title }}</strong><p>{{ $slot }}</p>@isset($action)<div class="hc-empty-action">{{ $action }}</div>@endisset</div>
