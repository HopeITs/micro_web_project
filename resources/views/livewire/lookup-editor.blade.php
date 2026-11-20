<div>
    @php
        $en = app()->getLocale() === 'en';
        $fields = $definition['fields'];
        $label = fn ($o) => $o ? ($en && $o->name_en ? $o->name_en : $o->name_ar) : '';
        $err = fn ($key) => $errors->first($key);
    @endphp

    @if($definition['city'])
    <div class="hc-chips" role="group" aria-label="{{ __('lookups.city') }}" style="margin-block-end:16px">
        @foreach($cities as $c)
            <button type="button" class="hc-chip" wire:click="selectCity({{ $c->id }})" aria-pressed="{{ (int) $c->id === $city ? 'true' : 'false' }}">{{ $en ? $c->name_en : $c->name_ar }} <span class="muted ltr">{{ $c->code }}</span></button>
        @endforeach
    </div>
    @endif

    @if(! $editable)<x-hc.alert type="info" style="margin-block-end:16px">{{ __('lookups.read_only_hint') }}</x-hc.alert>@endif
    @if($message !== '')<x-hc.alert :type="$tone" style="margin-block-end:16px">{{ $message }}</x-hc.alert>@endif

    @if($editable)
    <x-hc.card :title="__('lookups.add_title')" style="margin-block-end:16px">
        <form wire:submit="create" class="hc-form-grid" style="align-items:end">
            <x-hc.field :label="__('lookups.name_ar')" for="draft-name_ar" :required="true" :error="$err('draft.name_ar')"><input class="hc-input" id="draft-name_ar" wire:model="draft.name_ar" maxlength="255" autocomplete="off"></x-hc.field>
            <x-hc.field :label="__('lookups.name_en')" for="draft-name_en" :error="$err('draft.name_en')"><input class="hc-input ltr" id="draft-name_en" wire:model="draft.name_en" maxlength="255" autocomplete="off" dir="ltr"></x-hc.field>
            @foreach($fields as $field => $spec)
                <x-hc.field :label="__('lookups.fields.'.$field)" :for="'draft-'.$field" :required="$spec['required']" :error="$err('draft.'.$field)">
                    @if($spec['kind'] === 'parent')
                        <select class="hc-select" id="draft-{{ $field }}" wire:model="draft.{{ $field }}"><option value="">{{ __('lookups.choose') }}</option>@foreach($options[$field] as $o)<option value="{{ $o->id }}">{{ $label($o) }}</option>@endforeach</select>
                    @elseif($spec['kind'] === 'choice')
                        <select class="hc-select" id="draft-{{ $field }}" wire:model="draft.{{ $field }}"><option value="">{{ __('lookups.choose') }}</option>@foreach($spec['options'] as $code)<option value="{{ $code }}">{{ __('lookups.choices.'.$field.'.'.$code) }}</option>@endforeach</select>
                    @else
                        <input class="hc-input ltr" id="draft-{{ $field }}" wire:model="draft.{{ $field }}" maxlength="{{ $spec['max'] }}" autocomplete="off">
                    @endif
                </x-hc.field>
            @endforeach
            <div class="hc-field"><x-hc.button type="submit" icon="plus" wire:loading.attr="disabled">{{ __('lookups.add') }}</x-hc.button></div>
        </form>
    </x-hc.card>
    @endif

    <x-hc.card :flush="true">
        <div class="hc-toolbar" style="align-items:end">
            <div class="hc-field grow">
                <label class="hc-label" for="lookup-q">{{ __('lookups.search') }}</label>
                <input id="lookup-q" class="hc-input" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('lookups.search_placeholder') }}" autocomplete="off">
            </div>
            <div class="hc-choice"><label><input type="checkbox" wire:model.live="hidden">{{ __('lookups.show_hidden') }}</label></div>
            <span class="muted" aria-live="polite">{{ __('lookups.results', ['count' => $rows->count()]) }}</span>
            <a class="hc-btn hc-btn-secondary hc-btn-sm" href="{{ route('lookups.export', ['locale' => app()->getLocale(), 'list' => $list, 'city' => $definition['city'] ? $city : null, 'format' => 'xlsx']) }}"><x-hc.icon name="file" :size="16"/>{{ __('lookups.export') }} Excel</a>
        </div>
        <div class="hc-table-wrap" wire:loading.class="opacity-60">
            @if($rows->isEmpty())
                <x-hc.empty :title="__('lookups.empty')">{{ __('lookups.empty_hint') }}</x-hc.empty>
            @else
            <table class="hc-table stack">
                <thead><tr><th>{{ __('lookups.name_ar') }}</th><th>{{ __('lookups.name_en') }}</th>@foreach($fields as $field => $_)<th>{{ __('lookups.fields.'.$field) }}</th>@endforeach<th class="num">{{ __('lookups.used') }}</th><th>{{ __('lookups.status') }}</th>@if($editable)<th></th>@endif</tr></thead>
                <tbody>
                @foreach($rows as $row)
                    @php($used = $usage[(int) $row->id] ?? 0)
                    @if($editing === (int) $row->id)
                    <tr wire:key="row-{{ $row->id }}-edit">
                        <td data-label="{{ __('lookups.name_ar') }}"><input class="hc-input" wire:model="edit.name_ar" maxlength="255" aria-label="{{ __('lookups.name_ar') }}" wire:keydown.enter="saveEdit" wire:keydown.escape="cancel">@if($err('edit.name_ar'))<p class="hc-error">{{ $err('edit.name_ar') }}</p>@endif</td>
                        <td data-label="{{ __('lookups.name_en') }}"><input class="hc-input ltr" dir="ltr" wire:model="edit.name_en" maxlength="255" aria-label="{{ __('lookups.name_en') }}" wire:keydown.enter="saveEdit" wire:keydown.escape="cancel"></td>
                        @foreach($fields as $field => $spec)
                        <td data-label="{{ __('lookups.fields.'.$field) }}">
                            @if($spec['kind'] === 'parent')
                                <select class="hc-select" wire:model="edit.{{ $field }}" aria-label="{{ __('lookups.fields.'.$field) }}"><option value="">{{ __('lookups.choose') }}</option>@foreach($options[$field] as $o)<option value="{{ $o->id }}">{{ $label($o) }}</option>@endforeach</select>
                            @elseif($spec['kind'] === 'choice')
                                <select class="hc-select" wire:model="edit.{{ $field }}" aria-label="{{ __('lookups.fields.'.$field) }}">@foreach($spec['options'] as $code)<option value="{{ $code }}">{{ __('lookups.choices.'.$field.'.'.$code) }}</option>@endforeach</select>
                            @else
                                <input class="hc-input ltr" wire:model="edit.{{ $field }}" maxlength="{{ $spec['max'] }}" aria-label="{{ __('lookups.fields.'.$field) }}">
                            @endif
                            @if($err('edit.'.$field))<p class="hc-error">{{ $err('edit.'.$field) }}</p>@endif
                        </td>
                        @endforeach
                        <td class="num" data-label="{{ __('lookups.used') }}">{{ $used ?: '—' }}</td>
                        <td data-label="{{ __('lookups.status') }}">@if($row->is_active)<x-hc.badge tone="ok">{{ __('lookups.active') }}</x-hc.badge>@else<x-hc.badge>{{ __('lookups.hidden') }}</x-hc.badge>@endif</td>
                        <td style="white-space:nowrap"><x-hc.button size="sm" icon="check" wire:click="saveEdit">{{ __('lookups.save') }}</x-hc.button> <x-hc.button size="sm" variant="ghost" wire:click="cancel">{{ __('lookups.cancel') }}</x-hc.button></td>
                    </tr>
                    @else
                    <tr wire:key="row-{{ $row->id }}" @unless($row->is_active) style="opacity:.6" @endunless>
                        <td data-label="{{ __('lookups.name_ar') }}">{{ $row->name_ar }}</td>
                        <td data-label="{{ __('lookups.name_en') }}" class="ltr">{{ $row->name_en ?: '—' }}</td>
                        @foreach($fields as $field => $spec)
                        <td data-label="{{ __('lookups.fields.'.$field) }}">{{ match($spec['kind']) { 'parent' => $row->{$field.'_name'} ?? '—', 'choice' => $row->{$field} ? __('lookups.choices.'.$field.'.'.$row->{$field}) : '—', default => $row->{$field} ?: '—' } }}</td>
                        @endforeach
                        <td class="num" data-label="{{ __('lookups.used') }}">{{ $used ? __('lookups.used_count', ['count' => number_format($used)]) : __('lookups.unused') }}</td>
                        <td data-label="{{ __('lookups.status') }}">@if($row->is_active)<x-hc.badge tone="ok">{{ __('lookups.active') }}</x-hc.badge>@else<x-hc.badge>{{ __('lookups.hidden') }}</x-hc.badge>@endif</td>
                        @if($editable)
                        <td style="white-space:nowrap">
                            <x-hc.button size="sm" variant="ghost" wire:click="startEdit({{ $row->id }})">{{ __('lookups.edit') }}</x-hc.button>
                            <x-hc.button size="sm" variant="ghost" wire:click="toggle({{ $row->id }}, {{ $row->is_active ? 'false' : 'true' }})">{{ $row->is_active ? __('lookups.hide') : __('lookups.show') }}</x-hc.button>
                            <x-hc.button size="sm" variant="ghost" wire:click="remove({{ $row->id }})" wire:confirm="{{ __('lookups.confirm_delete') }}" style="color:var(--danger, #b42318)">{{ __('lookups.delete') }}</x-hc.button>
                        </td>
                        @endif
                    </tr>
                    @endif
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </x-hc.card>
</div>
