@props(['title', 'icon' => 'search'])
<div class="hc-empty"><x-hc.icon :name="$icon" :size="40"/><strong>{{ $title }}</strong><p>{{ $slot }}</p></div>
