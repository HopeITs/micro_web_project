@php
    $en = app()->getLocale() === 'en';
    $label = fn ($m) => $m ? ($en && $m->name_en ? $m->name_en : $m->name_ar) : __('hc.not_set');
    $person = $project->projectApplicants->first()?->person;
    $current = $project->projectStateHistory->firstWhere('is_current', true);
    $loan = $project->loans->first();
    $stateEn = $current?->projectState?->name_en;
    $status = \App\Support\ProjectStatus::class;
    $path = $status::path($stateEn, $project->projectStateHistory->reject(fn ($h) => $h->is_current)->map(fn ($h) => $h->projectState?->name_en)->values()->all());
@endphp
<x-layouts.hc :title="\App\Services\ProjectNumberAllocator::display($project->city->code, $project->project_number)">
    <p style="margin-block-end:12px"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('projects.index', ['locale' => app()->getLocale()]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('hc.back') }}</a></p>
    <div class="hc-page-head">
        <div>
            <p class="hc-eyebrow">{{ $en ? $project->city->name_en : $project->city->name_ar }} · {{ __('hc.stage_'.$project->application_stage) }}</p>
            <h1><x-hc.project-number :code="$project->city->code" :number="$project->project_number"/> · {{ $project->name }}</h1>
            <p>{{ $person ? trim($person->first_name.' '.$person->father_name.' '.$person->last_name) : '' }}</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            @if($current)<x-hc.badge :tone="$status::tone($stateEn)" :icon="$status::icon($stateEn)" style="font-size:.9rem;padding:4px 14px">{{ $label($current->projectState) }}</x-hc.badge>@endif
            @if(app(\App\Services\Applications\ApplicationService::class)->canWrite(auth()->user(), (int) $project->city_id))
                <x-hc.button icon="settings" variant="secondary" :href="route('applications.edit', ['locale' => app()->getLocale(), 'project' => $project->id])">{{ __('applications.edit') }}</x-hc.button>
            @endif
            @if(app(\App\Services\AccountAccess::class)->check(auth()->user(), (int) $project->city_id, 'assessments.view'))
                <x-hc.button icon="clipboard" variant="secondary" :href="route('checklists.show', ['locale' => app()->getLocale(), 'project' => $project->id])">Checklist</x-hc.button>
            @endif
            @php($annex = app(\App\Services\Applications\ProjectAnnexService::class)->kind($project))
            @if($annex)
                <x-hc.button icon="clipboard" variant="secondary" :href="route('applications.annex', ['locale' => app()->getLocale(), 'project' => $project->id])">{{ __('annexes.'.$annex) }}</x-hc.button>
            @endif
        </div>
    </div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif
    @if($current)<x-hc.stage-bar :current="$path['current']" :mode="$path['mode']" :tone="$path['tone']" :note="$label($current->projectState)" style="margin-block-end:20px"/>@endif

    <div class="hc-grid" style="grid-template-columns: repeat(auto-fit, minmax(min(100%, 520px), 1fr)); align-items:start">
        <div class="hc-grid">
            <x-hc.section :title="__('hc.details')" step="1">
                <dl class="hc-dl">
                    <div><dt>{{ __('hc.number') }}</dt><dd><x-hc.project-number :code="$project->city->code" :number="$project->project_number"/></dd></div>
                    <div><dt>{{ __('hc.request_date') }}</dt><dd class="num">{{ $project->request_date?->format('Y-m-d') ?? __('hc.not_set') }}</dd></div>
                    <div><dt>{{ __('hc.requested') }}</dt><dd class="num">{{ $project->requested_amount !== null ? number_format((float) $project->requested_amount) : __('hc.not_set') }}</dd></div>
                    <div><dt>{{ __('hc.career') }}</dt><dd>{{ $label($project->career) }}</dd></div>
                    <div><dt>{{ __('hc.category') }}</dt><dd>{{ $label($project->projectCategory) }}</dd></div>
                    <div><dt>{{ __('hc.type') }}</dt><dd>{{ $label($project->projectType) }}</dd></div>
                    <div><dt>{{ __('hc.donor') }}</dt><dd>{{ $label($project->donor) }}</dd></div>
                    <div><dt>{{ __('hc.address') }}</dt><dd>{{ $project->place_address ?: __('hc.not_set') }}</dd></div>
                    @if($project->legacy_id)<div><dt>{{ __('hc.legacy_number') }}</dt><dd class="num">{{ $project->legacy_id }}</dd></div>@endif
                </dl>
            </x-hc.section>
            @if($person)
            <x-hc.section :title="__('hc.applicant')" step="2">
                <dl class="hc-dl">
                    <div><dt>{{ __('hc.beneficiary') }}</dt><dd>{{ trim($person->first_name.' '.$person->father_name.' '.$person->last_name) }}</dd></div>
                    <div><dt>{{ __('hc.national_number') }}</dt><dd class="num ltr">{{ $person->national_number ?: __('hc.not_set') }}</dd></div>
                    <div><dt>{{ __('hc.mobile') }}</dt><dd class="num ltr">{{ $person->mobile ?: __('hc.not_set') }}</dd></div>
                </dl>
            </x-hc.section>
            @endif
            <x-hc.section :title="__('hc.loan')" step="3">
                @if($loan)
                    @php($paid = $loan->loanInstallments->where('is_paid', true)->sum('amount'))
                    <dl class="hc-dl" style="margin-block-end:16px">
                        <div><dt>{{ __('hc.amount') }}</dt><dd class="num">{{ number_format((float) $loan->amount) }} <span class="muted">{{ $loan->currency }}</span></dd></div>
                        <div><dt>{{ __('hc.paid') }}</dt><dd class="num">{{ number_format((float) $paid) }}</dd></div>
                        <div><dt>{{ __('hc.collected') }}</dt><dd><div class="hc-progress"><span style="width: {{ $loan->amount > 0 ? round($paid / $loan->amount * 100) : 0 }}%"></span></div></dd></div>
                    </dl>
                    <div class="hc-table-wrap"><table class="hc-table">
                        <thead><tr><th>#</th><th>{{ __('hc.due_on') }}</th><th class="num">{{ __('hc.amount') }}</th><th>{{ __('hc.state') }}</th></tr></thead>
                        <tbody>@foreach($loan->loanInstallments as $i => $inst)
                            <tr><td class="num">{{ $i + 1 }}</td><td class="num">{{ $inst->due_on?->format('Y-m-d') }}</td><td class="num">{{ number_format((float) $inst->amount) }}</td>
                                <td>@if($inst->is_paid)<x-hc.badge tone="ok">{{ __('hc.paid') }}</x-hc.badge>@elseif($inst->due_on?->isPast())<x-hc.badge tone="danger">{{ __('hc.unpaid') }}</x-hc.badge>@else<x-hc.badge>{{ __('hc.unpaid') }}</x-hc.badge>@endif</td></tr>
                        @endforeach</tbody>
                    </table></div>
                @else
                    <p class="muted">{{ __('hc.no_loan') }}</p>
                @endif
            </x-hc.section>
        </div>
        <x-hc.card :title="__('hc.history')">
            <ol class="hc-timeline">
                @foreach($project->projectStateHistory as $h)
                    <li @class(['current' => $h->is_current])><strong>{{ $label($h->projectState) }}</strong><br><span class="muted num">{{ $h->changed_on?->format('Y-m-d') }}</span>@if($h->stateReason)<br><span class="muted">{{ $label($h->stateReason) }}</span>@endif</li>
                @endforeach
            </ol>
        </x-hc.card>
    </div>
</x-layouts.hc>
