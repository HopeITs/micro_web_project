@php
    $locale = app()->getLocale();
    $D = fn ($key) => __('papers.data.'.$key);
    $number = \App\Services\ProjectNumberAllocator::display((string) \Illuminate\Support\Facades\DB::table('cities')->where('id', $project->city_id)->value('code'), (int) $project->project_number);
    $groups = [
        'disbursement' => ['title' => $D('disbursement'), 'paper' => 'disbursement-request', 'fields' => ['request_number' => 'text', 'requested_on' => 'date', 'hold_amount' => 'number', 'extra_amount' => 'number', 'extra_reason' => 'text', 'notes' => 'area']],
        'contract' => ['title' => $D('contract'), 'paper' => 'partnership-contract', 'fields' => ['purpose' => 'text', 'financed_equipment' => 'area', 'work_text' => 'text', 'duration_months' => 'number', 'starts_on' => 'date', 'ends_on' => 'date', 'profit_period' => 'text', 'signed_on' => 'date', 'notice_address' => 'text']],
    ];
@endphp
<x-layouts.hc :title="$D('title')">
    <div class="hc-page-head">
        <div><p class="hc-eyebrow">{{ $number }}</p><h1>{{ $D('title') }}</h1><p>{{ $D('intro') }}</p></div>
        <x-hc.button variant="ghost" :href="route('projects.show', ['locale' => $locale, 'project' => $projectId])">{{ __('papers.back') }}</x-hc.button>
    </div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif
    @unless($canManage)<x-hc.alert type="info" style="margin-block-end:16px">{{ $D('read_only') }}</x-hc.alert>@endunless
    @foreach($groups as $form => $g)
        <form method="POST" action="{{ route('applications.paper-data.save', ['locale' => $locale, 'project' => $projectId, 'form' => $form]) }}" style="margin-block-end:16px">
            @csrf
            <x-hc.card :title="$g['title']">
                <div class="hc-form-grid">
                    @foreach($g['fields'] as $f => $kind)
                        <x-hc.field :label="$D('f.'.$f)" :for="$form.'-'.$f" :error="$errors->first($f)">
                            @if($kind === 'area')
                                <textarea class="hc-input" id="{{ $form }}-{{ $f }}" name="{{ $f }}" rows="2" @disabled(! $canManage)>{{ old($f, $rows[$form][$f]) }}</textarea>
                            @else
                                <input class="hc-input {{ $kind !== 'text' ? 'num ltr' : '' }}" id="{{ $form }}-{{ $f }}" name="{{ $f }}" @if($kind === 'date') type="date" @elseif($kind === 'number') inputmode="decimal" @endif value="{{ old($f, $rows[$form][$f]) }}" @disabled(! $canManage)>
                            @endif
                        </x-hc.field>
                    @endforeach
                </div>
                @if($canManage)
                    <div style="margin-block-start:12px;display:flex;gap:8px;flex-wrap:wrap">
                        <x-hc.button type="submit" icon="check">{{ $D('save') }}</x-hc.button>
                        <button type="submit" name="print" value="1" class="hc-btn hc-btn-secondary">{{ $D('save_print') }}</button>
                    </div>
                @endif
                <p style="margin:12px 0 0"><a class="row-link" href="{{ route('applications.finance-paper', ['locale' => $locale, 'project' => $projectId, 'paper' => $g['paper']]) }}" target="_blank" rel="noopener">{{ $D('open_paper') }}</a></p>
            </x-hc.card>
        </form>
    @endforeach
</x-layouts.hc>
