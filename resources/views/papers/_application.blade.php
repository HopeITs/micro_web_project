{{-- M4.20: استمارة المشروع (F03) redrawn to the paper's design: five sheets as on the paper. $d comes from ApplicationPaper. --}}
@php
    $L = fn ($key) => __('paper_app.'.$key);
    $p = $d['person']; $pr = $d['project']; $ap = $d['applicant']; $g = $d['grids'];
    $city = $d['city']['code'];
    $yn = ['yes' => $L('yes'), 'no' => $L('no')];
    $hasNot = ['yes' => $L('has'), 'no' => $L('has_not')];
    $fullName = trim(implode(' ', array_filter([$p['first_name'] ?? '', $p['father_name'] ?? '', $p['last_name'] ?? ''])));
    $year = fn ($date) => $date === '' || $date === null ? '' : substr((string) $date, 0, 4);
    $fundChosen = collect($d['fund_types'])->firstWhere('ticked', true)['id'] ?? null;
    $fundOptions = collect($d['fund_types'])->mapWithKeys(fn ($f) => [$f['id'] => $f['label']])->all();
    $current = array_values(array_filter($g['work_experiences'], fn ($r) => ($r['status'] ?? '') === 'yes'));
    $before = array_values(array_filter($g['work_experiences'], fn ($r) => ($r['status'] ?? '') !== 'yes'));
    $own = fn (array $rows) => array_map(fn ($r) => $r + ['_name' => $p['first_name'] ?? '', '_role' => $L('role_beneficiary')], $rows);
    $workers = array_values(array_filter($g['family'], fn ($r) => ($r['has_work'] ?? '') === 'yes'));
@endphp

{{-- Page 1: the project and the beneficiary's story --}}
<x-paper.page :city="$city">
    <div class="p-title">{{ $L('title') }}</div>
    <div class="intro">
        <div>
            <div class="row"><x-paper.field :label="$L('full_name')" :value="$fullName"/><x-paper.field :label="$L('project_no')" :value="$d['number']"/></div>
            <div class="row"><x-paper.field :label="$L('mobile_call')" :value="$p['mobile'] ?? ''"/><x-paper.field :label="$L('career')" :value="$pr['career_id'] ?? ''"/></div>
            <div class="row"><x-paper.field :label="$L('request_date')" :value="$pr['request_date'] ?? ''"/><x-paper.field :label="$L('project_type')" :value="$pr['project_type_id'] ?? ''"/></div>
            <div class="row"><x-paper.field :label="$L('project_desc')" :value="$pr['name'] ?? ''"/><x-paper.field :label="$L('project_nature')" :value="$pr['project_subtype_id'] ?? ''"/></div>
            <div class="row">
                <div class="f"><span class="l">{{ $L('fund_type') }}</span><span class="opts"><x-paper.opts :options="$fundOptions" :chosen="$fundChosen"/></span></div>
                <x-paper.field :label="$L('amount')" :value="($pr['requested_amount'] ?? '') !== '' ? number_format((float) $pr['requested_amount']) : ''"/>
            </div>
            <p class="note">{{ $L('hint_desc') }}</p>
            <p class="note">{{ $L('hint_nature') }}</p>
        </div>
        <div class="photo"></div>
    </div>
    <div class="q">{{ $L('story') }}: <small>{{ $L('story_hint') }}</small></div>
    <div class="lined" style="--n:8">{{ $ap['loss_story'] ?? '' }}</div>
    <div class="q">{{ $L('goal') }}: <small>{{ $L('goal_hint') }}</small></div>
    <div class="lined" style="--n:2">{{ $ap['goal'] ?? '' }}</div>
    <div class="goals">@foreach($d['goals'] as $goal)<span class="opt">{{ $goal['label'] }}<i class="rb {{ $goal['ticked'] ? 'on' : '' }}"></i></span>@endforeach</div>
    <div class="q">{{ $L('goal_how') }}</div>
    <div class="lined" style="--n:3">{{ $ap['goal_how'] ?? '' }}</div>
    <div class="q">{{ $L('other_notes') }}:</div>
    <div class="lined" style="--n:2">{{ $ap['other_notes'] ?? '' }}</div>
</x-paper.page>

{{-- Page 2: personal information and work --}}
<x-paper.page :city="$city">
    <div class="bar">{{ $L('personal') }}</div>
    <div class="row"><x-paper.field :label="$L('name_ar')" :value="$p['first_name'] ?? ''"/><x-paper.field :label="$L('role')" :value="$L('role_beneficiary')"/></div>
    <div class="row"><x-paper.field :label="$L('last_ar')" :value="$p['last_name'] ?? ''"/><x-paper.field :label="$L('sex')" :value="($p['sex'] ?? '') !== '' ? __('people.'.$p['sex']) : ''"/></div>
    <div class="row" style="--c:1"><x-paper.field :label="$L('name_en')" :value="$p['full_name_en'] ?? ''"/></div>
    <div class="row"><x-paper.field :label="$L('father')" :value="$p['father_name'] ?? ''"/><x-paper.field :label="$L('mother')" :value="$p['mother_name'] ?? ''"/></div>
    <div class="row" style="--c:1"><x-paper.field :label="$L('registry')" :value="$p['registration_place'] ?? ''"/></div>
    <div class="row" style="--c:1"><x-paper.field :label="$L('national')" :value="$p['national_number'] ?? ''"/></div>
    <div class="row" style="--c:1"><x-paper.field :label="$L('born')" :value="$year($p['birth_date'] ?? '')"/></div>
    <div class="row" style="--c:1"><x-paper.field :label="$L('marital')" :value="$p['marital_status_id'] ?? ''"/></div>
    <p class="note">{{ $L('hint_marital') }}</p>
    <div class="row" style="--c:3"><x-paper.field :label="$L('parish')" :value="$p['parish_id'] ?? ''"/><x-paper.field :label="$L('intermediary')" :value="$p['intermediary'] ?? ''"/><x-paper.field :label="$L('priest')" :value="trim(($p['priest_id'] ?? '').(($p['second_priest_id'] ?? '') !== '' ? ' / '.$p['second_priest_id'] : ''))"/></div>
    <p class="note">{{ $L('hint_priest') }}</p>
    <div class="row" style="--c:3"><x-paper.field :label="$L('mobile')" :value="$p['mobile'] ?? ''"/><x-paper.field :label="$L('home_phone')" :value="$p['home_phone'] ?? ''"/><x-paper.field :label="$L('family_mobile')" :value="$p['mobile_2'] ?? ''"/></div>
    <div class="row" style="--c:1"><x-paper.field :label="$L('area')" :value="$p['street_id'] ?? ''"/></div>
    <div class="row" style="--c:1"><x-paper.field :label="$L('address')" :value="$p['home_address'] ?? ''"/></div>
    <div class="row"><x-paper.field :label="$L('ownership')" :value="$p['home_ownership_id'] ?? ''"/><x-paper.field :label="$L('notes')" :value="$p['home_state_note'] ?? ''"/></div>
    <p class="note">{{ $L('hint_ownership') }}</p>
    @if(($p['sex'] ?? '') !== 'female')
    <div class="row" style="--c:3"><x-paper.field :label="$L('military')" :value="$p['military_service'] ?? ''"/><x-paper.field :label="$L('military_period')" :value="$p['military_period'] ?? ''"/><x-paper.field :label="$L('military_date')" :value="$p['military_service_date'] ?? ''"/></div>
    <div class="row" style="--c:1"><div class="f"><span class="l">{{ $L('military_war') }}</span><span class="opts"><x-paper.opts :options="$yn" :chosen="$p['military_in_war'] ?? ''"/></span></div></div>
    @endif

    <div class="sub">{{ $L('work_now') }}<span class="opts"><x-paper.opts :options="['yes' => $L('works'), 'no' => $L('not_works')]" :chosen="$ap['has_work'] ?? ''"/></span></div>
    <x-paper.grid :columns="['occupation_id' => $L('w_career'), 'work_type_id' => $L('w_type'), 'description' => $L('w_desc'), 'begin_text' => $L('w_begin'), 'place_state' => $L('w_state'), 'place' => [$L('w_place'), 22], 'contact' => $L('w_contact'), 'salary' => $L('w_salary')]" :rows="$current" :min="2"/>
    <div class="sub">{{ $L('work_before') }}</div>
    <x-paper.grid :columns="['occupation_id' => $L('w_career'), 'work_type_id' => $L('w_type'), 'description' => $L('w_desc'), 'begin_text' => $L('w_begin'), 'end_text' => $L('w_end'), 'place_state' => $L('w_state'), 'place' => $L('w_place'), 'contact' => $L('w_contact'), 'leaving_reason' => $L('w_leaving')]" :rows="$before" :min="3"/>
    <p class="note">{{ $L('hint_work') }}</p>
    <p class="note">{{ $L('hint_work_dates') }}</p>
    <div class="row" style="--c:1;margin-top:3mm"><x-paper.field :label="$L('work_years')" :value="$ap['work_years'] ?? ''"/></div>
</x-paper.page>

{{-- Page 3: education, courses, the project's premises and energy, agreements, budget --}}
<x-paper.page :city="$city">
    <div class="sub">{{ $L('education') }}<span class="opts"><x-paper.opts :options="['yes' => $L('educated'), 'no' => $L('not_educated')]" :chosen="$ap['has_education'] ?? ''"/></span></div>
    <x-paper.grid :columns="['education_level_id' => $L('education'), 'education_specialty_id_group' => $L('degree'), 'education_specialty_id' => $L('speciality')]" :rows="$g['educations']" :min="2"/>
    <p class="note">{{ $L('hint_edu') }}</p>
    <p class="note">{{ $L('hint_degree') }}</p>

    <div class="sub">{{ $L('courses') }}<span class="opts"><x-paper.opts :options="$hasNot" :chosen="$ap['has_courses'] ?? ''"/></span></div>
    <x-paper.grid :columns="['project_subcategory_id' => $L('c_type'), 'name' => $L('c_name'), 'place' => $L('c_place'), 'donor_text' => $L('c_donor'), 'date_text' => $L('c_date')]" :rows="$g['courses']" :min="2"/>
    <p class="note">{{ $L('hint_courses') }}</p>

    <div class="bar" style="margin-top:6mm">{{ $L('project_info') }}</div>
    <x-paper.grid :columns="['place_type_id' => $L('premises_own'), 'place_address' => $L('premises_addr'), 'place_note' => $L('notes')]" :rows="[$pr]" :min="1"/>
    <p class="note">{{ $L('hint_premises') }}</p>

    <div class="sub">{{ $L('power_project') }} <span style="font-weight:500">{{ $L('if_exists') }}</span></div>
    <x-paper.grid :columns="['power_source_id' => $L('power_source'), 'note' => $L('power_note'), 'cost' => $L('power_cost'), 'responsibility' => $L('power_donor')]" :rows="$g['project_power_sources']" :min="2"/>
    <p class="note">{{ $L('hint_power') }}</p>
    <div class="row" style="--c:1;margin-top:3mm"><x-paper.field :label="$L('capacity')" :value="trim($pr['amper_number'] ?? '')"/></div>
    <p class="note">{{ $L('hint_capacity') }}</p>

    <div class="sub">{{ $L('agreements') }}</div>
    @foreach([1, 2, 3, 4, 5] as $n)
        {{-- A stored agreement means "no objection": it ticks «لا» to «هل لديك مانع؟». --}}
        @php($stored = $pr['agreement_'.$n] ?? '')
        <div class="ask"><span>{{ __('paper_app.agreement.'.$n) }}</span><span><x-paper.opts :options="$yn" :chosen="$stored === 'yes' ? 'no' : ($stored === 'no' ? 'yes' : '')"/></span></div>
    @endforeach

    <div class="sub">{{ $L('budget') }}</div>
    <x-paper.grid :columns="['name' => $L('b_name'), 'comments' => $L('b_kind'), 'quantity' => $L('b_qty'), 'price' => $L('b_price')]" :rows="$g['materials']" :min="2"/>
    <p class="note">{{ $L('hint_budget') }}</p>
</x-paper.page>

{{-- Page 4: the family --}}
<x-paper.page :city="$city">
    <div class="bar">{{ $L('family_info') }}</div>
    <div class="row" style="--c:3"><x-paper.field :label="$L('family_size')" :value="$p['family_size'] ?? ''"/><x-paper.field :label="$L('household')" :value="$p['household_size'] ?? ''"/><x-paper.field :label="$L('family_book')" :value="$d['family_number']"/></div>
    <div class="row" style="--c:2"><div class="f"><span class="l">{{ $L('other_family') }}</span><span class="opts"><x-paper.opts :options="$yn" :chosen="$ap['lives_with_family'] ?? ''"/></span></div><x-paper.field :label="$L('who')" :value="$ap['lives_with_family_note'] ?? ''"/></div>
    <x-paper.grid :columns="['first_name' => $L('f_name'), 'last_name' => $L('f_last'), 'father_name' => $L('f_father'), 'relation_id' => $L('f_relation'), 'national_number' => [$L('f_national'), 15], 'birth_date' => $L('f_born'), 'person_state_id' => $L('f_state'), 'education_level_id' => $L('f_edu'), 'degree' => $L('f_degree'), 'has_work' => $L('f_works')]"
        :rows="$g['family']" :min="7"
        :fmt="['birth_date' => fn ($r) => $year($r['birth_date'] ?? ''), 'degree' => fn ($r) => ($r['education_specialty_id_group'] ?? '') !== '' ? $r['education_specialty_id_group'] : ($r['education_specialty_id'] ?? ''), 'has_work' => fn ($r) => ($r['has_work'] ?? '') === 'yes' ? $L('works') : (($r['has_work'] ?? '') === 'no' ? $L('not_works') : '')]"/>
    <p class="note">{{ $L('hint_family') }}</p>
    <p class="note">{{ $L('hint_state') }}</p>

    <div class="sub">{{ $L('family_work') }}</div>
    <x-paper.grid :columns="['first_name' => $L('f_name'), 'relation_id' => $L('f_relation'), 'work_name' => $L('fw_job'), 'type' => $L('fw_type'), 'income' => $L('fw_income')]" :rows="$workers" :min="2"/>
    <p class="note">{{ $L('hint_family_work') }}</p>

    <div class="sub">{{ $L('losses') }}<span class="opts"><x-paper.opts :options="$hasNot" :chosen="$ap['has_loss'] ?? ''"/></span></div>
    <x-paper.grid :columns="['loss_type_id' => $L('l_type'), 'year_text' => $L('l_year'), 'note' => $L('notes')]" :rows="$g['losses']" :min="2"/>
    <p class="note">{{ $L('hint_loss') }}</p>

    <div class="sub">{{ $L('illness') }}<span class="opts"><x-paper.opts :options="$hasNot" :chosen="$ap['has_illness'] ?? ''"/></span></div>
    <x-paper.grid :columns="['_name' => $L('i_name'), '_role' => $L('i_role'), 'illness_id' => $L('i_state'), 'note' => $L('i_desc'), 'has_medical_aid' => $L('i_aid'), 'donor' => $L('i_donor'), 'aid_period_id' => $L('i_period'), 'medical_need_id' => $L('i_need')]"
        :rows="$own($g['illnesses'])" :min="3" :fmt="['donor' => fn ($r) => ($r['donor_text'] ?? '') !== '' ? $r['donor_text'] : ($r['aid_source_id'] ?? '')]"/>
    <p class="note">{{ $L('hint_illness') }}</p>
    <p class="note">{{ $L('hint_needs') }}</p>
</x-paper.page>

{{-- Page 5: assets, aid, debts, energy at home, declaration --}}
<x-paper.page :city="$city">
    <div class="sub">{{ $L('assets') }}<span class="opts"><x-paper.opts :options="$hasNot" :chosen="$ap['has_assets'] ?? ''"/></span></div>
    <x-paper.grid :columns="['_name' => $L('a_name'), '_role' => $L('a_role'), 'asset_kind_id' => $L('a_kind'), 'year_text' => $L('a_year'), 'asset_type_id' => $L('a_nature'), 'description' => $L('a_desc'), 'asset_benefit_id' => $L('a_benefit')]" :rows="$own($g['assets'])" :min="2"/>
    <p class="note">{{ $L('hint_assets') }}</p>

    <div class="sub">{{ $L('aid_other') }} <span style="font-weight:500">{{ $L('aid_small') }}</span><span class="opts"><x-paper.opts :options="$hasNot" :chosen="$ap['has_aid_sources'] ?? ''"/></span></div>
    <x-paper.grid :columns="['_name' => $L('a_name'), '_role' => $L('a_role'), 'aid_source_id' => $L('o_source'), 'year_text' => $L('o_date'), 'amount_text' => $L('o_amount'), 'project_type_text' => $L('o_project'), 'notes' => $L('o_items')]" :rows="$own($g['aid_sources'])" :min="2"/>
    <div class="row" style="--c:3;margin:4mm 0"><div class="f"><span class="l">{{ $L('debts') }}</span><span class="opts"><x-paper.opts :options="$yn" :chosen="$ap['has_debts'] ?? ''"/></span></div><x-paper.field :label="$L('debts_amount')" :value="$ap['debts_amount'] ?? ''"/><x-paper.field :label="$L('debts_reason')" :value="$ap['debts_note'] ?? ''"/></div>

    <div class="sub">{{ $L('help') }}<span class="opts"><x-paper.opts :options="$hasNot" :chosen="$ap['has_help'] ?? ''"/></span></div>
    <x-paper.grid :columns="['aid_source_id' => $L('h_source'), 'aid_type_id' => $L('h_type'), 'aid_period_id' => $L('h_period'), 'notes' => $L('notes')]" :rows="$g['aid_received']" :min="2"/>
    <p class="note">{{ $L('hint_help') }}</p>

    <div class="sub">{{ $L('power_home') }}</div>
    <x-paper.grid :columns="['power_source_id' => $L('power_source'), 'note' => $L('power_note'), 'cost' => $L('power_cost'), 'responsibility' => $L('power_donor')]" :rows="$g['power_sources']" :min="2"/>
    <p class="note">{{ $L('hint_power') }}</p>

    <div class="q" style="margin-top:5mm">{{ $L('extra') }}:</div>
    <div class="lined" style="--n:2">{{ $pr['profit_description'] ?? '' }}</div>

    <p style="margin:6mm 0 0;font-weight:700;line-height:2.1">{{ $L('declaration_1') }} <span style="border-bottom:1px dotted #444;padding-inline:4mm">{{ $fullName }}</span> {{ $L('declaration_2') }}</p>
    <div class="sigs">
        <div>{{ $L('sig_beneficiary') }}</div>
        <div>{{ $L('sig_registrar') }}@if($d['registrars']['paper'])<br><small>{{ $d['registrars']['paper'] }}</small>@endif</div>
        <div>{{ $L('sig_registrar') }}@if($d['registrars']['program'])<br><small>{{ $d['registrars']['program'] }}</small>@endif</div>
    </div>
</x-paper.page>
