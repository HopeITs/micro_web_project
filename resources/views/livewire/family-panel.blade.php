<div>
@if($home === null)
    <x-hc.card :title="__('people.family')" id="family"><p class="muted">{{ __('families.not_in_family') }}</p></x-hc.card>
@else
    @php
        $locale = app()->getLocale();
        $members = $home['members'];
        $headName = $home['head_name'];
        $number = $home['family']->family_number;
        $familyUrl = route('families.show', ['locale' => $locale, 'family' => $home['family']->id]).'#h-'.$home['head_id'];
        $totals = $home['totals'];
        // On a person's page the reminders about stored figures stay on the family page.
        $gaps = collect($home['flags'])->reject(fn ($f) => $f['tone'] === 'info')->values();
    @endphp
    <section class="hc-card fam-home" id="{{ $compact ? 'family' : 'h-'.$home['head_id'] }}">
        <header class="hc-card-head fam-home-head">
            <div>
                <h2>{{ $headName === null ? __('families.no_head_title') : __('families.family_of', ['name' => $headName]) }}</h2>
                {{-- On the family page the number is the page's heading and the size is in the totals. --}}
                @if($compact)<p class="muted" style="font-size:.85rem">{{ __('people.family_number') }}: <span class="ltr num">{{ $number }}</span> · {{ trans_choice('families.member_count', $totals['members'], ['count' => $totals['members']]) }}</p>@endif
            </div>
            <div class="fam-actions">
                @if($compact)<x-hc.button size="sm" variant="ghost" icon="users" :href="$familyUrl">{{ __('families.open') }}</x-hc.button>@endif
                @if($editable && $home['head_person'] !== null)<x-hc.button size="sm" variant="secondary" icon="plus" wire:click="openAdd">{{ __('people.add_member') }}</x-hc.button>@endif
            </div>
        </header>

        @if($notice)<div class="fam-block" wire:key="notice"><x-hc.alert type="success">{{ $notice }}</x-hc.alert></div>@endif
        @error('member')<div class="fam-block"><x-hc.alert type="danger">{{ $message }}</x-hc.alert></div>@enderror

        @if(! $compact)
        <dl class="fam-tiles">
            <div><dt>{{ __('families.members') }}</dt><dd data-total="members">{{ $totals['members'] }}</dd></div>
            <div><dt>{{ __('families.residents') }}</dt><dd data-total="residents">{{ $totals['residents'] }}</dd></div>
            <div><dt>{{ __('families.working') }}</dt><dd data-total="working">{{ $totals['working'] }}</dd></div>
            <div><dt>{{ __('families.children_under', ['age' => \App\Services\People\FamilyService::CHILD_AGE]) }}</dt><dd data-total="children">{{ $totals['children'] }}</dd></div>
            <div><dt>{{ __('families.income') }}</dt><dd data-total="income" class="ltr">{{ $totals['income'] === null ? '—' : number_format($totals['income']) }}</dd></div>
            <div><dt>{{ __('people.projects') }}</dt><dd data-total="projects">{{ $totals['projects'] }}</dd></div>
        </dl>
        @endif

        @if($compact && $gaps->isNotEmpty())
            <p class="fam-block"><a class="fam-attention-link" href="{{ $familyUrl }}"><x-hc.icon name="warning" :size="16"/>{{ __('families.attention_count', ['count' => $gaps->count()]) }}</a></p>
        @elseif(! $compact && $home['flags'] !== [])
            <details class="fam-attention" open>
                <summary><x-hc.icon name="warning" :size="18"/><span>{{ __('families.attention') }}</span><span class="num">{{ count($home['flags']) }}</span></summary>
                <ul>
                @foreach($home['flags'] as $flag)
                    <li class="is-{{ $flag['tone'] }}" wire:key="flag-{{ $flag['key'] }}" data-flag="{{ $flag['key'] }}">
                        <span>{{ __('families.flags.'.$flag['key']) }}</span>
                        @if(str_starts_with($flag['key'], 'counts'))
                            <span class="muted">{{ __('families.counts_detail', [
                                'stored_all' => $flag['stored']['family_size'] ?? '—', 'stored_home' => $flag['stored']['household_size'] ?? '—',
                                'all' => $flag['computed']['family_size'], 'home' => $flag['computed']['household_size']]) }}</span>
                            @if($editable)<x-hc.button size="sm" variant="secondary" icon="sync" wire:click="recount" wire:loading.attr="disabled">{{ __('families.recount') }}</x-hc.button>@endif
                        @endif
                        @foreach($flag['members'] as $m)
                            @if($editable)
                                <button type="button" class="hc-chip" wire:click="startEdit({{ $m->id }})">{{ $m->name }}</button>
                            @else
                                <a class="hc-chip" href="{{ route('people.show', ['locale' => $locale, 'person' => $m->person_id]) }}">{{ $m->name }}</a>
                            @endif
                        @endforeach
                    </li>
                @endforeach
                </ul>
            </details>
        @endif

        @php($tabs = $compact ? ['members'] : ['members', 'work', 'health', 'assets', 'aid', 'projects'])
        <div @if(! $compact) x-data="hcTabs('hc.family.tab', @js($tabs), 'members')" @endif>
            @if(! $compact)
            <div class="hc-tabs" role="tablist" aria-label="{{ __('families.family') }}" @keydown="keys($event)">
                @foreach($tabs as $tab)
                    @php($amount = match ($tab) { 'members' => $totals['members'], 'projects' => $totals['projects'], default => collect($records[$tab])->sum(fn ($rows) => $rows->count()) })
                    <button type="button" role="tab" id="tab-{{ $home['head_id'] }}-{{ $tab }}" data-tab="{{ $tab }}" aria-controls="panel-{{ $home['head_id'] }}-{{ $tab }}"
                            :aria-selected="tab === '{{ $tab }}'" aria-selected="{{ $tab === 'members' ? 'true' : 'false' }}" :tabindex="tab === '{{ $tab }}' ? 0 : -1" @click="select('{{ $tab }}')">{{ __('families.tabs.'.$tab) }} <span>{{ $amount }}</span></button>
                @endforeach
            </div>
            @endif

            <div @if(! $compact) role="tabpanel" id="panel-{{ $home['head_id'] }}-members" aria-labelledby="tab-{{ $home['head_id'] }}-members" x-show="tab === 'members'" @endif>
                @if($members->isEmpty())
                    <p class="muted fam-block">{{ __('people.no_family') }}</p>
                @else
                <ul @class(['fam-members', 'is-compact' => $compact])>
                    @foreach($members as $m)
                    <li wire:key="m-{{ $m->id }}" @class(['fam-member', 'is-head' => $m->is_head, 'is-self' => $m->person_id === $personId])>
                        <div class="fam-who">
                            <span class="fam-avatar" aria-hidden="true"><x-hc.icon name="user" :size="18"/></span>
                            <div>
                                @if($m->person_id === $personId)<strong>{{ $m->name }}</strong>
                                @else<a class="fam-name" href="{{ route('people.show', ['locale' => $locale, 'person' => $m->person_id]) }}">{{ $m->name }}</a>@endif
                                <span class="fam-tags">
                                    @if($m->is_head)<x-hc.badge tone="brand" icon="user">{{ __('families.head') }}</x-hc.badge>
                                    @else<span class="fam-role">{{ $m->relation ?? __('families.no_relation') }}</span>@endif
                                    @if($compact && $m->person_id === $personId)<span class="muted">{{ __('families.this_person') }}</span>@endif
                                </span>
                                @if(! $compact)
                                    <span class="fam-sub">@if($m->national_number)<span class="ltr num">{{ $m->national_number }}</span>@else{{ __('families.no_national') }}@endif</span>
                                @endif
                            </div>
                        </div>
                        <dl class="fam-facts">
                            <div><dt>{{ __('people.fields.sex') }}</dt><dd>{{ $m->sex ? __('people.'.$m->sex) : '—' }}</dd></div>
                            <div><dt>{{ __('families.age') }}</dt><dd>{{ $m->age === null ? '—' : $m->age }}@if($m->birth_year) <span class="muted num">({{ $m->birth_year }})</span>@endif</dd></div>
                            <div><dt>{{ __('people.fields.person_state_id') }}</dt><dd>{{ $m->state ?? '—' }}</dd></div>
                            @if(! $compact)
                            <div @class(['is-empty' => ! $m->marital])><dt>{{ __('people.fields.marital_status_id') }}</dt><dd>{{ $m->marital ?? '—' }}</dd></div>
                            @php($education = collect([$m->education_level, $m->education_degree, $m->education_specialty])->filter()->unique()->implode(' · '))
                            <div @class(['wide', 'is-empty' => $education === ''])><dt>{{ __('families.education') }}</dt><dd>{{ $education ?: '—' }}</dd></div>
                            <div @class(['wide', 'is-empty' => ! $m->working && $m->has_work === null && $m->income === null])><dt>{{ __('families.work') }}</dt><dd>
                                @if($m->working){{ $m->work_text !== '' ? $m->work_text : __('families.works') }}@elseif($m->has_work === false){{ __('families.not_working') }}@else—@endif
                                @if($m->income !== null)<span class="fam-income">{{ __('families.income_short') }} <span class="ltr num">{{ number_format($m->income) }}</span></span>@endif
                            </dd></div>
                            @endif
                        </dl>
                        @if($editable)
                        <div class="fam-row-actions">
                            <button type="button" class="hc-icon-btn" wire:click="startEdit({{ $m->id }})" title="{{ __('people.edit') }}" aria-label="{{ __('people.edit') }}: {{ $m->name }}"><x-hc.icon name="pencil" :size="18"/></button>
                            @if(! $m->is_head && (! $compact || $m->person_id !== $personId))
                            <button type="button" class="hc-icon-btn fam-remove" wire:click="remove({{ $m->id }})" wire:confirm="{{ __('people.confirm_remove_member') }}" title="{{ __('people.remove_member') }}" aria-label="{{ __('people.remove_member') }}: {{ $m->name }}"><x-hc.icon name="x" :size="18"/></button>
                            @endif
                        </div>
                        @endif
                    </li>
                    @endforeach
                </ul>
                @endif
            </div>

            @if(! $compact)
                @foreach(['work', 'health', 'assets', 'aid'] as $tab)
                    <div role="tabpanel" id="panel-{{ $home['head_id'] }}-{{ $tab }}" aria-labelledby="tab-{{ $home['head_id'] }}-{{ $tab }}" x-show="tab === '{{ $tab }}'" x-cloak>
                        @include('people._records', ['tab' => $tab, 'tables' => $records[$tab], 'members' => $members])
                    </div>
                @endforeach
                <div role="tabpanel" id="panel-{{ $home['head_id'] }}-projects" aria-labelledby="tab-{{ $home['head_id'] }}-projects" x-show="tab === 'projects'" x-cloak>
                    @if($home['projects']->isEmpty())
                        <p class="muted fam-block">{{ __('families.no_projects_family') }}</p>
                    @else
                    <div class="hc-table-wrap"><table class="hc-table stack" data-smart="off">
                        <thead><tr><th>{{ __('hc.number') }}</th><th>{{ __('hc.project') }}</th><th>{{ __('families.applicant') }}</th><th>{{ __('families.state') }}</th><th>{{ __('hc.request_date') }}</th></tr></thead>
                        <tbody>
                        @foreach($home['projects'] as $pr)
                            @php($who = $members->firstWhere('person_id', $pr->person_id))
                            <tr wire:key="pr-{{ $pr->id }}-{{ $pr->person_id }}">
                                <td data-label="{{ __('hc.number') }}"><a class="row-link" href="{{ route('projects.show', ['locale' => $locale, 'project' => $pr->id]) }}"><x-hc.project-number :code="$pr->code" :number="$pr->project_number"/></a></td>
                                <td data-label="{{ __('hc.project') }}">{{ $pr->name ?: __('hc.not_set') }}</td>
                                <td data-label="{{ __('families.applicant') }}">{{ $who?->name }} <span class="muted">{{ $pr->role }}</span></td>
                                <td data-label="{{ __('families.state') }}">@if($pr->state)<x-hc.badge :tone="\App\Support\ProjectStatus::tone($pr->state_en)" :icon="\App\Support\ProjectStatus::icon($pr->state_en)">{{ $pr->state }}</x-hc.badge>@else<x-hc.badge>{{ __('hc.stage_'.$pr->application_stage) }}</x-hc.badge>@endif</td>
                                <td data-label="{{ __('hc.request_date') }}" class="num">{{ $pr->request_date ? substr($pr->request_date, 0, 10) : '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                    @endif
                </div>
            @endif
        </div>
    </section>

    @if($open)
        @include('people._member-drawer')
    @endif
@endif
</div>
