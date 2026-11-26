@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $name = \App\Services\People\PeopleService::fullName($person);
    $value = function (string $field, array $spec) use ($person, $labels) {
        $v = $person->{$field};
        return match ($spec['kind']) {
            'lookup' => $labels[$field] ?? null,
            'bool' => $v === null ? null : ($v ? __('people.yes') : __('people.no')),
            'sex' => $v ? __('people.'.$v) : null,
            'choice' => $v ? __('people.choices.'.$field.'.'.$v) : null,
            'date' => $v ? substr((string) $v, 0, 10) : null,
            default => filled($v) ? $v : null,
        };
    };
    // Shown once, in the header: not repeated in the details.
    $inHeader = ['first_name', 'father_name', 'last_name', 'sex', 'birth_date', 'marital_status_id', 'person_state_id', 'national_number', 'mobile', 'mobile_2', 'home_phone'];
    $groups = [];
    foreach (\App\Services\People\PersonFields::PERSON as $group => $fields) {
        if ($group === 'military' && $person->sex !== 'male') {
            continue;
        }
        $shown = [];
        foreach (array_diff_key($fields, array_flip($inHeader)) as $field => $spec) {
            if (($text = $value($field, $spec)) !== null) {
                $shown[$field] = $text;
            }
        }
        if ($shown !== []) {
            $groups[$group] = $shown;
        }
    }
    $born = $person->birth_date ? substr((string) $person->birth_date, 0, 10) : null;
    $age = $born ? max(0, (int) \Illuminate\Support\Carbon::parse($born)->diffInYears(\Illuminate\Support\Carbon::today())) : null;
    // A birth year alone is stored as the first of January.
    $bornText = $born && str_ends_with($born, '-01-01') ? substr($born, 0, 4) : $born;
    $areas = ['work' => ['work_experiences', 'educations', 'courses', 'goals'], 'support' => ['illnesses', 'losses', 'assets', 'aid_sources', 'aid_received', 'power_sources']];
    $tabs = ['family', 'details', 'work', 'support', 'projects', 'files'];
    $amounts = [
        'work' => array_sum(array_intersect_key($counts, array_flip($areas['work']))),
        'support' => array_sum(array_intersect_key($counts, array_flip($areas['support']))),
        'projects' => $projects->count(),
    ];
