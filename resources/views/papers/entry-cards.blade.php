{{-- One entry form per paper (deeds, sale contracts): $cards = [key, title, note, action, fields, links]; each field is [value, hint, kind]. --}}
@php
    $E = fn ($key, $with = []) => __('paper_entry.'.$key, $with);
    $inputTypes = ['date' => 'date', 'month' => 'month', 'time' => 'time'];
    $numbers = ['money', 'rate', 'months', 'year'];
@endphp
<x-layouts.hc :title="$title">
    <div class="hc-page-head">
        <div><p class="hc-eyebrow">{{ $number }}</p><h1>{{ $title }}</h1><p>{{ $intro }}</p></div>
        <x-hc.button variant="ghost" :href="route('projects.show', ['locale' => app()->getLocale(), 'project' => $projectId])">{{ __('papers.back') }}</x-hc.button>
    </div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif
    @unless($canSave)<x-hc.alert type="info" style="margin-block-end:16px">{{ $readOnly }}</x-hc.alert>@endunless
    @foreach($cards as $card)
        @php
            $typed = old('_form') === $card['key'];
        @endphp
        <form method="POST" action="{{ $card['action'] }}" style="margin-block-end:16px" id="form-{{ $card['key'] }}">
            @csrf
            <input type="hidden" name="_form" value="{{ $card['key'] }}">
            <x-hc.card :title="$card['title']">
                @if($card['note'])<p style="margin:0 0 12px">{{ $card['note'] }}</p>@endif
                <div class="hc-form-grid">
                    @foreach($card['fields'] as $name => $field)
                        @php
                            $id = $card['key'].'-'.$name;
                            $current = $typed ? (string) old($name, '') : $field['value'];
                            $kind = $field['kind'];
                        @endphp
                        <x-hc.field :label="$E('f.'.$name)" :for="$id" :error="$typed ? $errors->first($name) : ''" :hint="$field['hint'] !== '' ? $E('if_empty', ['value' => $field['hint']]) : null">
                            @if(is_int($kind) && $kind >= 500)
                                <textarea class="hc-input" id="{{ $id }}" name="{{ $name }}" rows="2" @disabled(! $canSave)>{{ $current }}</textarea>
                            @elseif($kind === 'currency')
                                <select class="hc-input" id="{{ $id }}" name="{{ $name }}" @disabled(! $canSave)>
                                    <option value=""></option>
                                    @foreach(\App\Services\Finance\FinanceService::CURRENCIES as $currency)<option value="{{ $currency }}" @selected($current === $currency)>{{ $currency }}</option>@endforeach
                                </select>
                            @else
                                <input class="hc-input {{ is_int($kind) ? '' : 'num ltr' }}" id="{{ $id }}" name="{{ $name }}" type="{{ $inputTypes[$kind] ?? 'text' }}" @if(in_array($kind, $numbers, true)) inputmode="decimal" @endif value="{{ $current }}" @disabled(! $canSave)>
                            @endif
                        </x-hc.field>
                    @endforeach
                </div>
                @if($canSave)
                    <div style="margin-block-start:12px;display:flex;gap:8px;flex-wrap:wrap">
                        <x-hc.button type="submit" icon="check">{{ $E('save') }}</x-hc.button>
                        <button type="submit" name="print" value="1" class="hc-btn hc-btn-secondary">{{ $E('save_print') }}</button>
                    </div>
                @endif
                <p style="margin:12px 0 0;display:flex;gap:16px;flex-wrap:wrap">
                    @foreach($card['links'] as [$label, $url])<a class="row-link" href="{{ $url }}" target="_blank" rel="noopener">{{ $label }}</a>@endforeach
                </p>
            </x-hc.card>
        </form>
    @endforeach
</x-layouts.hc>
