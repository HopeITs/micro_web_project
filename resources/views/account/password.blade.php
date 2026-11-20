<x-layouts.hc :title="__('account.change_title')">
    <div class="hc-page-head"><div><p class="hc-eyebrow">{{ __('hc.account') }}</p><h1>{{ __('account.change_title') }}</h1><p>{{ __('account.change_intro', ['min' => \App\Services\AccountPasswords::MIN_LENGTH]) }}</p></div></div>
    @if($forced)<x-hc.alert type="warning" style="margin-block-end:16px">{{ __('account.first_login') }}</x-hc.alert>@endif
    <x-hc.card style="max-width:520px">
        <form method="POST" action="{{ route('account.password.update', ['locale' => app()->getLocale()]) }}" class="hc-grid" style="gap:16px">@csrf
            <x-hc.field :label="__('account.new_password')" for="password" :required="true"><input class="hc-input ltr" id="password" name="password" type="password" autocomplete="new-password" required></x-hc.field>
            <x-hc.field :label="__('account.confirm_password')" for="password_confirmation" :required="true"><input class="hc-input ltr" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></x-hc.field>
            <div><x-hc.button type="submit" icon="check">{{ __('account.change') }}</x-hc.button></div>
        </form>
    </x-hc.card>
</x-layouts.hc>
