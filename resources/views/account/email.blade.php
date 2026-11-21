@php($locale = app()->getLocale())
<x-layouts.hc :title="__('account.email_title')">
    <div class="hc-page-head"><div><p class="hc-eyebrow">{{ __('hc.account') }}</p><h1>{{ __('account.email_title') }}</h1><p>{{ __('account.email_intro', ['domain' => '@'.$domain]) }}</p></div></div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif
    <x-hc.card style="max-width:520px">
        @if($pending === null)
            <form method="POST" action="{{ route('account.email.send', ['locale' => $locale]) }}" class="hc-grid" style="gap:16px" novalidate>@csrf
                <x-hc.field :label="__('account.email')" for="email" :required="true">
                    <div class="hc-input-icon">
                        <x-hc.icon name="mail" :size="18"/>
                        <input class="hc-input ltr" id="email" name="email" type="email" maxlength="190" value="{{ old('email') }}" placeholder="{{ 'name@'.$domain }}" autocomplete="email" autocapitalize="none" spellcheck="false" required autofocus @error('email') aria-invalid="true" @enderror>
                    </div>
                </x-hc.field>
                <div><x-hc.button type="submit" icon="mail">{{ __('account.email_send') }}</x-hc.button></div>
            </form>
        @else
            @error('email')<x-hc.alert type="danger" style="margin-block-end:16px">{{ $message }}</x-hc.alert>@enderror
            <form method="POST" action="{{ route('account.email.confirm', ['locale' => $locale]) }}" class="hc-grid" style="gap:16px" novalidate>@csrf
                <x-hc.field :label="__('account.code')" for="code" :required="true" :hint="__('account.code_hint', ['email' => $pending, 'minutes' => $minutes])">
                    <input class="hc-input ltr hc-code-input" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required autofocus @error('code') aria-invalid="true" @enderror>
                </x-hc.field>
                <div class="hc-toolbar" style="padding:0;gap:8px">
                    <x-hc.button type="submit" icon="check">{{ __('account.code_confirm') }}</x-hc.button>
                    <button type="submit" name="restart" value="1" class="hc-btn hc-btn-ghost" formnovalidate>{{ __('account.code_restart') }}</button>
                </div>
            </form>
        @endif
    </x-hc.card>
</x-layouts.hc>
