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
                <x-hc.button icon="file" variant="secondary" :href="route('applications.print', ['locale' => app()->getLocale(), 'project' => $project->id])" target="_blank" rel="noopener">{{ __('papers.print_application') }}</x-hc.button>
                <x-hc.button icon="file" variant="secondary" :href="route('applications.file', ['locale' => app()->getLocale(), 'project' => $project->id])" target="_blank" rel="noopener">{{ __('papers.print_file') }}</x-hc.button>
                @foreach(['audio-consent' => 'print_audio', 'special-case' => 'print_special', 'experience-test' => 'print_test'] as $paperKey => $paperLabel)
                    <x-hc.button icon="file" variant="ghost" :href="route('applications.paper', ['locale' => app()->getLocale(), 'project' => $project->id, 'paper' => $paperKey])" target="_blank" rel="noopener">{{ __('paper_project.'.$paperLabel) }}</x-hc.button>
                @endforeach
            @endif
            @php($finance = app(\App\Services\AccountAccess::class)->check(auth()->user(), (int) $project->city_id, 'finance.view') && app(\App\Services\AccountAccess::class)->check(auth()->user(), (int) $project->city_id, 'applications.view'))
            @if($finance)
                <x-hc.button icon="wallet" variant="secondary" :href="route('finance.show', ['locale' => app()->getLocale(), 'project' => $project->id])">{{ __('finance.open') }}</x-hc.button>
                @foreach(['promissory-note' => 'print_note', 'clearance' => 'print_clearance', 'disbursement-request' => 'print_disbursement', 'partnership-contract' => 'print_partnership'] as $financeKey => $financeLabel)
                    <x-hc.button icon="file" variant="ghost" :href="route('applications.finance-paper', ['locale' => app()->getLocale(), 'project' => $project->id, 'paper' => $financeKey])" target="_blank" rel="noopener">{{ __('paper_finance.'.$financeLabel) }}</x-hc.button>
                @endforeach
            @endif
            {{-- M4.12: the project's visits (visits.view or me_visits.view, with applications.view). --}}
            @if(app(\App\Services\AccountAccess::class)->check(auth()->user(), (int) $project->city_id, 'applications.view') && (app(\App\Services\AccountAccess::class)->check(auth()->user(), (int) $project->city_id, 'visits.view') || app(\App\Services\AccountAccess::class)->check(auth()->user(), (int) $project->city_id, 'me_visits.view')))
                <x-hc.button icon="map" variant="secondary" :href="route('visits.hub', ['locale' => app()->getLocale(), 'project' => $project->id])">{{ __('visits.open') }}</x-hc.button>
            @endif
            @php($viewsApplication = app(\App\Services\AccountAccess::class)->check(auth()->user(), (int) $project->city_id, 'applications.view'))
            {{-- M4.13: the project's special cases (applications.view + special_cases.view). --}}
            @if($viewsApplication && app(\App\Services\AccountAccess::class)->check(auth()->user(), (int) $project->city_id, 'special_cases.view'))
                <x-hc.button icon="clipboard" variant="secondary" :href="route('special-cases.project', ['locale' => app()->getLocale(), 'project' => $project->id])">{{ __('special_cases.open') }}</x-hc.button>
            @endif
            @if($viewsApplication && app(\App\Services\AccountAccess::class)->check(auth()->user(), (int) $project->city_id, 'executive_files.view'))
                <x-hc.button icon="clipboard" variant="secondary" :href="route('executive.show', ['locale' => app()->getLocale(), 'project' => $project->id])">{{ __('executive.open') }}</x-hc.button>
            @endif
            @if($viewsApplication && app(\App\Services\AccountAccess::class)->check(auth()->user(), (int) $project->city_id, 'purchasing.view'))
                <x-hc.button icon="clipboard" variant="secondary" :href="route('purchasing.show', ['locale' => app()->getLocale(), 'project' => $project->id])">{{ __('purchasing.open') }}</x-hc.button>
            @endif
            @php($annex = app(\App\Services\Applications\ProjectAnnexService::class)->kind($project))
            @if($annex)
                <x-hc.button icon="clipboard" variant="secondary" :href="route('applications.annex', ['locale' => app()->getLocale(), 'project' => $project->id])">{{ __('annexes.'.$annex) }}</x-hc.button>
            @endif
        </div>
    </div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif
    @if($current)<x-hc.stage-bar :current="$path['current']" :mode="$path['mode']" :tone="$path['tone']" :note="$label($current->projectState)" style="margin-block-end:20px"/>@endif
    @php($tracks = app(\App\Services\AccountAccess::class)->check(auth()->user(), (int) $project->city_id, 'applications.view'))
    @if($tracks)<livewire:project-next-step :project-id="$project->id" />@endif

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
                @if($finance)<p style="margin:12px 0 0"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('finance.show', ['locale' => app()->getLocale(), 'project' => $project->id]) }}"><x-hc.icon name="wallet" :size="16"/>{{ __('finance.links.finance') }}</a></p>@endif
            </x-hc.section>
            @if($tracks)
                @php($scoreService = app(\App\Services\Applications\ProjectScores::class))
                @php($scores = $scoreService->load($project->id))
                @if($project->application_stage === 'full' || collect($scores)->contains(fn ($s) => $s['value'] !== '' || $s['notes'] !== ''))
                <x-hc.section :title="__('applications.sections.scores')" step="4">
                    @if(collect($scores)->contains(fn ($s) => $s['value'] !== '' || $s['notes'] !== ''))
                    <div class="hc-table-wrap"><table class="hc-table">
                        <thead><tr><th>{{ __('applications.scores.criterion') }}</th><th>{{ __('applications.scores.answer') }}</th><th>{{ __('applications.scores.notes') }}</th></tr></thead>
                        <tbody>@foreach($scoreService->criteria() as $c)
                            @php($s = $scores[$c->id] ?? ['value' => '', 'notes' => ''])
                            <tr><td>{{ $c->label }}</td><td>{{ $s['value'] === '' ? '—' : ($c->options->firstWhere('value', (int) $s['value'])->label ?? $s['value']) }}</td><td>{{ $s['notes'] ?: '' }}</td></tr>
                        @endforeach</tbody>
                    </table></div>
                    <p style="margin:12px 0 0;font-weight:600">{{ __('applications.scores.total', ['total' => \App\Services\Applications\ProjectScores::total($scores)]) }}</p>
                    @else
                    <p class="muted">{{ __('applications.scores.empty') }}</p>
                    @endif
                </x-hc.section>
                @endif
            @endif
        </div>
        @if($tracks)<div class="hc-grid">
            <livewire:project-lifecycle :project-id="$project->id" />
            <livewire:project-tasks :project-id="$project->id" />
        </div>@endif
        <livewire:attachments-panel owner="project" :owner-id="$project->id" />
    </div>
</x-layouts.hc>
