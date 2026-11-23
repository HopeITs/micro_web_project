@php
    $locale = app()->getLocale();
    $selected = collect($scans)->first(fn ($s) => (int) $s->id === (int) $attachmentId);
    $url = $selected ? route('attachments.file', ['locale' => $locale, 'attachment' => $selected->id]) : null;
    $isPdf = $selected && strtolower((string) $selected->extension) === 'pdf';
    $tone = ['high' => 'ok', 'medium' => 'warn', 'low' => 'danger'];
    $errorKey = $error === null ? null : (str_starts_with($error, 'http_') ? 'http' : $error);
@endphp
<div>
    <div class="hc-page-head">
        <div>
            <p class="hc-eyebrow">{{ \App\Services\People\PeopleService::fullName($person) }}</p>
            <h1>{{ __('identity.title') }}</h1>
            <p>{{ __('identity.intro') }}</p>
        </div>
        <x-hc.button variant="secondary" :href="route('people.show', ['locale' => $locale, 'person' => $person->id])">{{ __('people.back') }}</x-hc.button>
    </div>

    @if(! $enabled)
        <x-hc.alert type="warning" :title="__('identity.disabled_title')" style="margin-block-end:16px">{{ __('identity.disabled.'.$driver) }}</x-hc.alert>
    @endif
    @if($errorKey)<x-hc.alert type="danger" style="margin-block-end:16px">{{ __('identity.errors.'.$errorKey) }}</x-hc.alert>@endif
    @if($problems !== [])
        <x-hc.alert type="danger" style="margin-block-end:16px">@foreach($problems as $problem){{ $problem }}@if(! $loop->last)<br>@endif @endforeach</x-hc.alert>
    @endif

    @if($scans === [])
        <x-hc.empty :title="__('identity.no_scans')" icon="file"/>
    @else
    <style>.idr{display:grid;gap:16px;align-items:start;grid-template-columns:minmax(0,5fr) minmax(0,8fr)}@media(max-width:1100px){.idr{grid-template-columns:minmax(0,1fr)}}</style>
    <div class="idr">
        <x-hc.card :title="__('identity.scan')">
            <div class="hc-field" style="margin-block-end:12px">
                <label class="hc-label" for="scan">{{ __('identity.scan') }}</label>
                <select id="scan" class="hc-select" wire:model.live="attachmentId">
                    @foreach($scans as $scan)<option value="{{ $scan->id }}">{{ $scan->original_name ?: '#'.$scan->id }}{{ $scan->is_id ? ' · '.__('identity.is_id') : '' }}</option>@endforeach
                </select>
            </div>
            @if($url)
                @if($isPdf)<iframe src="{{ $url }}" title="{{ __('identity.scan') }}" style="width:100%;height:70vh;border:1px solid var(--line);border-radius:var(--radius-sm);background:#fff"></iframe>
                @else<img src="{{ $url }}" alt="{{ __('identity.scan') }}" style="width:100%;max-height:72vh;object-fit:contain;border:1px solid var(--line);border-radius:var(--radius-sm);background:#fff">@endif
            @endif
        </x-hc.card>

        <x-hc.card :title="__('identity.title')">
            <p class="muted" style="font-size:.85rem;margin-block:0 10px">{{ $local ? __('identity.privacy_local') : __('identity.privacy') }} <strong>{{ __('identity.engines.'.$driver) }}</strong></p>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-block-end:12px">
                <x-hc.button type="button" icon="id" wire:click="read" wire:loading.attr="disabled" wire:target="read" :disabled="! $enabled || $remaining === 0">{{ $reading === [] ? __('identity.read') : __('identity.read_again') }}</x-hc.button>
                <span class="muted" wire:loading wire:target="read">{{ __('identity.reading') }}</span>
                @if($remaining !== null)<span class="muted" style="font-size:.85rem">{{ __('identity.remaining', ['count' => $remaining]) }}</span>@endif
            </div>

            @if($reading !== [])
                <p style="margin-block:0 8px"><strong>{{ __('identity.document_type') }}:</strong> {{ __('identity.types.'.$reading['type']) }}</p>
                @if(($reading['notes'] ?? '') !== '')<p class="muted" style="font-size:.85rem;margin-block:0 8px">{{ __('identity.notes') }}: {{ $reading['notes'] }}</p>@endif
                <p class="muted" style="font-size:.85rem;margin-block:0 8px">{{ __('identity.check_hint') }}</p>
                <div class="hc-table-wrap">
                    <table class="hc-table stack">
                        <thead><tr><th>{{ __('identity.col_field') }}</th><th>{{ __('identity.col_read') }}</th><th>{{ __('identity.col_conf') }}</th><th>{{ __('identity.col_use') }}</th></tr></thead>
                        <tbody>
                        @foreach(\App\Services\Identity\IdCardReader::FIELDS as $field)
                            @php($read = $reading['fields'][$field])
                            <tr>
                                <td data-label="{{ __('identity.col_field') }}">{{ __('people.fields.'.$field) }}</td>
                                <td data-label="{{ __('identity.col_read') }}">
                                    @if($field === 'sex')
                                        <select class="hc-select" wire:model="values.sex"><option value="">—</option><option value="male">{{ __('people.male') }}</option><option value="female">{{ __('people.female') }}</option></select>
                                    @else
                                        <input class="hc-input" @if($field === 'birth_date') type="date" @else type="text" dir="auto" @endif wire:model="values.{{ $field }}" maxlength="100" aria-label="{{ __('people.fields.'.$field) }}">
                                    @endif
                                    <div class="muted" style="font-size:.8rem;margin-block-start:4px">{{ __('identity.col_now') }}: @if($currentValues[$field] !== '')<bdi class="num">{{ $field === 'sex' ? __('people.'.$currentValues[$field]) : $currentValues[$field] }}</bdi>@else—@endif</div>
                                    @if($read['alternatives'] !== [])
                                        <div class="muted" style="font-size:.8rem;margin-block-start:4px">{{ __('identity.alternatives') }}:
                                            @foreach($read['alternatives'] as $alt)<button type="button" class="hc-chip" wire:click="pick('{{ $field }}', @js($alt))"><bdi>{{ $alt }}</bdi></button>@endforeach</div>
                                    @endif
                                    @if($read['warning'])<div style="font-size:.8rem;color:var(--danger)">{{ __('identity.warnings.'.$read['warning']) }}</div>@endif
                                </td>
                                <td data-label="{{ __('identity.col_conf') }}"><x-hc.badge :tone="$tone[$read['confidence']]">{{ __('identity.confidence.'.$read['confidence']) }}</x-hc.badge></td>
                                <td data-label="{{ __('identity.col_use') }}"><input type="checkbox" wire:model="use.{{ $field }}" style="width:20px;height:20px" aria-label="{{ __('identity.col_use') }}: {{ __('people.fields.'.$field) }}"></td>
                            </tr>
                        @endforeach
                        @foreach(['nationality', 'id_document_type'] as $field)
                            <tr>
                                <td data-label="{{ __('identity.col_field') }}">{{ __('people.fields.'.$field) }}</td>
                                <td data-label="{{ __('identity.col_read') }}"><select class="hc-select" wire:model="values.{{ $field }}"><option value="">—</option>@foreach(\App\Services\People\PersonFields::personFlat()[$field]['options'] as $code)<option value="{{ $code }}">{{ __('people.choices.'.$field.'.'.$code) }}</option>@endforeach</select><div class="muted" style="font-size:.8rem;margin-block-start:4px">{{ __('identity.col_now') }}: @if($currentValues[$field] !== ''){{ __('people.choices.'.$field.'.'.$currentValues[$field]) }}@else—@endif</div></td>
                                <td data-label="{{ __('identity.col_conf') }}"></td>
                                <td data-label="{{ __('identity.col_use') }}"><input type="checkbox" wire:model="use.{{ $field }}" style="width:20px;height:20px" aria-label="{{ __('identity.col_use') }}: {{ __('people.fields.'.$field) }}"></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="display:flex;gap:10px;margin-block-start:14px;flex-wrap:wrap">
                    <x-hc.button type="button" icon="check" wire:click="save">{{ __('identity.save') }}</x-hc.button>
                    @if($needsConfirm)<x-hc.button type="button" variant="secondary" wire:click="save(true)">{{ __('identity.save_anyway') }}</x-hc.button>@endif
                </div>
            @endif
        </x-hc.card>
    </div>
    @endif
</div>
