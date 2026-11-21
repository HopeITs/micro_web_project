@php
    $en = app()->getLocale() === 'en';
    $name = fn ($row) => $row ? ($en && ($row->name_en ?? null) ? $row->name_en : $row->name_ar) : '—';
    $project = $panel['project'];
    $manage = $panel['canManage'] && $panel['eligible'];
    $loan = $panel['loan'];
    $career = $panel['career'];
    $money = fn ($v) => number_format((float) $v, str_contains((string) $v, '.') ? 2 : 0);
    $cityCode = \Illuminate\Support\Facades\DB::table('cities')->where('id', $project->city_id)->value('code');
@endphp
<div class="hc-grid">
    @if($notice !== '')<x-hc.alert type="success">{{ $notice }}</x-hc.alert>@endif
    @error('version')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror
    @error('rows')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror
    @if(! $panel['eligible'])<x-hc.alert type="warning">{{ __('purchasing.not_funded') }}</x-hc.alert>
    @elseif(! $panel['canManage'])<x-hc.alert type="info">{{ __('purchasing.read_only') }}</x-hc.alert>@endif

    <x-hc.card :title="__('purchasing.project')">
        <dl class="hc-dl">
            <div><dt>{{ __('purchasing.project_number') }}</dt><dd class="ltr"><x-hc.project-number :code="$cityCode" :number="$project->project_number"/></dd></div>
            <div><dt>{{ __('purchasing.beneficiary') }}</dt><dd>{{ $panel['beneficiary'] !== '' ? $panel['beneficiary'] : __('hc.not_set') }}</dd></div>
            <div><dt>{{ __('purchasing.project_name') }}</dt><dd>{{ $project->name ?: __('hc.not_set') }}</dd></div>
            <div><dt>{{ __('purchasing.profession') }}</dt><dd>{{ $career ? trim(($en && $career->profession_en ? $career->profession_en : $career->profession_ar).' '.($en && $career->career_en ? $career->career_en : $career->career_ar)) ?: '—' : '—' }}</dd></div>
            <div><dt>{{ __('purchasing.loan_amount') }}</dt><dd class="num">{{ $loan ? $money($loan->amount).' '.$loan->currency : '—' }}</dd></div>
            <div><dt>{{ __('purchasing.loan_date') }}</dt><dd class="num">{{ $loan ? \App\Services\Finance\Fields::day($loan->taken_on) : '—' }}</dd></div>
        </dl>
        @if(! $loan)<x-hc.alert type="info" style="margin-block-start:12px">{{ __('purchasing.no_loan') }}</x-hc.alert>@endif
    </x-hc.card>

    <x-hc.card :title="__('purchasing.items')" :flush="true">
        @if($rows === [])
            <x-hc.empty :title="__('purchasing.none')" icon="clipboard">{{ $manage ? __('purchasing.none_hint') : '' }}</x-hc.empty>
        @else
        <div class="hc-table-wrap">
            <table class="hc-table stack hc-fin-rows">
                <thead><tr>
                    <th>{{ __('purchasing.name') }}</th><th>{{ __('purchasing.brand') }}</th><th>{{ __('purchasing.category') }}</th><th class="num">{{ __('purchasing.quantity') }}</th>
                    <th class="num">{{ __('purchasing.price') }}</th><th>{{ __('purchasing.currency') }}</th><th>{{ __('purchasing.invoice_date') }}</th><th>{{ __('purchasing.invoice_number') }}</th>
                    <th>{{ __('purchasing.trader') }}</th>@if($manage)<th></th>@endif
                </tr></thead>
                <tbody>
                @foreach($rows as $i => $row)
                    <tr wire:key="purchase-{{ $row['id'] ?? 'new-'.$i }}">
                        @if($manage)
                            <td data-label="{{ __('purchasing.name') }}"><input class="hc-input" wire:model.live.debounce.400ms="rows.{{ $i }}.item_name" maxlength="255" aria-label="{{ __('purchasing.name') }} {{ $i + 1 }}">@error('rows.'.$i.'.item_name')<p class="hc-error">{{ $message }}</p>@enderror</td>
                            <td data-label="{{ __('purchasing.brand') }}"><input class="hc-input" wire:model="rows.{{ $i }}.brand" maxlength="255" aria-label="{{ __('purchasing.brand') }} {{ $i + 1 }}">@error('rows.'.$i.'.brand')<p class="hc-error">{{ $message }}</p>@enderror</td>
                            <td data-label="{{ __('purchasing.category') }}"><select class="hc-select" wire:model="rows.{{ $i }}.category_id" aria-label="{{ __('purchasing.category') }} {{ $i + 1 }}">
                                <option value="">—</option>
                                @foreach($panel['categories'] as $c)<option value="{{ $c->id }}">{{ $name($c) }}</option>@endforeach
                            </select>@error('rows.'.$i.'.category_id')<p class="hc-error">{{ $message }}</p>@enderror</td>
                            <td data-label="{{ __('purchasing.quantity') }}"><input class="hc-input num ltr" inputmode="numeric" wire:model.live.debounce.400ms="rows.{{ $i }}.quantity" aria-label="{{ __('purchasing.quantity') }} {{ $i + 1 }}">@error('rows.'.$i.'.quantity')<p class="hc-error">{{ $message }}</p>@enderror</td>
                            <td data-label="{{ __('purchasing.price') }}"><input class="hc-input num ltr" inputmode="decimal" wire:model.live.debounce.400ms="rows.{{ $i }}.price" aria-label="{{ __('purchasing.price') }} {{ $i + 1 }}">@error('rows.'.$i.'.price')<p class="hc-error">{{ $message }}</p>@enderror</td>
                            <td data-label="{{ __('purchasing.currency') }}"><select class="hc-select" wire:model.live="rows.{{ $i }}.currency" aria-label="{{ __('purchasing.currency') }} {{ $i + 1 }}">
                                @foreach(\App\Services\Finance\PurchasingService::CURRENCIES as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                            </select>@error('rows.'.$i.'.currency')<p class="hc-error">{{ $message }}</p>@enderror</td>
                            <td data-label="{{ __('purchasing.invoice_date') }}"><input class="hc-input num ltr" type="date" wire:model="rows.{{ $i }}.invoice_date" aria-label="{{ __('purchasing.invoice_date') }} {{ $i + 1 }}">@error('rows.'.$i.'.invoice_date')<p class="hc-error">{{ $message }}</p>@enderror</td>
                            <td data-label="{{ __('purchasing.invoice_number') }}"><input class="hc-input ltr" wire:model="rows.{{ $i }}.invoice_number" maxlength="100" aria-label="{{ __('purchasing.invoice_number') }} {{ $i + 1 }}">@error('rows.'.$i.'.invoice_number')<p class="hc-error">{{ $message }}</p>@enderror</td>
                            <td data-label="{{ __('purchasing.trader') }}"><input class="hc-input" wire:model="rows.{{ $i }}.trader_name" maxlength="255" aria-label="{{ __('purchasing.trader') }} {{ $i + 1 }}">@error('rows.'.$i.'.trader_name')<p class="hc-error">{{ $message }}</p>@enderror</td>
                            <td class="full">
                                @if($row['id'] === null)
                                    <x-hc.button variant="ghost" size="sm" icon="x" wire:click="removeRow({{ $i }})">{{ __('purchasing.remove') }}</x-hc.button>
                                @else
                                    <x-hc.button variant="ghost" size="sm" icon="x" wire:click="deleteItem({{ $i }})" wire:confirm="{{ __('purchasing.delete_confirm') }}">{{ __('purchasing.delete') }}</x-hc.button>
                                @endif
                            </td>
                        @else
                            <td data-label="{{ __('purchasing.name') }}">{{ $row['item_name'] }}</td>
                            <td data-label="{{ __('purchasing.brand') }}">{{ $row['brand'] ?: '—' }}</td>
                            <td data-label="{{ __('purchasing.category') }}">{{ $row['category_id'] !== '' ? $name($panel['categories']->firstWhere('id', (int) $row['category_id'])) : '—' }}</td>
                            <td data-label="{{ __('purchasing.quantity') }}" class="num">{{ $row['quantity'] }}</td>
                            <td data-label="{{ __('purchasing.price') }}" class="num">{{ $money($row['price']) }}</td>
                            <td data-label="{{ __('purchasing.currency') }}">{{ $row['currency'] }}</td>
                            <td data-label="{{ __('purchasing.invoice_date') }}" class="num">{{ $row['invoice_date'] ?: '—' }}</td>
                            <td data-label="{{ __('purchasing.invoice_number') }}" class="ltr">{{ $row['invoice_number'] ?: '—' }}</td>
                            <td data-label="{{ __('purchasing.trader') }}">{{ $row['trader_name'] ?: '—' }}</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
        @if($manage)
            <div style="display:flex;gap:8px;flex-wrap:wrap;padding:12px 16px">
                <x-hc.button variant="secondary" icon="plus" wire:click="addRow">{{ __('purchasing.add') }}</x-hc.button>
                <x-hc.button icon="check" wire:click="save" wire:loading.attr="disabled" wire:target="save">{{ __('purchasing.save') }}</x-hc.button>
            </div>
            <p class="hc-hint" style="margin:0;padding:0 16px 16px">{{ __('purchasing.hint') }}</p>
        @endif
        <div style="padding:0 16px 16px">
            <p class="hc-label" style="margin:0 0 8px">{{ __('purchasing.totals') }}</p>
            @if($totals === [])
                <p class="muted" style="margin:0">{{ __('purchasing.totals_none') }}</p>
            @else
                <div class="hc-fin-totals">
                    @foreach($totals as $currency => $total)<x-hc.stat :label="$currency" :value="$money($total)"/>@endforeach
                </div>
            @endif
            <p class="hc-hint" style="margin:0">{{ __('purchasing.totals_hint') }}</p>
        </div>
    </x-hc.card>
</div>
