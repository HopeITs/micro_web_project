{{--
    Search box of a plain list page whose text must stay out of the address (App\Support\TableTools\PrivateSearch): posted to
    tables.search, kept in the session, cleared with the button. Place it outside other forms.
--}}
@props(['list', 'value' => '', 'placeholder' => ''])
<form method="POST" action="{{ route('tables.search', ['locale' => app()->getLocale(), 'list' => $list]) }}" class="hc-ts-server" role="search">
    @csrf
    <input type="hidden" name="back" value="{{ request()->getQueryString() }}">
    <div class="hc-field">
        <label class="hc-label" for="ts-{{ $list }}">{{ __('tables.search') }}</label>
        <input id="ts-{{ $list }}" name="q" class="hc-input" type="search" maxlength="100" value="{{ $value }}" placeholder="{{ $placeholder }}" autocomplete="off">
    </div>
    <x-hc.button type="submit" variant="secondary" icon="search">{{ __('tables.search_button') }}</x-hc.button>
    @if($value !== '')<x-hc.button type="submit" variant="ghost" icon="x" name="clear" value="1">{{ __('tables.clear_search') }}</x-hc.button>@endif
</form>
