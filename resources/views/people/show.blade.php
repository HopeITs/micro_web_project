@php
    $en = app()->getLocale() === 'en';
    $locale = app()->getLocale();
    $value = function (string $field, array $spec) use ($person, $labels) {
        $v = $person->{$field};
        return match ($spec['kind']) {
            'lookup' => $labels[$field] ?? null,
            'bool' => $v === null ? null : ($v ? __('people.yes') : __('people.no')),
            'sex' => $v ? __('people.'.$v) : null,
            'date' => $v ? substr((string) $v, 0, 10) : null,
            default => filled($v) ? $v : null,
        };
    };
@endphp
<x-layouts.hc :title="\App\Services\People\PeopleService::fullName($person)">
    <p style="margin-block-end:12px"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('people.index', ['locale' => $locale]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('people.back') }}</a></p>
    <div class="hc-page-head">
        <div>
            <p class="hc-eyebrow">{{ $en ? $city->name_en : $city->name_ar }} · {{ __('people.person') }}</p>
            <h1>{{ \App\Services\People\PeopleService::fullName($person) }}</h1>
            <p>@if($person->national_number)<span class="ltr num">{{ $person->national_number }}</span> · @endif{{ $person->birth_date ? substr($person->birth_date, 0, 10) : '' }}</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            @if($person->national_number)<x-hc.button icon="id" variant="ghost" :href="route('people.national', ['locale' => $locale, 'person' => $person->id])">{{ __('national.from_person') }}</x-hc.button>@endif
            @if($editable)<x-hc.button icon="settings" variant="secondary" :href="route('people.edit', ['locale' => $locale, 'person' => $person->id])">{{ __('people.edit_title') }}</x-hc.button>@endif
            @if(app(\App\Services\Applications\ApplicationService::class)->canWrite(auth()->user(), (int) $person->city_id))
                <x-hc.button icon="plus" :href="route('applications.create', ['locale' => $locale, 'person' => $person->id])">{{ __('applications.new') }}</x-hc.button>
            @endif
        </div>
    </div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif

    <div class="hc-grid" style="grid-template-columns: repeat(auto-fit, minmax(min(100%, 520px), 1fr)); align-items:start">
        <div class="hc-grid">
            @foreach(\App\Services\People\PersonFields::PERSON as $group => $fields)
                @continue($group === 'military' && $person->sex !== 'male')
                <x-hc.section :title="__('people.groups.'.$group)" :step="$loop->iteration" :open="$group !== 'military'">
                    <dl class="hc-dl">
                        @foreach($fields as $field => $spec)
                            @php($shown = $value($field, $spec))
                            @continue($shown === null && ! in_array($field, ['national_number', 'mobile', 'birth_date'], true))
                            <div><dt>{{ __('people.fields.'.$field) }}</dt><dd @class(['ltr num' => in_array($field, ['national_number', 'mobile', 'mobile_2', 'home_phone'], true)])>{{ $shown ?? __('hc.not_set') }}</dd></div>
                        @endforeach
                    </dl>
                </x-hc.section>
            @endforeach
            <x-hc.card :title="__('people.projects')" :flush="true">
                @if($projects->isEmpty())
                    <p class="muted" style="padding:16px 20px">{{ __('people.no_projects') }}</p>
                @else
                <div class="hc-table-wrap"><table class="hc-table stack">
                    <thead><tr><th>{{ __('hc.number') }}</th><th>{{ __('hc.project') }}</th><th>{{ __('hc.stage') }}</th><th>{{ __('people.role') }}</th><th>{{ __('hc.request_date') }}</th></tr></thead>
                    <tbody>@foreach($projects as $pr)
                        <tr><td data-label="{{ __('hc.number') }}"><a class="row-link" href="{{ route('projects.show', ['locale' => $locale, 'project' => $pr->id]) }}"><x-hc.project-number :code="$pr->code" :number="$pr->project_number"/></a></td>
                            <td data-label="{{ __('hc.project') }}">{{ $pr->name ?: __('hc.not_set') }}</td>
                            <td data-label="{{ __('hc.stage') }}"><x-hc.badge>{{ __('hc.stage_'.$pr->application_stage) }}</x-hc.badge></td>
                            <td data-label="{{ __('people.role') }}">{{ $pr->role ?: '—' }}</td>
                            <td data-label="{{ __('hc.request_date') }}" class="num">{{ $pr->request_date ? substr($pr->request_date, 0, 10) : '—' }}</td></tr>
                    @endforeach</tbody>
                </table></div>
                @endif
            </x-hc.card>
        </div>
        <div class="hc-grid">
            <livewire:attachments-panel owner="person" :owner-id="$person->id" />
            <livewire:family-panel :person-id="$person->id" />
            @foreach(array_keys(\App\Services\People\PersonFields::DETAILS) as $kind)
                <livewire:person-details :person-id="$person->id" :kind="$kind" :key="'d-'.$kind" />
            @endforeach
        </div>
    </div>
</x-layouts.hc>