@endphp
<x-layouts.hc :title="$name">
    <p style="margin-block-end:12px"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('people.index', ['locale' => $locale]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('people.back') }}</a></p>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif

    <header class="hc-card person-head">
        <span class="fam-avatar person-avatar" aria-hidden="true"><x-hc.icon name="user" :size="30"/></span>
        <div class="person-main">
            <p class="hc-eyebrow">{{ $en ? $city->name_en : $city->name_ar }} · {{ __('people.person') }}</p>
            <h1>{{ $name }}</h1>
            <ul class="person-facts">
                @if($person->sex)<li>{{ __('people.'.$person->sex) }}</li>@endif
                @if($born)<li><span class="num ltr">{{ $bornText }}</span> · {{ __('families.age_years', ['age' => $age]) }}</li>@endif
                @if($labels['marital_status_id'] ?? null)<li>{{ $labels['marital_status_id'] }}</li>@endif
                <li @class(['is-missing' => ! ($labels['person_state_id'] ?? null)])>{{ $labels['person_state_id'] ?? __('families.no_state') }}</li>
            </ul>
            <ul class="person-roles">
                @forelse($homes as $home)
                    <li>
                        <x-hc.icon name="users" :size="16"/>
                        <a href="{{ route('families.show', ['locale' => $locale, 'family' => $home->family_id]) }}#h-{{ $home->head_id }}">
                            @if($home->is_head){{ __('families.role_head') }}
                            @elseif($home->head_name){{ __('families.role_member', ['relation' => $home->relation ?? __('families.no_relation'), 'name' => $home->head_name]) }}
                            @else{{ __('families.role_unknown', ['relation' => $home->relation ?? __('families.no_relation')]) }}@endif
                            · {{ __('people.family_number') }} <span class="ltr num">{{ $home->family_number }}</span>
                        </a>
                    </li>
                @empty
                    <li class="muted">{{ __('families.not_in_family') }}</li>
                @endforelse
            </ul>
            <dl class="person-contacts">
                <div>
                    <dt>{{ __('people.fields.national_number') }}</dt>
                    <dd>@if(filled($person->national_number))<span class="ltr num" dir="ltr">{{ $person->national_number }}</span>@include('people._copy', ['text' => $person->national_number])@else<span class="muted">{{ __('hc.not_set') }}</span>@endif</dd>
                </div>
                @foreach(['mobile', 'mobile_2', 'home_phone'] as $field)
                    @continue(! filled($person->{$field}) && $field !== 'mobile')
                    <div>
                        <dt>{{ __('people.fields.'.$field) }}</dt>
                        <dd>@if(filled($person->{$field}))<a class="ltr num fam-tel" dir="ltr" href="tel:{{ preg_replace('/[^\d+]/', '', $person->{$field}) }}" title="{{ __('families.call') }}"><x-hc.icon name="phone" :size="15"/>{{ $person->{$field} }}</a>@include('people._copy', ['text' => $person->{$field}])@else<span class="muted">{{ __('hc.not_set') }}</span>@endif</dd>
                    </div>
                @endforeach
            </dl>
        </div>
        <div class="person-actions">
            @if(app(\App\Services\Applications\ApplicationService::class)->canWrite(auth()->user(), (int) $person->city_id))
                <x-hc.button icon="plus" :href="route('applications.create', ['locale' => $locale, 'person' => $person->id])">{{ __('applications.new') }}</x-hc.button>
            @endif
            @if($editable)<x-hc.button icon="pencil" variant="secondary" :href="route('people.edit', ['locale' => $locale, 'person' => $person->id])">{{ __('people.edit_title') }}</x-hc.button>@endif
            @if($editable)<x-hc.button icon="id" variant="secondary" :href="route('people.identity', ['locale' => $locale, 'person' => $person->id])">{{ __('identity.button') }}</x-hc.button>@endif
            @if($person->national_number)<x-hc.button icon="search" variant="ghost" :href="route('people.national', ['locale' => $locale, 'person' => $person->id])">{{ __('national.from_person') }}</x-hc.button>@endif
        </div>
    </header>

    <div x-data="hcTabs('hc.person.tab', @js($tabs), 'family')">
        <div class="hc-tabs" role="tablist" aria-label="{{ __('people.person') }}" @keydown="keys($event)">
            @foreach($tabs as $tab)
                <button type="button" role="tab" id="ptab-{{ $tab }}" data-tab="{{ $tab }}" aria-controls="ppanel-{{ $tab }}" :aria-selected="tab === '{{ $tab }}'" aria-selected="{{ $tab === 'family' ? 'true' : 'false' }}" :tabindex="tab === '{{ $tab }}' ? 0 : -1" @click="select('{{ $tab }}')">{{ __('families.person_tabs.'.$tab) }}@if(($amounts[$tab] ?? 0) > 0) <span>{{ $amounts[$tab] }}</span>@endif</button>
            @endforeach
        </div>

        <div class="hc-grid" role="tabpanel" id="ppanel-family" aria-labelledby="ptab-family" x-show="tab === 'family'">
            @forelse($homes as $home)
                <livewire:family-panel :person-id="$person->id" :family-id="$home->family_id" :head-id="$home->head_id" :key="'f-'.$home->family_id.'-'.$home->head_id" />
            @empty
                <livewire:family-panel :person-id="$person->id" />
            @endforelse
        </div>

        <div class="hc-grid" role="tabpanel" id="ppanel-details" aria-labelledby="ptab-details" x-show="tab === 'details'" x-cloak>
            @forelse($groups as $group => $shown)
                <x-hc.card :title="__('people.groups.'.$group)">
                    <dl class="hc-dl">
                        @foreach($shown as $field => $text)<div><dt>{{ __('people.fields.'.$field) }}</dt><dd>{{ $text }}</dd></div>@endforeach
                    </dl>
                </x-hc.card>
            @empty
                <x-hc.card><x-hc.empty :title="__('families.no_details')" icon="user">{{ __('families.no_details_hint') }}</x-hc.empty></x-hc.card>
            @endforelse
        </div>

        @foreach($areas as $area => $kinds)
            @php($filled = array_values(array_filter($kinds, fn ($kind) => $counts[$kind] > 0)))
            <div class="hc-grid" role="tabpanel" id="ppanel-{{ $area }}" aria-labelledby="ptab-{{ $area }}" x-show="tab === '{{ $area }}'" x-cloak x-data="{ shown: @js($filled) }">
                @if($filled === [])
                    <p class="muted" x-show="shown.length === 0">{{ __('families.area_empty') }}</p>
                @endif
                @if($editable && count($filled) < count($kinds))
                    <div class="person-add" x-data="{ menu: false }" x-show="shown.length < {{ count($kinds) }}" @click.outside="menu = false" @keydown.escape="menu = false">
                        <button type="button" class="hc-btn hc-btn-secondary" @click="menu = !menu" :aria-expanded="menu"><x-hc.icon name="plus" :size="18"/>{{ __('families.add_data') }}<x-hc.icon name="chevron" :size="16"/></button>
                        <div class="hc-dropdown" x-show="menu" x-cloak>
                            @foreach($kinds as $kind)
                                @continue($counts[$kind] > 0)
                                <button type="button" x-show="! shown.includes('{{ $kind }}')" @click="shown.push('{{ $kind }}'); menu = false; $nextTick(() => window.dispatchEvent(new CustomEvent('person-add', { detail: '{{ $kind }}' })))">{{ __('people.details.'.$kind) }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif
                @foreach($kinds as $kind)
                    {{-- Empty tables stay out of sight until chosen from the menu. --}}
                    <div x-show="shown.includes('{{ $kind }}')" @if($counts[$kind] === 0) x-cloak @endif data-kind="{{ $kind }}">
                        <livewire:person-details :person-id="$person->id" :kind="$kind" :key="'d-'.$kind" />
                    </div>
                @endforeach
            </div>
        @endforeach

        <div role="tabpanel" id="ppanel-projects" aria-labelledby="ptab-projects" x-show="tab === 'projects'" x-cloak>
            <x-hc.card :title="__('people.projects')" :flush="true">
                @if($projects->isEmpty())
                    <p class="muted" style="padding:16px 20px">{{ __('people.no_projects') }}</p>
                @else
                <div class="hc-table-wrap"><table class="hc-table stack">
                    <thead><tr><th>{{ __('hc.number') }}</th><th>{{ __('hc.project') }}</th><th>{{ __('families.state') }}</th><th>{{ __('hc.stage') }}</th><th>{{ __('people.role') }}</th><th>{{ __('hc.request_date') }}</th></tr></thead>
                    <tbody>@foreach($projects as $pr)
                        <tr><td data-label="{{ __('hc.number') }}"><a class="row-link" href="{{ route('projects.show', ['locale' => $locale, 'project' => $pr->id]) }}"><x-hc.project-number :code="$pr->code" :number="$pr->project_number"/></a></td>
                            <td data-label="{{ __('hc.project') }}">{{ $pr->name ?: __('hc.not_set') }}</td>
                            <td data-label="{{ __('families.state') }}">@if($pr->state)<x-hc.badge :tone="\App\Support\ProjectStatus::tone($pr->state_en)" :icon="\App\Support\ProjectStatus::icon($pr->state_en)">{{ $pr->state }}</x-hc.badge>@else — @endif</td>
                            <td data-label="{{ __('hc.stage') }}"><x-hc.badge>{{ __('hc.stage_'.$pr->application_stage) }}</x-hc.badge></td>
                            <td data-label="{{ __('people.role') }}">{{ $pr->role ?: '—' }}</td>
                            <td data-label="{{ __('hc.request_date') }}" class="num">{{ $pr->request_date ? substr($pr->request_date, 0, 10) : '—' }}</td></tr>
                    @endforeach</tbody>
                </table></div>
                @endif
            </x-hc.card>
        </div>

        <div role="tabpanel" id="ppanel-files" aria-labelledby="ptab-files" x-show="tab === 'files'" x-cloak>
            <livewire:attachments-panel owner="person" :owner-id="$person->id" />
        </div>
    </div>
</x-layouts.hc>
