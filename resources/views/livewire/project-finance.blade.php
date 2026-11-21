@php
    $en = app()->getLocale() === 'en';
    $name = fn ($row) => $row ? ($en && ($row->name_en ?? null) ? $row->name_en : $row->name_ar) : __('hc.not_set');
    $status = \App\Support\ProjectStatus::class;
    $current = $panel['current'];
    $loan = $panel['loan'];
    $model = $panel['model'];
    $manage = $panel['canManage'];
    $money = fn ($v) => $v === null || $v === '' ? '—' : number_format((float) $v, str_contains((string) $v, '.') ? 2 : 0);
    $today = now()->toDateString();
@endphp
<div class="hc-grid hc-finance">
    @if($notice !== '')<x-hc.alert type="success">{{ $notice }}</x-hc.alert>@endif
    @error('version')<x-hc.alert type="danger">{{ $message }}</x-hc.alert>@enderror
    @if(! $manage)<x-hc.alert type="info">{{ __('finance.read_only') }}</x-hc.alert>@endif
    @if($panel['visitNoticesMissing'])<x-hc.alert type="warning">{{ __('finance.visit_notices_missing') }}</x-hc.alert>@endif

    <div class="hc-grid" style="grid-template-columns: repeat(auto-fit, minmax(min(100%, 420px), 1fr)); align-items:start">
        {{-- Donor --}}
        <x-hc.card :title="__('finance.donor.title')">
            <p style="margin:0 0 12px">{{ __('finance.state') }}:
                @if($current)<x-hc.badge :tone="$status::tone($current->state_en)" :icon="$status::icon($current->state_en)">{{ $en && $current->state_en ? $current->state_en : $current->state_ar }}</x-hc.badge>
                @else<span class="muted">{{ __('hc.not_set') }}</span>@endif
            </p>
            @if($manage && $panel['funded'])
                <form wire:submit="saveDonor" class="hc-grid" style="gap:12px">
                    <x-hc.field :label="__('finance.donor.donor')" for="fin-donor" :error="$errors->first('donorForm.donor_id')" required>
                        <select class="hc-select" id="fin-donor" wire:model="donorForm.donor_id">
                            <option value="">—</option>
                            @foreach($panel['donors'] as $d)<option value="{{ $d->id }}">{{ $name($d) }}</option>@endforeach
                        </select>
                    </x-hc.field>
                    <x-hc.field :label="__('finance.donor.group')" for="fin-group" :error="$errors->first('donorForm.group_id')" :hint="__('finance.donor.group_hint')">
                        <select class="hc-select" id="fin-group" wire:model="donorForm.group_id">
                            <option value="">{{ __('finance.donor.no_group') }}</option>
                            @foreach($panel['groups'] as $g)<option value="{{ $g->id }}">{{ $name($g) }} · {{ $name($panel['donors']->firstWhere('id', $g->donor_id)) }}</option>@endforeach
                        </select>
                    </x-hc.field>
                    <div><x-hc.button type="submit" variant="secondary" icon="check" wire:loading.attr="disabled" wire:target="saveDonor">{{ __('finance.donor.save') }}</x-hc.button></div>
                </form>
            @else
                <dl class="hc-dl">
                    <div><dt>{{ __('finance.donor.donor') }}</dt><dd>{{ $name($panel['donor']) }}</dd></div>
                    <div><dt>{{ __('finance.donor.group') }}</dt><dd>{{ $panel['group'] ? $name($panel['group']) : __('finance.donor.no_group') }}</dd></div>
                </dl>
            @endif
            <p class="hc-hint" style="margin:12px 0 0">{{ __('finance.donor.rules') }}</p>
        </x-hc.card>

        {{-- Loan --}}
        <x-hc.card :title="__('finance.loan.title')">
            @error('loan')<x-hc.alert type="danger" style="margin-block-end:12px">{{ $message }}</x-hc.alert>@enderror
            @if($model)
                <div class="hc-fin-totals">
                    <x-hc.stat :label="__('finance.loan.amount')" :value="$money($model['values']['amount']).' '.$loan->currency" :note="$model['opposite'] !== null ? __('finance.loan.opposite', ['currency' => $loan->currency === 'USD' ? 'SYP' : 'USD']).': '.$money($model['opposite']) : null"/>
                    <x-hc.stat :label="__('finance.loan.paid')" :value="$money($model['summary']['paid_amount'])" :note="__('finance.loan.paid_count').': '.$model['summary']['paid_count']"/>
                    <x-hc.stat :label="__('finance.loan.remaining')" :value="$money($model['summary']['remaining_clamped'])" :note="$model['summary']['near_end'] ? __('finance.loan.near_end') : ($model['summary']['remaining_count'] !== null ? __('finance.loan.remaining_count').': '.$model['summary']['remaining_count'] : null)"/>
                </div>
            @elseif(! $manage)
                <p class="muted">{{ __('finance.loan.none') }}</p>
            @endif
            @if($manage)
                @if(! $loan)<p class="muted" style="margin-block-end:12px">{{ __('finance.loan.none_yet') }}</p>@endif
                @if($panel['ineligible'] && ! $loan)
                    <x-hc.alert type="warning">{{ __('finance.errors.ineligible_'.$panel['ineligible']) }}</x-hc.alert>
                @else
                <form wire:submit="saveLoan" class="hc-fin-form" style="margin-block-start:12px">
                    <x-hc.field :label="__('finance.loan.currency')" for="fin-currency" :error="$errors->first('loanForm.currency')" required>
                        <select class="hc-select" id="fin-currency" wire:model="loanForm.currency">
                            @foreach(\App\Services\Finance\FinanceService::CURRENCIES as $c)<option value="{{ $c }}">{{ __('finance.currencies.'.$c) }}</option>@endforeach
                        </select>
                    </x-hc.field>
                    <x-hc.field :label="__('finance.loan.taken_on')" for="fin-date" :error="$errors->first('loanForm.taken_on')" required>
                        <input class="hc-input num ltr" type="date" id="fin-date" wire:model="loanForm.taken_on">
                    </x-hc.field>
                    <x-hc.field :label="__('finance.loan.amount')" for="fin-amount" :error="$errors->first('loanForm.amount')" required>
                        <input class="hc-input num ltr" inputmode="decimal" id="fin-amount" wire:model="loanForm.amount" autocomplete="off">
                    </x-hc.field>
                    <x-hc.field :label="__('finance.loan.exchange_rate')" for="fin-rate" :error="$errors->first('loanForm.exchange_rate')">
                        <input class="hc-input num ltr" inputmode="decimal" id="fin-rate" wire:model="loanForm.exchange_rate" autocomplete="off">
                    </x-hc.field>
                    <x-hc.field :label="__('finance.loan.returned_amount')" for="fin-returned" :error="$errors->first('loanForm.returned_amount')">
                        <input class="hc-input num ltr" inputmode="decimal" id="fin-returned" wire:model="loanForm.returned_amount" autocomplete="off">
                    </x-hc.field>
                    <x-hc.field :label="__('finance.loan.installment_amount')" for="fin-payment" :error="$errors->first('loanForm.installment_amount')">
                        <input class="hc-input num ltr" inputmode="decimal" id="fin-payment" wire:model="loanForm.installment_amount" autocomplete="off">
                    </x-hc.field>
                    <x-hc.field :label="__('finance.loan.receipt_number')" for="fin-receipt" :error="$errors->first('loanForm.receipt_number')">
                        <input class="hc-input ltr" id="fin-receipt" wire:model="loanForm.receipt_number" maxlength="50" autocomplete="off">
                    </x-hc.field>
                    <x-hc.field class="wide" :label="__('finance.loan.note')" for="fin-note" :error="$errors->first('loanForm.note')">
                        <textarea class="hc-textarea" id="fin-note" wire:model="loanForm.note" rows="2"></textarea>
                    </x-hc.field>
                    @if($loan)
                        <dl class="hc-dl wide">
                            <div><dt>{{ __('finance.loan.count') }}</dt><dd class="num">{{ \App\Services\Finance\Money::canonical($loan->installments_count) ?? '—' }}</dd></div>
                            <div><dt>{{ __('finance.loan.rate') }}</dt><dd class="num">{{ \App\Services\Finance\Money::canonical($loan->rate) ?? '—' }}</dd></div>
                        </dl>
                    @endif
                    <p class="hc-hint wide" style="margin:0">{{ __('finance.loan.derived') }}</p>
                    <div class="wide" style="display:flex;gap:8px;flex-wrap:wrap">
                        <x-hc.button type="submit" icon="check" wire:loading.attr="disabled" wire:target="saveLoan">{{ __('finance.loan.save') }}</x-hc.button>
                        @if($loan && $panel['canDeleteLoan'])
                            <x-hc.button variant="ghost" icon="x" wire:click="deleteLoan" wire:confirm="{{ __('finance.loan.delete_confirm') }}" wire:loading.attr="disabled" wire:target="deleteLoan"
                                :disabled="$panel['installments']->contains(fn ($r) => $r->is_paid)">{{ __('finance.loan.delete') }}</x-hc.button>
                        @endif
                    </div>
                    @if($loan && $panel['canDeleteLoan'])<p class="hc-hint wide" style="margin:0">{{ __('finance.loan.delete_hint') }}</p>@endif
                </form>
                @endif
            @elseif($loan)
                <dl class="hc-dl" style="margin-block-start:12px">
                    <div><dt>{{ __('finance.loan.taken_on') }}</dt><dd class="num">{{ \App\Services\Finance\Fields::day($loan->taken_on) ?: '—' }}</dd></div>
                    <div><dt>{{ __('finance.loan.returned_amount') }}</dt><dd class="num">{{ $money($model['values']['returned']) }}</dd></div>
                    <div><dt>{{ __('finance.loan.installment_amount') }}</dt><dd class="num">{{ $money($model['values']['payment']) }}</dd></div>
                    <div><dt>{{ __('finance.loan.count') }}</dt><dd class="num">{{ \App\Services\Finance\Money::canonical($loan->installments_count) ?? '—' }}</dd></div>
                    <div><dt>{{ __('finance.loan.receipt_number') }}</dt><dd class="ltr">{{ $loan->receipt_number ?: '—' }}</dd></div>
                    @if($loan->note)<div><dt>{{ __('finance.loan.note') }}</dt><dd>{{ $loan->note }}</dd></div>@endif
                </dl>
            @endif
        </x-hc.card>
    </div>

    {{-- Instalments --}}
    @if($loan)
    <x-hc.card :title="__('finance.installments.title')" :flush="true" id="installments">
        @if($manage)
            <x-hc.section :title="__('finance.installments.generate')" :open="$rows === [] || $errors->has('generate.*')" style="margin:12px 16px">
                <form wire:submit="generateSchedule" class="hc-fin-form">
                    <x-hc.field :label="__('finance.installments.generate_count')" for="gen-count" :error="$errors->first('generate.count')">
                        <input class="hc-input num ltr" inputmode="numeric" id="gen-count" wire:model="generate.count" autocomplete="off">
                    </x-hc.field>
                    <x-hc.field :label="__('finance.installments.generate_first')" for="gen-first" :error="$errors->first('generate.first_due')">
                        <input class="hc-input num ltr" type="date" id="gen-first" wire:model="generate.first_due">
                    </x-hc.field>
                    <p class="hc-hint wide" style="margin:0">{{ __('finance.installments.generate_hint') }}</p>
                    <div class="wide"><x-hc.button type="submit" variant="secondary" icon="calendar" wire:loading.attr="disabled" wire:target="generateSchedule">{{ __('finance.installments.generate_button') }}</x-hc.button></div>
                </form>
            </x-hc.section>
        @endif
        @error('rows')<x-hc.alert type="danger" style="margin:12px 16px">{{ $message }}</x-hc.alert>@enderror
        @if($rows === [])
            <x-hc.empty :title="__('finance.installments.none')" icon="calendar">{{ $manage ? __('finance.installments.generate_hint') : '' }}</x-hc.empty>
        @else
        <div class="hc-table-wrap">
            <table class="hc-table stack hc-fin-rows">
                <thead><tr><th class="num">{{ __('finance.installments.number') }}</th><th>{{ __('finance.installments.due') }}</th><th class="num">{{ __('finance.installments.amount') }}</th><th>{{ __('finance.installments.status') }}</th><th>{{ __('finance.installments.received') }}</th><th>{{ __('finance.installments.note') }}</th>@if($manage)<th></th>@endif</tr></thead>
                <tbody>
                @foreach($rows as $i => $row)
                    @php($late = ! $row['is_paid'] && $row['due_on'] !== '' && $row['due_on'] < $today)
                    <tr wire:key="row-{{ $row['id'] ?? 'new-'.$i }}">
                        <td data-label="{{ __('finance.installments.number') }}" class="num">{{ $i + 1 }}@if($row['id'] === null) <span class="muted">· {{ __('finance.installments.unsaved') }}</span>@endif</td>
                        @if($manage)
                            <td data-label="{{ __('finance.installments.due') }}"><input class="hc-input num ltr" type="date" wire:model="rows.{{ $i }}.due_on" aria-label="{{ __('finance.installments.due') }} {{ $i + 1 }}">@error('rows.'.$i.'.due_on')<p class="hc-error">{{ $message }}</p>@enderror</td>
                            <td data-label="{{ __('finance.installments.amount') }}"><input class="hc-input num ltr" inputmode="decimal" wire:model="rows.{{ $i }}.amount" aria-label="{{ __('finance.installments.amount') }} {{ $i + 1 }}">@error('rows.'.$i.'.amount')<p class="hc-error">{{ $message }}</p>@enderror</td>
                            <td data-label="{{ __('finance.installments.status') }}"><label class="hc-check"><input type="checkbox" wire:model="rows.{{ $i }}.is_paid"> {{ __('finance.installments.paid') }}</label>@if($late) <x-hc.badge tone="danger">{{ __('finance.installments.overdue') }}</x-hc.badge>@endif</td>
                            <td data-label="{{ __('finance.installments.received') }}"><input class="hc-input num ltr" type="date" wire:model="rows.{{ $i }}.received_on" aria-label="{{ __('finance.installments.received') }} {{ $i + 1 }}">@error('rows.'.$i.'.received_on')<p class="hc-error">{{ $message }}</p>@enderror</td>
                            <td data-label="{{ __('finance.installments.note') }}"><input class="hc-input" wire:model="rows.{{ $i }}.note" aria-label="{{ __('finance.installments.note') }} {{ $i + 1 }}"></td>
                            <td class="full">
                                @if($row['id'] === null)
                                    <x-hc.button variant="ghost" size="sm" icon="x" wire:click="removeRow({{ $i }})">{{ __('finance.installments.remove') }}</x-hc.button>
                                @elseif(! $row['is_paid'] && ! ($panel['installments']->firstWhere('id', $row['id'])?->is_paid))
                                    <x-hc.button variant="ghost" size="sm" icon="x" wire:click="deleteInstallment({{ $row['id'] }})" wire:confirm="{{ __('finance.installments.delete_confirm') }}">{{ __('finance.installments.delete') }}</x-hc.button>
                                @endif
                            </td>
                        @else
                            <td data-label="{{ __('finance.installments.due') }}" class="num">{{ $row['due_on'] ?: '—' }}</td>
                            <td data-label="{{ __('finance.installments.amount') }}" class="num">{{ $money($row['amount']) }}</td>
                            <td data-label="{{ __('finance.installments.status') }}">@if($row['is_paid'])<x-hc.badge tone="ok">{{ __('finance.installments.paid') }}</x-hc.badge>@elseif($late)<x-hc.badge tone="danger">{{ __('finance.installments.overdue') }}</x-hc.badge>@else<x-hc.badge>{{ __('finance.installments.not_paid') }}</x-hc.badge>@endif</td>
                            <td data-label="{{ __('finance.installments.received') }}" class="num">{{ $row['received_on'] ?: '—' }}</td>
                            <td data-label="{{ __('finance.installments.note') }}">{{ $row['note'] ?: '—' }}</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
        @if($manage)
            <div style="display:flex;gap:8px;flex-wrap:wrap;padding:12px 16px">
                <x-hc.button variant="secondary" icon="plus" wire:click="addRow">{{ __('finance.installments.add') }}</x-hc.button>
                <x-hc.button icon="check" wire:click="savePayments" wire:loading.attr="disabled" wire:target="savePayments">{{ __('finance.installments.save') }}</x-hc.button>
            </div>
            <p class="hc-hint" style="margin:0;padding:0 16px 16px">{{ __('finance.installments.hint') }}</p>
        @endif
    </x-hc.card>
    @endif
</div>
