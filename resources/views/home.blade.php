<x-layouts.hc :title="__('hc.nav_home')">
    @php($en = app()->getLocale() === 'en')
    @php($locale = app()->getLocale())
    <div class="hc-page-head">
        <div>
            <h1>{{ __('desk.title') }}</h1>
            <p>{{ __('hc.home_title', ['name' => auth()->user()->username]) }} · {{ now()->translatedFormat('l j F Y') }}</p>
        </div>
        <x-hc.button :href="route('projects.index', ['locale' => $locale])" icon="folder" variant="secondary">{{ __('hc.all_projects') }}</x-hc.button>
    </div>

    {{-- X4: the three counting units for every project the account may read. --}}
    <x-hc.reach :reach="$reach" style="margin-block-end:28px"/>

    <livewire:my-desk />

    <div class="hc-section-title">
        <h2>{{ __('desk.overview') }}</h2>
        <p>{{ __('hc.home_intro') }}</p>
    </div>

    @php($groups = array_keys($byPath))
    <div class="hc-grid hc-home-charts" style="margin-block-end:20px">
        <x-hc.card :title="__('hc.home_by_path')">
            <x-hc.chart type="doughnut" :height="260" :title="__('hc.home_by_path')" :total-label="__('hc.reach_projects')"
                :labels="array_map(fn ($g) => __('hc.path_group_'.$g), $groups)"
                :datasets="[['label' => __('hc.stat_projects'), 'data' => array_values($byPath)]]"/>
        </x-hc.card>
        <x-hc.card :title="__('hc.home_by_city')">
            <x-hc.chart type="bar" :height="260" :title="__('hc.home_by_city')"
                :labels="$perCity->map(fn ($r) => $en ? $r['city']->name_en : $r['city']->name_ar)->all()"
                :datasets="[
                    ['label' => __('hc.stat_projects'), 'data' => $perCity->pluck('projects')->all()],
                    ['label' => __('hc.stat_beneficiaries'), 'data' => $perCity->pluck('beneficiaries')->all()],
                ]"/>
        </x-hc.card>
    </div>

    <div class="hc-grid hc-grid-2">
        <x-hc.card :title="__('hc.by_city')" :flush="true">
            <div class="hc-table-wrap">
            <table class="hc-table" data-smart="off">
                <thead><tr><th>{{ __('hc.city') }}</th><th class="num">{{ __('hc.stat_projects') }}</th><th class="num">{{ __('hc.stat_beneficiaries') }}</th><th class="num">{{ __('hc.stat_financed') }}</th><th>{{ __('hc.collected') }}</th></tr></thead>
                <tbody>
                @foreach($perCity as $row)
                    <tr>
                        <td><strong>{{ $en ? $row['city']->name_en : $row['city']->name_ar }}</strong> <span class="muted ltr">{{ $row['city']->code }}</span></td>
                        <td class="num">{{ number_format($row['projects']) }}</td>
                        <td class="num">{{ number_format($row['beneficiaries']) }}</td>
                        <td class="num">{{ number_format($row['financed']) }}</td>
                        <td style="min-width:140px">
                            @if($row['collected'] !== null)
                                <div style="display:flex;align-items:center;gap:8px"><div class="hc-progress" style="flex:1" role="img" aria-label="{{ $row['collected'] }}%"><span style="width: {{ $row['collected'] }}%"></span></div><span class="num">{{ $row['collected'] }}%</span></div>
                            @else <span class="muted">{{ __('hc.not_set') }}</span> @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr>
                    <td>{{ __('hc.total') }}</td>
                    <td class="num">{{ number_format($reach['projects']) }}</td>
                    <td class="num">{{ number_format($reach['beneficiaries']) }}</td>
                    <td class="num">{{ number_format($financed) }}</td>
                    <td></td>
                </tr></tfoot>
            </table>
            </div>
        </x-hc.card>

        <x-hc.card :title="__('hc.recent')" :flush="true">
            <x-slot:actions><x-hc.button variant="ghost" size="sm" :href="route('projects.index', ['locale' => $locale])">{{ __('hc.all_projects') }}</x-hc.button></x-slot:actions>
            <div class="hc-table-wrap">
            {{-- Only the latest few projects: searching them here would mislead. --}}
            <table class="hc-table" data-smart="off">
                <tbody>
                @foreach($recent as $p)
                    @php($person = $p->projectApplicants->first()?->person)
                    <tr>
                        <td><a class="row-link" href="{{ route('projects.show', ['locale' => $locale, 'project' => $p->id]) }}"><x-hc.project-number :code="$p->city->code" :number="$p->project_number"/></a></td>
                        <td>{{ $person ? $person->first_name.' '.$person->last_name : __('hc.not_set') }}<br><span class="muted" style="font-size:.85rem">{{ $p->name }}</span></td>
                        <td class="num muted">{{ $p->request_date?->format('Y-m-d') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </x-hc.card>
    </div>
</x-layouts.hc>
