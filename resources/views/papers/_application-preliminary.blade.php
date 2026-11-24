{{-- M4.20: الاستمارة الأولية للمشروع (F02) redrawn to the paper's design: two sheets. $d comes from ApplicationPaper. --}}
@php
    $L = fn ($key) => __('paper_app.'.$key);
    $p = $d['person']; $pr = $d['project']; $ap = $d['applicant']; $g = $d['grids'];
    $city = $d['city']['code'];
    $yn = ['yes' => $L('yes'), 'no' => $L('no')];
    $hasNot = ['yes' => $L('has'), 'no' => $L('has_not')];
    $year = fn ($date) => $date === '' || $date === null ? '' : substr((string) $date, 0, 4);
    $current = array_values(array_filter($g['work_experiences'], fn ($r) => ($r['status'] ?? '') === 'yes'));
    $own = fn (array $rows) => array_map(fn ($r) => $r + ['_name' => $p['first_name'] ?? '', '_role' => $L('role_beneficiary')], $rows);
@endphp

{{-- Page 1: the project, personal information and current work --}}
<x-paper.page :city="$city">
    <div class="p-title">{{ $L('title_pre') }}</div>
    <div class="row"><x-paper.field :label="$L('career')" :value="$pr['career_id'] ?? ''"/><x-paper.field :label="$L('project_no')" :value="$d['number']"/></div>
    <div class="row"><x-paper.field :label="$L('request_date')" :value="$pr['request_date'] ?? ''"/><x-paper.field :label="$L('project_type')" :value="$pr['project_type_id'] ?? ''"/></div>
    <div class="row"><x-paper.field :label="$L('project_desc')" :value="$pr['name'] ?? ''"/><x-paper.field :label="$L('project_nature')" :value="$pr['project_subtype_id'] ?? ''"/></div>
    <p class="note">{{ $L('hint_desc') }}</p>
    <p class="note">{{ $L('hint_nature') }}</p>

    <div class="bar">{{ $L('personal') }}</div>
    <div class="row" style="--c:3"><x-paper.field :label="$L('name_ar')" :value="$p['first_name'] ?? ''"/><x-paper.field :label="$L('last_ar')" :value="$p['last_name'] ?? ''"/><x-paper.field :label="$L('sex')" :value="($p['sex'] ?? '') !== '' ? __('people.'.$p['sex']) : ''"/></div>
    <div class="row" style="--c:1"><x-paper.field :label="$L('role')" :value="$L('role_beneficiary')"/></div>
    <div class="row"><x-paper.field :label="$L('father')" :value="$p['father_name'] ?? ''"/><x-paper.field :label="$L('mother')" :value="$p['mother_name'] ?? ''"/></div>
    <div class="row" style="--c:1"><x-paper.field :label="$L('national')" :value="$p['national_number'] ?? ''"/></div>
    <div class="row"><x-paper.field :label="$L('born')" :value="$year($p['birth_date'] ?? '')"/><x-paper.field :label="$L('marital')" :value="$p['marital_status_id'] ?? ''"/></div>
    <p class="note">{{ $L('hint_marital') }}</p>
    <div class="row"><x-paper.field :label="$L('parish')" :value="$p['parish_id'] ?? ''"/><x-paper.field :label="$L('intermediary')" :value="$p['intermediary'] ?? ''"/></div>
    <p class="note">{{ $L('hint_priest') }}</p>
    <div class="row"><x-paper.field :label="$L('area')" :value="$p['street_id'] ?? ''"/><x-paper.field :label="$L('mobile_call')" :value="$p['mobile'] ?? ''"/></div>
    <div class="row"><x-paper.field :label="$L('address')" :value="$p['home_address'] ?? ''"/><x-paper.field :label="$L('home_phone')" :value="$p['home_phone'] ?? ''"/></div>
    <div class="row"><x-paper.field :label="$L('ownership')" :value="$p['home_ownership_id'] ?? ''"/><x-paper.field :label="$L('notes')" :value="$p['home_state_note'] ?? ''"/></div>
    <p class="note">{{ $L('hint_ownership') }}</p>

    <div class="sub">{{ $L('work_now') }}<span class="opts"><x-paper.opts :options="['yes' => $L('works'), 'no' => $L('not_works')]" :chosen="$ap['has_work'] ?? ''"/></span></div>
    <x-paper.grid :columns="['occupation_id' => $L('w_career'), 'work_type_id' => $L('w_type'), 'description' => $L('w_desc'), 'begin_text' => $L('w_begin'), 'place_state' => $L('w_state'), 'place' => [$L('w_place'), 22], 'contact' => $L('w_contact'), 'salary' => $L('w_salary')]" :rows="$current" :min="3"/>
    <p class="note">{{ $L('hint_work') }}</p>
    <div class="row" style="--c:1;margin-top:3mm"><x-paper.field :label="$L('work_years')" :value="$ap['work_years'] ?? ''"/></div>
</x-paper.page>

{{-- Page 2: the family, aid from others, notes and signatures --}}
<x-paper.page :city="$city">
    <div class="bar">{{ $L('family_info') }}</div>
    <div class="row" style="--c:3"><x-paper.field :label="$L('family_size')" :value="$p['family_size'] ?? ''"/><x-paper.field :label="$L('household')" :value="$p['household_size'] ?? ''"/><x-paper.field :label="$L('family_book')" :value="$d['family_number']"/></div>
    <x-paper.grid :columns="['first_name' => $L('f_name'), 'last_name' => $L('f_last'), 'father_name' => $L('f_father'), 'relation_id' => $L('f_relation'), 'birth_date' => $L('f_born'), 'person_state_id' => $L('f_state'), 'has_work' => $L('f_works')]"
        :rows="$g['family']" :min="5"
        :fmt="['birth_date' => fn ($r) => $year($r['birth_date'] ?? ''), 'has_work' => fn ($r) => ($r['has_work'] ?? '') === 'yes' ? $L('works') : (($r['has_work'] ?? '') === 'no' ? $L('not_works') : '')]"/>
    <div class="row" style="--c:1;margin-top:3mm"><div class="f"><span class="l">{{ $L('other_family') }}</span><span class="opts"><x-paper.opts :options="$yn" :chosen="$ap['lives_with_family'] ?? ''"/></span></div></div>
    <p class="note">{{ $L('hint_family') }}</p>
    <p class="note">{{ $L('hint_state') }}</p>

    <div class="sub">{{ $L('aid_other') }} <span style="font-weight:500">{{ $L('aid_small') }}</span><span class="opts"><x-paper.opts :options="$hasNot" :chosen="$ap['has_aid_sources'] ?? ''"/></span></div>
    <x-paper.grid :columns="['_name' => $L('a_name'), '_role' => $L('a_role'), 'aid_source_id' => $L('o_source'), 'year_text' => $L('o_date'), 'amount_text' => $L('o_amount'), 'project_type_text' => $L('o_project'), 'notes' => $L('o_items')]" :rows="$own($g['aid_sources'])" :min="2"/>

    <div class="row" style="--c:1;margin-top:4mm"><x-paper.field :label="$L('notes').':'" :value="$ap['other_notes'] ?? ''"/></div>
    <div class="q" style="margin-top:2mm">{{ $L('extra') }}:</div>
    <div class="lined" style="--n:2">{{ $pr['profit_description'] ?? '' }}</div>
    <p style="text-align:center;margin:8mm 0 0;font-size:10pt">*{{ $L('pre_note') }}.</p>
    <div class="sigs" style="--c:2">
        <div>{{ $L('sig_beneficiary') }}</div>
        <div>{{ $L('sig_applicant') }}@if($d['registrars']['paper'])<br><small>{{ $d['registrars']['paper'] }}</small>@endif</div>
    </div>
</x-paper.page>
