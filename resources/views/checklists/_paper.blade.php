@php
    $ar = app()->isLocale('ar');
    $h = $state['header'];
    $text = fn ($name) => ($v = $h[$name] ?? '') === '' ? '—' : $v;
    $flag = fn ($name) => match ($h[$name] ?? '') { '1' => __('people.yes'), '0' => __('people.no'), default => '—' };
    $member = fn ($id) => $id !== '' && $id !== null && isset($names[(int) $id]) ? $names[(int) $id]->name_ar : '—';
    $memberPos = fn ($id) => $id !== '' && $id !== null && isset($names[(int) $id]) ? ($names[(int) $id]->position ?? '') : '';
    $p = fn ($key) => __('checklists.print_labels.'.$key);
    $number = \App\Services\ProjectNumberAllocator::display($city->code, (int) $project->project_number);
    $personName = $person ? \App\Services\People\PeopleService::fullName($person) : '—';
@endphp
    <img class="letterhead" src="{{ asset('assets/brand/checklist-header.png') }}" alt="Hope Center">
    <h1>Check List</h1>

    <section>
        <div class="sec">{{ $p('info') }}</div>
        <table class="grid"><tbody>
            <tr><td><b>{{ $p('name') }}:</b> {{ $personName }}</td><td><b>{{ $p('project_no') }}:</b> <bdi>{{ $number }}</bdi></td></tr>
            <tr><td><b>{{ $p('city') }}:</b> {{ $ar ? $city->name_ar : $city->name_en }}</td><td><b>{{ $p('project_name') }}:</b> {{ $project->name }}</td></tr>
        </tbody></table>
    </section>

    <section>
        <div class="sec">{{ __('checklists.lists.key_people') }}</div>
        <table class="grid"><thead><tr><th>{{ $p('name_col') }}</th><th>{{ $p('position') }}</th><th>{{ $p('role') }}</th><th>{{ $p('notes') }}</th></tr></thead><tbody>
        @forelse($state['members']['k'] as $row)
            <tr><td>{{ $row['name_notes'] ?: $member($row['checklist_member_id']) }}</td><td>{{ $member($row['checklist_member_id']) }} {{ $memberPos($row['checklist_member_id']) ? '· '.$memberPos($row['checklist_member_id']) : '' }}</td><td>{{ $row['role_notes'] ?: '—' }}</td><td>{{ $row['notes'] ?: '—' }}</td></tr>
        @empty<tr><td colspan="4">—</td></tr>@endforelse
        </tbody></table>
    </section>

    <section>
        <div class="sec">{{ $p('visit') }}</div>
        <table class="grid"><thead><tr><th>{{ $p('visit_type') }}</th><th>{{ $p('visit_date') }}</th><th>{{ $p('visit_time') }}</th><th>{{ $p('visitors') }}</th><th>{{ $p('notes') }}</th></tr></thead><tbody>
            <tr><td>{{ $p('home') }} ({{ $flag('has_home_visit') }})</td><td>{{ $text('home_visit_date') }}</td><td>{{ $text('home_visit_time') }}</td><td>{{ $member($h['home_visitor_1_id']) }}<br>{{ $h['home_visitor_2_id'] ? $member($h['home_visitor_2_id']) : '' }}</td><td>{{ $text('home_visit_note') }}</td></tr>
            <tr><td>{{ $p('project') }} ({{ $flag('has_project_visit') }})</td><td>{{ $text('project_visit_date') }}</td><td>{{ $text('project_visit_time') }}</td><td>{{ $member($h['project_visitor_1_id']) }}<br>{{ $h['project_visitor_2_id'] ? $member($h['project_visitor_2_id']) : '' }}</td><td>{{ $text('project_visit_note') }}</td></tr>
        </tbody></table>
        <div class="sub">{{ __('checklists.fields.visits_report') }}</div>
        <div class="box">{{ $text('visits_report') }}</div>
    </section>

    <section>
        <div class="sec">{{ $p('experience') }}</div>
        <div class="box"><b>{{ __('checklists.fields.needs_professional_test') }}:</b> {{ $flag('needs_professional_test') }} @if($h['professional_test_need_note'] !== '') — {{ $h['professional_test_need_note'] }}@endif</div>
        <div class="sub">{{ $p('pro_test') }} ({{ $flag('has_professional_test') }})</div>
        <table class="grid"><thead><tr><th>{{ $p('test_date') }}</th><th>{{ __('checklists.fields.professional_test_note') }}</th><th>{{ __('checklists.fields.professional_tester_id') }}</th><th>{{ $p('result') }}</th></tr></thead><tbody>
        <tr><td>{{ $text('professional_test_date') }}</td><td>{{ $text('professional_test_note') }}</td><td>{{ $member($h['professional_tester_id']) }}</td><td>{{ ($v = $h['professional_test_result'] ?? '') === '' ? '—' : __('people.choices.professional_test_result.'.$v) }}</td></tr></tbody></table>
        <div class="sub">{{ $p('pro_train') }} ({{ $flag('has_professional_training') }})</div>
        <table class="grid"><thead><tr><th>{{ $p('trainer') }}</th><th>{{ $p('place') }}</th><th>{{ $p('period') }}</th><th>{{ $p('result') }}</th></tr></thead><tbody>
        <tr><td>{{ $text('professional_trainer') }}</td><td>{{ $text('professional_training_place') }}</td><td>{{ $text('professional_training_start') }} → {{ $text('professional_training_end') }}</td><td>{{ $text('professional_training_result') }}</td></tr></tbody></table>
        <div class="sub">{{ $p('mng_train') }} ({{ $flag('has_management_training') }})</div>
        <table class="grid"><thead><tr><th>{{ $p('trainer') }}</th><th>{{ $p('place') }}</th><th>{{ $p('date') }}</th><th>{{ $p('result') }}</th></tr></thead><tbody>
        <tr><td>{{ $text('management_trainer') }}</td><td>{{ $text('management_training_place') }}</td><td>{{ $text('management_training_date') }}</td><td>{{ $text('management_training_result') }}</td></tr></tbody></table>
    </section>

    @foreach(['t' => ['team', 'team_date'], 'c' => ['committee', 'committee_date']] as $location => [$title, $dateField])
    <section>
        <div class="sec">{{ __('checklists.lists.'.$title) }}</div>
        <div class="box"><b>{{ $p('meeting_date') }}:</b> {{ $text($dateField) }}</div>
        <table class="grid"><thead><tr><th>{{ $p('name_col') }}</th><th>{{ $p('opinion') }}</th><th>{{ $p('reason') }}</th><th>{{ $p('signature') }}</th></tr></thead><tbody>
        @forelse($state['members'][$location] as $row)
            <tr><td>{{ $member($row['checklist_member_id']) }}</td><td>{{ $row['checklist_opinion_id'] !== '' ? ($opinions[(int) $row['checklist_opinion_id']]->label ?? '—') : '—' }}</td><td>{{ $row['notes'] ?: '' }}</td><td></td></tr>
        @empty<tr><td colspan="4">—</td></tr>@endforelse
        </tbody></table>
    </section>
    @endforeach

    @if($need !== null)
    <section>
        <div class="sec">{{ __('checklists.sections.need') }}</div>
        <table class="grid"><thead><tr><th style="width:38%">{{ __('checklists.criterion') }}</th><th>{{ __('checklists.need.indicator') }}</th><th style="width:14%;text-align:center">{{ __('checklists.need.mark') }}</th></tr></thead><tbody>
        @foreach($need['rows'] as $row)
            <tr><td>{{ __('checklists.need.criteria.'.$row['key']) }}</td><td style="text-align:center">{{ $row['indicator'] !== '' ? $row['indicator'] : '—' }}</td><td style="text-align:center">{{ $row['mark'] ?? '—' }}</td></tr>
        @endforeach
            <tr><th colspan="2" style="text-align:end">{{ __('checklists.need.total') }}</th><th style="text-align:center">{{ $need['total'] }}</th></tr>
        </tbody></table>
    </section>
    @endif

    <section>
        <div class="sec">{{ $p('notes') }}</div>
        <div class="box">{{ $text('notes') }}</div>
        <div class="box"><b>{{ __('checklists.fields.rejection_message') }}:</b> {{ $flag('rejection_message') }}@if($h['rejection_message'] === '1') · {{ $text('rejection_message_date') }} · {{ $sender ?? '—' }}@endif</div>
    </section>
    <p class="foot">{{ $p('printed') }} {{ now()->format('Y-m-d H:i') }}</p>
