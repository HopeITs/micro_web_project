{{--
    The ask box (X1): a plain question in Arabic or English becomes filter chips, never SQL. Parsed locally on this server;
    nothing is sent anywhere. Shown above the filter bar (x-hc.filter-bar) and only to accounts that may read the dataset.
--}}
<div class="hc-ask" @unless($allowed) hidden @endunless>
    @if($allowed)
        <form class="hc-toolbar" wire:submit="ask" role="search" aria-label="{{ __('ask.title') }}" style="align-items:flex-end">
            <div class="hc-field grow">
                <label class="hc-label" for="ask-{{ $dataset }}">{{ __('ask.title') }}</label>
                <div style="position:relative">
                    <input id="ask-{{ $dataset }}" class="hc-input" type="text" wire:model="question" maxlength="300" autocomplete="off" spellcheck="false"
                           placeholder="{{ __('ask.placeholder') }}" style="padding-inline-start:40px">
                    <x-hc.icon name="search" :size="18" style="position:absolute;inset-inline-start:12px;inset-block-start:12px;color:var(--muted)"/>
                </div>
            </div>
            <x-hc.button type="submit" variant="secondary" icon="check" wire:loading.attr="disabled" wire:target="ask,useExample">{{ __('ask.submit') }}</x-hc.button>
        </form>

        @if(! $asked && $examples !== [])
            <div class="hc-chips" role="group" aria-label="{{ __('ask.examples') }}" style="margin-block-start:8px">
                <span class="hc-label">{{ __('ask.examples') }}</span>
                @foreach($examples as $i => $example)
                    <button type="button" class="hc-chip" wire:key="ask-example-{{ $i }}" wire:click="useExample({{ $i }})">{{ $example }}</button>
                @endforeach
            </div>
        @endif

        @if($asked)
            <div class="hc-filter-row" role="status" aria-live="polite" style="margin-block-start:8px">
                @forelse($chips as $chip)
                    <span class="hc-filter-chip" wire:key="ask-chip-{{ $chip['field'] }}">
                        <span class="hc-filter-chip-main"><span class="hc-filter-chip-field">{{ $chip['label'] }}:</span> <span>{{ $chip['summary'] }}</span></span>
                        <button type="button" class="hc-filter-chip-x" wire:click="removeChip({{ \Illuminate\Support\Js::from($chip['field']) }})"
                                aria-label="{{ __('hc.filter_remove', ['field' => $chip['label']]) }}" title="{{ __('hc.filter_remove', ['field' => $chip['label']]) }}"><x-hc.icon name="x" :size="14"/></button>
                    </span>
                @empty
                    @if($search === '')
                        <span class="hc-hint">{{ __('ask.nothing_understood') }}</span>
                    @endif
                @endforelse
                @if($search !== '')
                    <span class="hc-filter-chip"><span class="hc-filter-chip-main"><span class="hc-filter-chip-field">{{ __('ask.numbers') }}:</span> <bdi class="ltr">{{ $search }}</bdi></span></span>
                @endif
                @if($report)
                    <span class="hc-filter-chip"><span class="hc-filter-chip-main"><span class="hc-filter-chip-field">{{ __('ask.report') }}:</span> <span>{{ $report['rows'] }}@if($report['columns']) × {{ $report['columns'] }}@endif · {{ $report['measure'] }}</span></span></span>
                @endif
            </div>
            @if($unknown !== [])
                <p class="hc-hint" style="margin-block-start:4px">{{ __('ask.not_understood') }} <bdi>{{ implode('، ', $unknown) }}</bdi></p>
            @endif
            <p class="hc-hint">{{ __('ask.local_note') }}</p>
        @endif
    @endif
</div>
