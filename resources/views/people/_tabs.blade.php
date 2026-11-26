{{-- The two views of the people area. $current = families | individuals. --}}
<nav class="fam-viewtabs" aria-label="{{ __('families.views') }}">
    @foreach(['families' => ['families.index', 'users'], 'individuals' => ['people.index', 'user']] as $key => [$route, $icon])
        <a href="{{ route($route, ['locale' => app()->getLocale()]) }}" @if($current === $key) aria-current="page" @endif><x-hc.icon :name="$icon" :size="18"/>{{ __('families.view_'.$key) }}</a>
    @endforeach
</nav>
