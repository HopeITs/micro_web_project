@php
    $locale = app()->getLocale();
    $D = fn ($key) => __('purchase_data.'.$key);
    $suggestHint = ['request' => 'suggest_request', 'quote' => 'suggest_quote', 'return' => 'suggest_return'];
    $widths = ['material' => 34, 'item_name' => 30, 'item_kind' => 18, 'quantity' => 10, 'material_state' => 14, 'unit_price' => 16, 'brand' => 16, 'serial_number' => 16, 'technical_state' => 16, 'notes' => 22];
@endphp
<x-layouts.hc :title="$D('title')">
    <div class="hc-page-head">
        <div><p class="hc-eyebrow">{{ $number }}</p><h1>{{ $D('title') }}</h1><p>{{ $D('intro') }}</p></div>
        <x-hc.button variant="ghost" :href="route('projects.show', ['locale' => $locale, 'project' => $projectId])">{{ __('papers.back') }}</x-hc.button>
    </div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif
    @unless($canManage)<x-hc.alert type="info" style="margin-block-end:16px">{{ $D('read_only') }}</x-hc.alert>@endunless
    @foreach($specs as $form => $spec)
        @php
            $state = $forms[$form];
            // After a refused save only the form that was sent keeps what was typed.
            $typed = old('_form') === $form;
            $value = fn (string $key, string $stored) => $typed ? (string) old($key, '') : $stored;
            $ticks = $form === 'return' && collect($state['rows'])->contains(fn ($r) => ! empty($r['suggested']));
        @endphp
        <form method="POST" action="{{ route('applications.purchase-data.save', ['locale' => $locale, 'project' => $projectId, 'form' => $form]) }}" style="margin-block-end:16px" id="form-{{ $form }}">
            @csrf
            <input type="hidden" name="_form" value="{{ $form }}">
            <x-hc.card :title="$D($form)">
                @if($typed && $errors->any())
                    <x-hc.alert type="danger" style="margin-block-end:12px">
                        @foreach($errors->getMessages() as $key => $messages)
                            <span style="display:block">@if(preg_match('/^rows\.(\d+)\.(.+)$/', $key, $m)){{ __('purchase_data.row', ['n' => $m[1] + 1]) }} — {{ $D('f.'.$m[2]) }}: @elseif(isset($spec['head'][$key])){{ $D('f.'.$key) }}: @endif{{ $messages[0] }}</span>
                        @endforeach
                    </x-hc.alert>
                @endif
                @if($spec['head'] !== [])
                    <div class="hc-form-grid">
                        @foreach($spec['head'] as $f => $kind)
                            <x-hc.field :label="$D('f.'.$f)" :for="$form.'-'.$f">
                                <input class="hc-input {{ $kind === 'date' ? 'num ltr' : '' }}" id="{{ $form }}-{{ $f }}" name="{{ $f }}" @if($kind === 'date') type="date" @endif value="{{ $value($f, $state['head'][$f]) }}" @disabled(! $canManage)>
                            </x-hc.field>
                        @endforeach
                    </div>
                @endif
                @if($spec['items'] === null)
                    <p style="margin:12px 0 0">{{ $D('receipt_hint') }}</p>
                @else
                    @unless($state['saved'])<p style="margin:12px 0">{{ $D($suggestHint[$form]) }}</p>@endunless
                    <div class="hc-table-wrap" style="margin-block-start:12px">
                        <table class="hc-table stack">
                            <thead><tr>
                                @if($ticks)<th>{{ $D('returned') }}</th>@endif
                                <th>#</th>
                                @foreach($spec['columns'] as $c => $kind)<th style="width:{{ $widths[$c] }}%">{{ $D('f.'.$c) }}</th>@endforeach
                            </tr></thead>
                            <tbody>
                            @foreach($state['rows'] as $i => $row)
                                <tr>
                                    @if($ticks)
                                        <td class="full" data-label="{{ $D('returned') }}">
                                            @if(! empty($row['suggested']))
                                                <input type="hidden" name="rows[{{ $i }}][suggested]" value="1">
                                                <input type="checkbox" name="rows[{{ $i }}][include]" value="1" aria-label="{{ $D('returned') }} {{ $i + 1 }}" @checked($typed && old("rows.$i.include")) @disabled(! $canManage)>
                                            @endif
                                        </td>
                                    @endif
                                    <td class="num full">{{ $i + 1 }}</td>
                                    @foreach($spec['columns'] as $c => $kind)
                                        <td @class(['full' => $c === $spec['required']]) data-label="{{ $D('f.'.$c) }}">
                                            @php($current = $value("rows.$i.$c", $row[$c] ?? ''))
                                            @if($kind === 'state')
                                                <select class="hc-input" name="rows[{{ $i }}][{{ $c }}]" aria-label="{{ $D('f.'.$c) }} {{ $i + 1 }}" @disabled(! $canManage)>
                                                    <option value=""></option>
                                                    @foreach(\App\Services\Papers\PurchasePaper::STATES as $s)<option value="{{ $s }}" @selected($current === $s)>{{ $D('state_'.$s) }}</option>@endforeach
                                                </select>
                                            @else
                                                <input class="hc-input {{ in_array($kind, ['int', 'money'], true) ? 'num ltr' : '' }}" name="rows[{{ $i }}][{{ $c }}]" value="{{ $current }}" aria-label="{{ $D('f.'.$c) }} {{ $i + 1 }}" @if(in_array($kind, ['int', 'money'], true)) inputmode="decimal" @endif @disabled(! $canManage)>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @if($canManage)
                    <div style="margin-block-start:12px;display:flex;gap:8px;flex-wrap:wrap">
                        <x-hc.button type="submit" icon="check">{{ $D('save') }}</x-hc.button>
                        <button type="submit" name="print" value="1" class="hc-btn hc-btn-secondary">{{ $D('save_print') }}</button>
                    </div>
                @endif
                <p style="margin:12px 0 0"><a class="row-link" href="{{ route('applications.purchase-paper', ['locale' => $locale, 'project' => $projectId, 'paper' => $spec['paper']]) }}" target="_blank" rel="noopener">{{ $D('open_paper') }}</a></p>
            </x-hc.card>
        </form>
    @endforeach
</x-layouts.hc>
