<x-layouts.hc :title="__('hc.nav_home')">
    @php($en = app()->getLocale() === 'en')
    <div class="hc-page-head">
        <div>
            <p class="hc-eyebrow">{{ __('hc.home_eyebrow') }} · {{ now()->translatedFormat('j F Y') }}</p>
            <h1>{{ __('desk.title') }}</h1>
            <p>{{ __('hc.home_title', ['name' => auth()->user()->username]) }} · {{ __('desk.intro') }}</p>
        </div>
        <x-hc.button :href="route('projects.index', ['locale' => app()->getLocale()])" icon="folder" variant="secondary">{{ __('hc.all_projects') }}</x-hc.button>
    </div>

    <livewire:my-desk />

    <h2 style="font-size:1.05rem;margin:8px 0 10px">{{ __('desk.overview') }}</h2>
    <p class="muted" style="margin-block-end:10px">{{ __('hc.home_intro') }}</p>
    <div class="hc-grid hc-grid-stats" style="margin-block-end:20px">
        <x-hc.stat :label="__('hc.stat_projects')" :value="number_format($total)"/>
        <x-hc.stat :label="__('hc.stat_financed')" :value="number_format($financed)" accent="var(--ok)"/>
        <x-hc.stat :label="__('hc.stat_review')" :value="number_format($review)" accent="var(--warn)"/>
        <x-hc.stat :label="__('hc.stat_preliminary')" :value="number_format($preliminary)" accent="var(--info)"/>
        <x-hc.stat :label="__('hc.stat_due')" :value="number_format($due)" accent="var(--danger)"/>
    </div>

    <div class="hc-grid hc-grid-2">
        <x-hc.card :title="__('hc.by_city')" :flush="true">
            <div class="hc-table-wrap">
            <table class="hc-table">
                <thead><tr><th>{{ __('hc.city') }}</th><th class="num">{{ __('hc.stat_projects') }}</th><th class="num">{{ __('hc.stat_financed') }}</th><th>{{ __('hc.collected') }}</th></tr></thead>
                <tbody>
                @foreach($perCity as $row)
                    <tr>
                        <td><strong>{{ $en ? $row['city']->name_en : $row['city']->name_ar }}</strong> <span class="muted ltr">{{ $row['city']->code }}</span></td>
                        <td class="num">{{ number_format($row['projects']) }}</td>
                        <td class="num">{{ number_format($row['financed']) }}</td>
                        <td style="min-width:140px">
                            @if($row['collected'] !== null)
                                <div style="display:flex;align-items:center;gap:8px"><div class="hc-progress" style="flex:1" role="img" aria-label="{{ $row['collected'] }}%"><span style="width: {{ $row['collected'] }}%"></span></div><span class="num">{{ $row['collected'] }}%</span></div>
                            @else <span class="muted">{{ __('hc.not_set') }}</span> @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </x-hc.card>

        <x-hc.card :title="__('hc.recent')" :flush="true">
            <x-slot:actions><x-hc.button variant="ghost" size="sm" :href="route('projects.index', ['locale' => app()->getLocale()])">{{ __('hc.all_projects') }}</x-hc.button></x-slot:actions>
            <div class="hc-table-wrap">
            {{-- Only the latest few projects: searching them here would mislead. --}}
            <table class="hc-table" data-smart="off">
                <tbody>
                @foreach($recent as $p)
                    @php($person = $p->projectApplicants->first()?->person)
                    <tr>
                        <td><a class="row-link" href="{{ route('projects.show', ['locale' => app()->getLocale(), 'project' => $p->id]) }}"><x-hc.project-number :code="$p->city->code" :number="$p->project_number"/></a></td>
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
