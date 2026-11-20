<div>
    @php($en = app()->getLocale() === 'en')
    @php($live = ['first_name', 'last_name', 'father_name', 'national_number'])
    <form wire:submit="save">
        <div class="hc-grid">
            @if($cities->isNotEmpty())
            <x-hc.card>
                <div class="hc-form-grid">
                    <x-hc.field :label="__('hc.city')" for="city" :required="true">
                        <select class="hc-select" id="city" wire:model.live="city">@foreach($cities as $c)<option value="{{ $c->id }}">{{ $en ? $c->name_en : $c->name_ar }}</option>@endforeach</select>
                    </x-hc.field>
                    <x-hc.field :label="__('people.family_number')" for="family_number" :hint="__('people.family_number_hint')" :error="$errors->first('family_number')">
                        <input class="hc-input ltr" id="family_number" wire:model.live.debounce.400ms="familyNumber" maxlength="50" autocomplete="off">
                    </x-hc.field>
                </div>
            </x-hc.card>
            @endif

            @if($duplicates || $holders->isNotEmpty())
            <x-hc.alert type="warning" :title="__('people.duplicates_title')">
                @foreach($duplicates as $d)
                    <a class="row-link" href="{{ route('people.show', ['locale' => app()->getLocale(), 'person' => $d->id]) }}" target="_blank">{{ $d->name }}</a>
                    — {{ $d->city }} · {{ __('people.dup_'.$d->reason) }}@if($d->national_number) · <span class="ltr">{{ $d->national_number }}</span>@endif
                    @if($d->projects) · {{ __('people.projects') }}: <span class="ltr">{{ implode('، ', $d->projects) }}</span>@endif<br>
                @endforeach
                @foreach($holders as $h)
                    <a class="row-link" href="{{ route('people.show', ['locale' => app()->getLocale(), 'person' => $h->id]) }}" target="_blank">{{ $h->name }}</a> — {{ __('people.dup_family') }}<br>
                @endforeach
                <span class="hc-choice" style="margin-block-start:8px"><label><input type="checkbox" wire:model.live="confirmed">{{ __('people.not_same_person') }}</label></span>
            </x-hc.alert>
            @endif
            @error('confirm')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror

            @foreach($groups as $group => $fields)
                @continue($group === 'military' && ($person['sex'] ?? '') !== 'male')
                <x-hc.section :title="__('people.groups.'.$group)" :step="$loop->iteration">
                    <div class="hc-form-grid">
                    @foreach($fields as $field => $spec)
                        <x-hc.field :label="__('people.fields.'.$field)" :for="'p-'.$field" :required="$spec['required'] ?? false" :error="$errors->first('person.'.$field)"
                            :hint="$field === 'national_number' && $nationalWarning ? __('people.national_11') : null">
                            @include('people._input', ['model' => 'person.'.$field, 'id' => 'p-'.$field, 'options' => $options[$field] ?? collect(), 'live' => in_array($field, $live, true)])
                        </x-hc.field>
                    @endforeach
                    </div>
                </x-hc.section>
            @endforeach
        </div>
        <x-hc.action-bar>
            <a class="hc-btn hc-btn-ghost" href="{{ $personId ? route('people.show', ['locale' => app()->getLocale(), 'person' => $personId]) : route('people.index', ['locale' => app()->getLocale()]) }}">{{ __('people.cancel') }}</a>
            <span class="spacer"></span>
            <span wire:loading class="muted">{{ __('people.saving') }}</span>
            <x-hc.button type="submit" icon="check" wire:loading.attr="disabled">{{ __('people.save') }}</x-hc.button>
        </x-hc.action-bar>
    </form>
</div>
