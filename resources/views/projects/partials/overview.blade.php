@php
    $en = app()->getLocale() === 'en';
    $label = fn ($m) => $m ? ($en && $m->name_en ? $m->name_en : $m->name_ar) : __('hc.not_set');
    $loan = $page['loan'];
@endphp
<div class="hc-pj-columns">
    <x-hc.card :title="__('hc.details')">
        <dl class="hc-dl">
            <div><dt>{{ __('hc.request_date') }}</dt><dd class="num">{{ $project->request_date?->format('Y-m-d') ?? __('hc.not_set') }}</dd></div>
            <div><dt>{{ __('hc.requested') }}</dt><dd class="num">{{ $project->requested_amount !== null ? number_format((float) $project->requested_amount) : __('hc.not_set') }}</dd></div>
            <div><dt>{{ __('hc.career') }}</dt><dd>{{ $label($project->career) }}</dd></div>
            <div><dt>{{ __('hc.category') }}</dt><dd>{{ $label($project->projectCategory) }}</dd></div>
            <div><dt>{{ __('hc.type') }}</dt><dd>{{ $label($project->projectType) }}</dd></div>
            <div><dt>{{ __('hc.donor') }}</dt><dd>{{ $label($project->donor) }}</dd></div>
            <div><dt>{{ __('hc.address') }}</dt><dd dir="auto">{{ $project->place_address ?: __('hc.not_set') }}</dd></div>
            @if($project->legacy_id)<div><dt>{{ __('hc.legacy_number') }}</dt><dd class="num">{{ $project->legacy_id }}</dd></div>@endif
        </dl>
    </x-hc.card>

    {{-- With the finance module the loan lives in its tile and page; without it, this is its only place. --}}
    @unless($page['finance'])
    <x-hc.card :title="__('hc.loan')" id="installments">
        @if($loan)
            <dl class="hc-dl">
                <div><dt>{{ __('hc.amount') }}</dt><dd class="num">{{ number_format($loan['amount']) }} <span class="muted">{{ $loan['currency'] }}</span></dd></div>
                <div><dt>{{ __('hc.paid') }}</dt><dd class="num">{{ number_format($loan['paid']) }}</dd></div>
                <div><dt>{{ __('hc.collected') }} <span class="num">{{ $loan['percent'] }}%</span></dt><dd><div class="hc-progress"><span style="width: {{ $loan['percent'] }}%"></span></div></dd></div>
                @if($loan['nextDue'])<div><dt>{{ __('project_page.next_installment') }}</dt><dd class="num">{{ $loan['nextDue'] }}</dd></div>@endif
            </dl>
            @if($loan['rows'] !== [])
            <details class="hc-pj-more">
                <summary>{{ __('project_page.show_installments', ['count' => count($loan['rows'])]) }}<x-hc.icon name="chevron" :size="16"/></summary>
                <div class="hc-table-wrap"><table class="hc-table" data-smart="off">
                    <thead><tr><th>#</th><th>{{ __('hc.due_on') }}</th><th class="num">{{ __('hc.amount') }}</th><th>{{ __('hc.state') }}</th></tr></thead>
                    <tbody>@foreach($loan['rows'] as $i => $row)
                        <tr><td class="num">{{ $i + 1 }}</td><td class="num">{{ $row['due'] ?? __('hc.not_set') }}</td><td class="num">{{ number_format($row['amount']) }}</td>
                            <td>@if($row['paid'])<x-hc.badge tone="ok">{{ __('hc.paid') }}</x-hc.badge>@elseif($row['late'])<x-hc.badge tone="danger">{{ __('hc.unpaid') }}</x-hc.badge>@else<x-hc.badge>{{ __('hc.unpaid') }}</x-hc.badge>@endif</td></tr>
                    @endforeach</tbody>
                </table></div>
            </details>
            @endif
        @else
            <p class="muted">{{ __('hc.no_loan') }}</p>
        @endif
    </x-hc.card>
    @endunless

    @if($page['scores'] && $page['scores']['filled'])
    <x-hc.card :title="__('applications.sections.scores')" :subtitle="__('applications.scores.total', ['total' => $page['scores']['total']])" :flush="true" class="hc-pj-wide">
        <div class="hc-table-wrap"><table class="hc-table stack" data-smart="off">
            <thead><tr><th>{{ __('applications.scores.criterion') }}</th><th>{{ __('applications.scores.answer') }}</th><th>{{ __('applications.scores.notes') }}</th></tr></thead>
            <tbody>@foreach($page['scores']['rows'] as $row)
                <tr><td data-label="{{ __('applications.scores.criterion') }}">{{ $row['label'] }}</td><td data-label="{{ __('applications.scores.answer') }}">{{ $row['answer'] }}</td><td data-label="{{ __('applications.scores.notes') }}" dir="auto">{{ $row['notes'] }}</td></tr>
            @endforeach</tbody>
        </table></div>
    </x-hc.card>
    @endif
</div>
