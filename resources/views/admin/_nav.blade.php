{{-- Tabs shared by the account administration pages, plus the status and validation messages. --}}
@php($locale = app()->getLocale())
<nav class="hc-chips" aria-label="{{ __('admin.nav') }}" style="margin-block-end:16px">
    @foreach(['admin.accounts.index' => ['admin.title', 'shield'], 'admin.accounts.new' => ['admin.new', 'plus'], 'admin.tasks' => ['admin.tasks_title', 'task'], 'admin.staff' => ['admin.staff_title', 'users']] as $name => [$label, $icon])
        @php($on = request()->routeIs($name) || ($name === 'admin.accounts.index' && request()->routeIs('admin.accounts.show')))
        <a class="hc-chip" href="{{ route($name, ['locale' => $locale]) }}" aria-pressed="{{ $on ? 'true' : 'false' }}" @if($on) aria-current="page" @endif><x-hc.icon :name="$icon" :size="16"/>{{ __($label) }}</a>
    @endforeach
</nav>
@if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif
@if($errors->any())<x-hc.alert type="danger" :title="__('admin.errors_title')" style="margin-block-end:16px">@foreach($errors->all() as $error){{ $error }}@if(! $loop->last)<br>@endif @endforeach</x-hc.alert>@endif
