@php
    $en = app()->getLocale() === 'en';
    $project = $panel['project'];
    $manage = $panel['canManage'];
    $file = $panel['file'];
    $state = $panel['state'];
    $status = \App\Support\ProjectStatus::class;
@endphp
<div class="hc-grid">
    @if($notice !== '')<x-hc.alert type="success">{{ $notice }}</x-hc.alert>@endif
    @if($noLegalWarning)<x-hc.alert type="warning">{{ __('executive.no_legal') }}</x-hc.alert>@endif
    @error('file')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror
    @if(! $manage)<x-hc.alert type="info">{{ __('executive.read_only') }}</x-hc.alert>@endif

    <x-hc.card :title="__('executive.project')">
        <dl class="hc-dl">
            <div><dt>{{ __('executive.project_number') }}</dt><dd class="ltr"><x-hc.project-number :code="\Illuminate\Support\Facades\DB::table('cities')->where('id', $project->city_id)->value('code')" :number="$project->project_number"/></dd></div>
            <div><dt>{{ __('executive.beneficiary') }}</dt><dd>{{ $panel['beneficiary'] !== '' ? $panel['beneficiary'] : __('hc.not_set') }}</dd></div>
            <div><dt>{{ __('executive.project_name') }}</dt><dd>{{ $project->name ?: __('hc.not_set') }}</dd></div>
            <div><dt>{{ __('executive.state') }}</dt><dd>@if($state)<x-hc.badge :tone="$status::tone($state->state_en)" :icon="$status::icon($state->state_en)">{{ $en && $state->state_en ? $state->state_en : $state->state_ar }}</x-hc.badge>@else<span class="muted">{{ __('hc.not_set') }}</span>@endif</dd></div>
        </dl>
    </x-hc.card>

    <x-hc.card :title="$file ? __('executive.file').' '.$file->file_number : __('executive.new')">
        @if(! $file && ! $manage)
            <p class="muted">{{ __('executive.none') }}</p>
        @elseif($manage)
            @if(! $file)<p class="muted" style="margin:0 0 12px">{{ __('executive.none_yet') }}</p>@endif
            <form wire:submit="save" class="hc-fin-form">
                <x-hc.field :label="__('executive.number')" for="exe-number" :error="$errors->first('form.number')" required>
                    <input class="hc-input ltr" id="exe-number" wire:model="form.number" maxlength="50" autocomplete="off">
                </x-hc.field>
                <x-hc.field :label="__('executive.begin')" for="exe-begin" :error="$errors->first('form.begin_date')" required>
                    <input class="hc-input num ltr" type="date" id="exe-begin" wire:model.live="form.begin_date">
                </x-hc.field>
                <x-hc.field :label="__('executive.current')" for="exe-current" :error="$errors->first('form.current_date')" :hint="$file ? null : __('executive.current_hint')">
                    <input class="hc-input num ltr" type="date" id="exe-current" wire:model.live="form.current_date">
                </x-hc.field>
                <x-hc.field :label="__('executive.months')" for="exe-months" :error="$errors->first('form.months')" :hint="__('executive.months_hint')">
                    <input class="hc-input num ltr" inputmode="numeric" id="exe-months" wire:model.live="form.months" autocomplete="off">
                </x-hc.field>
                <x-hc.field :label="__('executive.expires')" for="exe-expires" :hint="__('executive.expires_hint')">
                    <output class="hc-input num ltr" id="exe-expires" style="display:block">{{ $expires ?? '—' }}</output>
                </x-hc.field>
                <x-hc.field :label="__('executive.impound_date')" for="exe-impound-date" :error="$errors->first('form.impound_date')">
                    <input class="hc-input num ltr" type="date" id="exe-impound-date" wire:model="form.impound_date">
                </x-hc.field>
                <x-hc.field class="wide" :label="__('executive.impound_type')" for="exe-impound-type" :error="$errors->first('form.impound_type')">
                    <input class="hc-input" id="exe-impound-type" wire:model="form.impound_type" maxlength="100" autocomplete="off">
                </x-hc.field>
                <div class="wide" style="display:flex;gap:8px;flex-wrap:wrap">
                    <x-hc.button type="submit" icon="check" wire:loading.attr="disabled" wire:target="save">{{ $file ? __('executive.save') : __('executive.create') }}</x-hc.button>
                    @if($file)
                        <x-hc.button variant="ghost" icon="x" wire:click="delete" wire:confirm="{{ __('executive.delete_confirm') }}" wire:loading.attr="disabled" wire:target="delete">{{ __('executive.delete') }}</x-hc.button>
                    @endif
                </div>
                <p class="hc-hint wide" style="margin:0">{{ __('executive.notice_hint') }}</p>
                @if($panel['noLegal'])<x-hc.alert type="warning" class="wide">{{ __('executive.no_legal') }}</x-hc.alert>@endif
            </form>
        @else
            <dl class="hc-dl">
                <div><dt>{{ __('executive.number') }}</dt><dd class="ltr">{{ $file->file_number }}</dd></div>
                <div><dt>{{ __('executive.begin') }}</dt><dd class="num">{{ $file->begin_on ?: '—' }}</dd></div>
                <div><dt>{{ __('executive.current') }}</dt><dd class="num">{{ $file->current_on ?: '—' }}</dd></div>
                <div><dt>{{ __('executive.months') }}</dt><dd class="num">{{ $file->months ?? '—' }}</dd></div>
                <div><dt>{{ __('executive.expires') }}</dt><dd class="num">{{ $panel['expires'] ?? '—' }}</dd></div>
                <div><dt>{{ __('executive.impound_date') }}</dt><dd class="num">{{ $file->impound_on ?: '—' }}</dd></div>
                <div><dt>{{ __('executive.impound_type') }}</dt><dd>{{ $file->impound_type ?: '—' }}</dd></div>
            </dl>
        @endif
    </x-hc.card>
</div>
