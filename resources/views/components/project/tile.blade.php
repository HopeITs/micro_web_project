{{-- One module of a project as a link: its name and a live line or two. alert marks it with an icon and colour (the words are in "needs attention"). --}}
@props(['module'])
<a {{ $attributes->class(['hc-pj-tile', 'is-alert' => $module['alert']]) }} href="{{ $module['url'] }}" data-module="{{ $module['key'] }}">
    <span class="hc-pj-tile-icon"><x-hc.icon :name="$module['icon']" :size="20"/></span>
    <span class="hc-pj-tile-text">
        <strong>{{ $module['label'] }}@if($module['alert'])<span class="hc-pj-tile-flag" title="{{ __('project_page.needs_attention') }}"><x-hc.icon name="warning" :size="15"/><span class="sr-only">{{ __('project_page.needs_attention') }}</span></span>@endif</strong>
        @foreach($module['lines'] as $line)<small>{{ $line }}</small>@endforeach
        @if($module['percent'] !== null)<span class="hc-progress" aria-hidden="true"><span style="width: {{ $module['percent'] }}%"></span></span>@endif
    </span>
</a>
