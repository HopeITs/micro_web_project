<div x-data x-on:form-errors.window="$nextTick(() => document.querySelector('.hc-error')?.scrollIntoView({block: 'center', behavior: 'smooth'}))">
    @if($message !== '')<x-hc.alert type="success" style="margin-block-end:16px">{{ $message }}</x-hc.alert>@endif
    @if($errors->any())<x-hc.alert type="danger" style="margin-block-end:16px">{{ __('applications.errors.fix', ['count' => count($errors->all())]) }}</x-hc.alert>@endif
    @unless($editable)<x-hc.alert type="info" style="margin-block-end:16px">{{ __('annexes.read_only') }}</x-hc.alert>@endunless

    <form wire:submit="save">
    <fieldset @disabled(! $editable) style="border:0;padding:0;margin:0;min-width:0">
    @if($kind === 'agriculture')
        <x-hc.card :title="__('annexes.agriculture_questions', ['count' => $questions->count()])">
            <ol style="margin:0;padding-inline-start:20px;display:grid;gap:16px">
            @foreach($questions as $q)
                @php($value = $answers[$q->id] ?? '')
                @php($asList = is_array($value))
                <li wire:key="ag-{{ $q->id }}">
                    <p style="font-weight:600;margin-block-end:6px">{{ $q->label }}
                        @if($q->multiple)<span class="muted" style="font-weight:400;font-size:.85rem">({{ __('annexes.several') }})</span>@endif</p>
                    <div class="hc-choice" role="{{ $asList ? 'group' : 'radiogroup' }}" aria-label="{{ $q->label }}">
                        @foreach($q->answers as $a)
                            <label><input type="{{ $asList ? 'checkbox' : 'radio' }}" value="{{ $a->id }}" wire:model="answers.{{ $q->id }}" @if(! $asList) name="ag-{{ $q->id }}" @endif>{{ $a->label }}</label>
                        @endforeach
                    </div>
                    @if($asList && ! $q->multiple)<p class="hc-hint">{{ __('annexes.old_several') }}</p>@endif
                    @error('answers.'.$q->id)<p class="hc-error">{{ $message }}</p>@enderror
                </li>
            @endforeach
            </ol>
        </x-hc.card>
    @else
        <x-hc.card :title="__('annexes.rehab_header')" style="margin-block-end:16px">
            <div class="hc-form-grid">
                <x-hc.field :label="__('annexes.fields.property_ownership')" for="rh-own" :error="$errors->first('header.property_ownership')"><input class="hc-input" id="rh-own" wire:model="header.property_ownership" maxlength="100"></x-hc.field>
                <x-hc.field :label="__('annexes.fields.property_area')" for="rh-area" :error="$errors->first('header.property_area')"><input class="hc-input" id="rh-area" wire:model="header.property_area" maxlength="100"></x-hc.field>
                <x-hc.field :label="__('annexes.fields.street_id')" for="rh-street" :error="$errors->first('header.street_id')">
                    @include('people._input', ['model' => 'header.street_id', 'id' => 'rh-street', 'field' => 'street_id', 'spec' => ['kind' => 'lookup'], 'options' => $streets])
                </x-hc.field>
                <x-hc.field :label="__('annexes.fields.address_after_fund')" for="rh-address" :error="$errors->first('header.address_after_fund')"><input class="hc-input" id="rh-address" wire:model="header.address_after_fund" maxlength="255"></x-hc.field>
            </div>
        </x-hc.card>

        @foreach($groups as $group => $list)
        <x-hc.card :title="__('annexes.groups.'.$group)" :flush="true" style="margin-block-end:16px">
            <div class="hc-table-wrap"><table class="hc-table stack">
                <thead><tr><th>{{ __('annexes.item') }}</th><th>{{ __('annexes.kind') }}</th><th>{{ __('annexes.fields.note') }}</th><th class="num">{{ __('annexes.fields.quantity') }}</th><th class="num">{{ __('annexes.fields.price') }}</th><th>{{ __('annexes.fields.result') }}</th></tr></thead>
                <tbody>
                @foreach($list as $q)
                    @php($value = $items[$q->id]['answers'] ?? '')
                    @php($asList = is_array($value))
                    <tr wire:key="rh-{{ $q->id }}">
                        <th scope="row" data-label="{{ __('annexes.item') }}" style="font-weight:600">{{ $q->label }}</th>
                        <td data-label="{{ __('annexes.kind') }}" style="min-width:260px">
                            <div class="hc-choice" role="{{ $asList ? 'group' : 'radiogroup' }}" aria-label="{{ $q->label }}">
                                @foreach($q->answers as $a)
                                    <label><input type="{{ $asList ? 'checkbox' : 'radio' }}" value="{{ $a->id }}" wire:model="items.{{ $q->id }}.answers" @if(! $asList) name="rh-{{ $q->id }}" @endif>{{ $a->label }}</label>
                                @endforeach
                            </div>
                            @if($asList && ! $q->multiple)<p class="hc-hint">{{ __('annexes.old_several') }}</p>@endif
                            @error('items.'.$q->id.'.answers')<p class="hc-error">{{ $message }}</p>@enderror
                        </td>
                        <td data-label="{{ __('annexes.fields.note') }}" style="min-width:160px"><input class="hc-input" wire:model="items.{{ $q->id }}.note" aria-label="{{ __('annexes.fields.note') }}"></td>
                        <td data-label="{{ __('annexes.fields.quantity') }}" style="min-width:80px"><input class="hc-input ltr" inputmode="numeric" wire:model.blur="items.{{ $q->id }}.quantity" aria-label="{{ __('annexes.fields.quantity') }}">@error('items.'.$q->id.'.quantity')<p class="hc-error">{{ $message }}</p>@enderror</td>
                        <td data-label="{{ __('annexes.fields.price') }}" style="min-width:110px"><input class="hc-input ltr" inputmode="decimal" wire:model.blur="items.{{ $q->id }}.price" aria-label="{{ __('annexes.fields.price') }}">@error('items.'.$q->id.'.price')<p class="hc-error">{{ $message }}</p>@enderror</td>
                        <td data-label="{{ __('annexes.fields.result') }}" style="min-width:140px"><input class="hc-input" wire:model="items.{{ $q->id }}.result" aria-label="{{ __('annexes.fields.result') }}"></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </x-hc.card>
        @endforeach
    @endif
    </fieldset>

    <x-hc.action-bar>
        <a class="hc-btn hc-btn-ghost" href="{{ route('projects.show', ['locale' => app()->getLocale(), 'project' => $projectId]) }}">{{ __('hc.back') }}</a>
        @if($kind === 'rehabilitation')<span class="muted">{{ __('annexes.total') }}: <strong class="num">{{ number_format($total) }}</strong></span>@endif
        <span class="spacer"></span>
        @if($editable)
            @if($exists)<x-hc.button variant="ghost" wire:click="remove" wire:confirm="{{ __('annexes.confirm_delete') }}" style="color:var(--danger, #b42318)">{{ __('annexes.delete') }}</x-hc.button>@endif
            <span wire:loading wire:target="save" class="muted">{{ __('people.saving') }}</span>
            <x-hc.button type="submit" icon="check" wire:loading.attr="disabled">{{ __('annexes.save') }}</x-hc.button>
        @endif
    </x-hc.action-bar>
    </form>
</div>
