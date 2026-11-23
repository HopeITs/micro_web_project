<div wire:poll.20s="autosave" x-data x-on:form-errors.window="$nextTick(() => document.querySelector('.hc-error, .hc-alert-danger')?.scrollIntoView({block: 'center', behavior: 'smooth'}))">
    @php
        $en = app()->getLocale() === 'en';
        $locale = app()->getLocale();
        $sectionGrids = fn (string $section) => array_values(array_filter($grids, fn ($k) => \App\Services\Applications\ApplicationFields::GRIDS[$k]['section'] === $section));
        $step = 0;
        $switches = \App\Services\Applications\ApplicationFields::SWITCHES;
        $gridSwitches = \App\Services\Applications\ApplicationFields::gridSwitches();
        $governedFields = collect($switches)->pluck('fields')->flatten()->filter()->all();
        $parentFields = collect(\App\Services\Applications\ApplicationFields::flat(\App\Services\Applications\ApplicationFields::PROJECT, 'full'))->pluck('parent')->filter()->unique()->all();
    @endphp
    <form wire:submit="save">
    <div class="hc-grid">
        @if($pendingDraftAt !== '')
            <x-hc.alert type="info" :title="__('applications.drafts.pending_title')">
                {{ __('applications.drafts.pending', ['time' => $pendingDraftAt]) }}
                <span style="display:flex;gap:8px;margin-block-start:8px"><x-hc.button size="sm" wire:click="restoreDraft">{{ __('applications.drafts.restore') }}</x-hc.button><x-hc.button size="sm" variant="ghost" wire:click="discardDraft">{{ __('applications.drafts.discard') }}</x-hc.button></span>
            </x-hc.alert>
        @endif
        {{-- City, stage and project number --}}
        <x-hc.card>
            <div class="hc-form-grid" style="align-items:end">
                @if($cities->isNotEmpty())
                <x-hc.field :label="__('hc.city')" for="a-city" :required="true">
                    <select class="hc-select" id="a-city" wire:model.live="form.city">@foreach($cities as $c)<option value="{{ $c->id }}">{{ $en ? $c->name_en : $c->name_ar }}</option>@endforeach</select>
                </x-hc.field>
                @else
                <x-hc.field :label="__('hc.city')"><p style="padding-block:10px">{{ $en ? $city->name_en : $city->name_ar }}</p></x-hc.field>
                @endif
                <x-hc.field :label="__('applications.number')" for="a-number" :error="$errors->first('number')" :hint="$suggested ? __('applications.number_hint', ['number' => $suggested]) : __('applications.number_edit_hint')">
                    <div dir="ltr" style="display:flex;align-items:center;gap:6px;justify-content:flex-end"><span class="muted">{{ $city->code }}-</span><input class="hc-input ltr" id="a-number" wire:model="form.number" inputmode="numeric" dir="ltr" maxlength="10" style="max-width:140px"></div>
                </x-hc.field>
                <div class="hc-field">
                    <span class="hc-label">{{ __('applications.stage') }}</span>
                    <div class="hc-chips" role="group" aria-label="{{ __('applications.stage') }}">
                        <button type="button" class="hc-chip" wire:click="setStage('preliminary')" aria-pressed="{{ $stage === 'preliminary' ? 'true' : 'false' }}" @disabled($savedStage === 'full')>{{ __('hc.stage_preliminary') }}</button>
                        <button type="button" class="hc-chip" wire:click="setStage('full')" aria-pressed="{{ $stage === 'full' ? 'true' : 'false' }}">{{ __('hc.stage_full') }}</button>
                    </div>
                    <p class="hc-hint">{{ $stage === 'full' ? ($savedStage === 'full' ? __('applications.stage_full_saved') : __('applications.stage_full_hint')) : __('applications.stage_preliminary_hint') }}</p>
                </div>
            </div>
        </x-hc.card>

        @if($readinessIssues !== [] || ($readinessChecked && $stage === 'full'))
            @if($readinessIssues === [])
                <x-hc.alert type="success">{{ __('applications.ready') }}</x-hc.alert>
            @else
                <x-hc.alert type="danger" :title="__('fullapp.title').' ('.count($readinessIssues).')'">
                    <span style="display:block;max-height:220px;overflow:auto">@foreach($readinessIssues as $issue)• {{ $issue }}<br>@endforeach</span>
                </x-hc.alert>
            @endif
        @endif
        @if($errors->any() && ! $errors->has('confirm'))<x-hc.alert type="danger">{{ __('applications.errors.fix', ['count' => count($errors->all())]) }}</x-hc.alert>@endif

        {{-- Sections in the order of the desktop Application_New_Form (Main_panel top to bottom): the project block, the story (full only),
             the applicant's personal information, work, project premises (full only), family, support, and the profit description. --}}
        @php
            $blocks = [['project', 'project'], ['applicant', 'story'], ['person', null], ['applicant', 'work'], ['project', 'premises'], ['applicant', 'family'], ['applicant', 'aid'], ['project', 'extra']];
        @endphp
        @foreach($blocks as [$type, $group])
        @if($type === 'person')
        {{-- Beneficiary --}}
        @php($step++)
        <x-hc.section :title="__('applications.sections.beneficiary')" :step="$step">
            @if($form['person_id'] === null && $projectId === null)
                <x-hc.field :label="__('applications.pick_beneficiary')" for="a-pick" :hint="__('applications.pick_hint')">
                    <input class="hc-input" id="a-pick" type="search" wire:model.live.debounce.300ms="personSearch" placeholder="{{ __('people.pick_placeholder') }}" autocomplete="off">
                </x-hc.field>
                @if($picks->isNotEmpty())
                <div class="hc-chips" style="margin-block:8px 16px">@foreach($picks as $p)<button type="button" class="hc-chip" wire:click="pickPerson({{ $p->id }})">{{ $p->name }} @if($p->national_number)<span class="muted ltr num">{{ $p->national_number }}</span>@endif</button>@endforeach</div>
                @endif
            @elseif($form['person_id'] !== null)
                <p style="margin-block-end:12px"><x-hc.badge tone="brand">{{ __('applications.registered_person') }}</x-hc.badge>
                    <a class="row-link" href="{{ route('people.show', ['locale' => $locale, 'person' => $form['person_id']]) }}" target="_blank">{{ __('applications.open_person') }}</a>
                    @if($projectId === null)<x-hc.button size="sm" variant="ghost" icon="x" wire:click="newPerson">{{ __('applications.other_person') }}</x-hc.button>@endif</p>
            @endif

            @if($duplicates || $holders->isNotEmpty())
            <x-hc.alert type="warning" :title="__('people.duplicates_title')" style="margin-block-end:12px">
                @foreach($duplicates as $d)
                    <a class="row-link" href="{{ route('people.show', ['locale' => $locale, 'person' => $d->id]) }}" target="_blank">{{ $d->name }}</a> — {{ $d->city }} · {{ __('people.dup_'.$d->reason) }}@if($d->projects) · <span class="ltr">{{ implode('، ', $d->projects) }}</span>@endif
                    @if($form['person_id'] === null && $d->city === ($en ? $city->name_en : $city->name_ar))<x-hc.button size="sm" variant="ghost" wire:click="pickPerson({{ $d->id }})">{{ __('applications.use_this_person') }}</x-hc.button>@endif<br>
                @endforeach
                @foreach($holders as $h)<a class="row-link" href="{{ route('people.show', ['locale' => $locale, 'person' => $h->id]) }}" target="_blank">{{ $h->name }}</a> — {{ __('people.dup_family') }}<br>@endforeach
                <span class="hc-choice" style="margin-block-start:8px"><label><input type="checkbox" wire:model.live="confirmed">{{ __('people.not_same_person') }}</label></span>
            </x-hc.alert>
            @endif
            @error('confirm')<x-hc.alert type="danger" style="margin-block-end:12px">{{ $message }}</x-hc.alert>@enderror

            @foreach($personGroups as $pgroup => $fields)
                @continue($pgroup === 'military' && ($form['person']['sex'] ?? '') !== 'male')
                @continue($fields === [])
                <h3 class="hc-label" style="margin-block:12px 8px">{{ __('people.groups.'.$pgroup) }}</h3>
                <div class="hc-form-grid">
                @foreach($fields as $field => $spec)
                    <x-hc.field :label="__('people.fields.'.$field)" :for="'ap-'.$field" :required="$spec['required'] ?? false" :error="$errors->first('form.person.'.$field)" :hint="$field === 'birth_date' ? __('forms.year_hint') : null">
                        @if($field === 'birth_date')
                            {{-- The desktop takes the year only (P_DOB_textBox, MaxLength 4). --}}
                            <input class="hc-input ltr" id="ap-birth_date" type="text" inputmode="numeric" dir="ltr" maxlength="10" placeholder="YYYY" wire:model="form.person.birth_date" autocomplete="off">
                        @else
                            @include('people._input', ['model' => 'form.person.'.$field, 'id' => 'ap-'.$field, 'options' => $personOptions[$field] ?? collect(), 'live' => in_array($field, ['first_name', 'last_name', 'father_name', 'national_number'], true)])
                        @endif
                    </x-hc.field>
                @endforeach
                </div>
            @endforeach
        </x-hc.section>

        @elseif($type === 'project')
        {{-- Project groups --}}
        @php($fields = $projectGroups[$group] ?? [])
        @continue($fields === [])
        @php($step++)
        @if($group === 'premises')
        {{-- Project-specific information (desktop t20): premises, project power sources, capacity, approvals, then the proposed budget. --}}
        <x-hc.section :title="__('applications.sections.premises')" :step="$step">
            <div class="hc-form-grid">
            @foreach(array_intersect_key($fields, array_flip(['place_type_id', 'place_address', 'place_note'])) as $field => $spec)
                <x-hc.field :label="__('applications.fields.'.$field)" :for="'pr-'.$field" :error="$errors->first('form.project.'.$field)">
                    @include('people._input', ['model' => 'form.project.'.$field, 'id' => 'pr-'.$field, 'options' => $options[$field] ?? collect()])
                </x-hc.field>
            @endforeach
            </div>
            @if(in_array('project_power_sources', $grids, true))
                @include('applications._grid', ['kind' => 'project_power_sources', 'options' => $gridOptions['project_power_sources']])
            @endif
            <div class="hc-field" style="margin-block:16px 8px">
                <span class="hc-label">{{ __('applications.fields.amper_number') }}</span>
                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 20px">
                    <input class="hc-input ltr" style="max-width:140px" type="text" inputmode="numeric" dir="ltr" id="pr-amper_number" wire:model="form.project.amper_number" aria-label="{{ __('applications.fields.amper_number') }}">
                    <div class="hc-choice" role="radiogroup" aria-label="{{ __('applications.fields.amper_type') }}">
                        @foreach(['1', '0'] as $unit)<label><input type="radio" value="{{ $unit }}" wire:model="form.project.amper_type">{{ __('applications.amper_units.'.$unit) }}</label>@endforeach
                    </div>
                </div>
                @error('form.project.amper_number')<p class="hc-error">{{ $message }}</p>@enderror @error('form.project.amper_type')<p class="hc-error">{{ $message }}</p>@enderror
            </div>
            <h3 class="hc-label" style="margin-block:16px 8px">{{ __('applications.approvals') }}</h3>
            @foreach(['agreement_1', 'agreement_2', 'agreement_3', 'agreement_4', 'agreement_5'] as $agreement)
                <div class="hc-question" style="display:flex;flex-wrap:wrap;align-items:center;gap:6px 16px;padding-block:6px;border-block-end:1px solid var(--line)">
                    <span style="flex:1;min-width:240px">{{ __('applications.fields.'.$agreement) }}</span>
                    <div class="hc-choice" role="radiogroup" aria-label="{{ __('applications.fields.'.$agreement) }}">
                        <label><input type="radio" value="1" wire:model="form.project.{{ $agreement }}">{{ __('people.yes') }}</label>
                        <label><input type="radio" value="0" wire:model="form.project.{{ $agreement }}">{{ __('people.no') }}</label>
                    </div>
                </div>
            @endforeach
            @if(in_array('materials', $grids, true))
                <?php
                    $budgetTotal = '0'; $budgetLocal = '0';
                    $num = fn ($v) => is_numeric(str_replace(',', '', (string) $v)) ? str_replace(',', '', (string) $v) : '0';
                    foreach ($form['grids']['materials'] ?? [] as $m) {
                        $budgetTotal = bcadd($budgetTotal, bcmul($num($m['quantity'] ?? ''), $num($m['price'] ?? ''), 4), 4);
                        $budgetLocal = bcadd($budgetLocal, $num($m['local_contribution'] ?? ''), 4);
                    }
                    $money = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
                ?>
                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;margin-block:16px 0">
                    @if($canAttachBudget)<label class="hc-btn hc-btn-secondary" style="cursor:pointer;margin:0"><x-hc.icon name="plus" :size="16"/> {{ __('applications.budget.attach') }}<input type="file" wire:model="budgetFile" style="display:none" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls,.doc,.docx"></label>@endif
                    <span wire:loading wire:target="budgetFile" class="muted">…</span>
                    @if($budgetFile)<span>{{ __('applications.budget.pending', ['name' => $budgetFile->getClientOriginalName()]) }}</span>@endif
                    @if($budgetAttachments !== [])
                        <x-hc.badge tone="brand">{{ __('applications.budget.attached') }}</x-hc.badge>
                        @foreach($budgetAttachments as $att)<a class="row-link" href="{{ route('attachments.file', ['locale' => $locale, 'attachment' => $att->id]) }}" target="_blank">{{ __('applications.budget.view') }}: {{ $att->original_name }}</a>@endforeach
                    @endif
                    @error('budgetFile')<span class="hc-error">{{ $message }}</span>@enderror
                </div>
                <p class="hc-hint" style="margin:4px 0 0">{{ __('applications.budget.hint') }}</p>
                @include('applications._grid', ['kind' => 'materials', 'options' => $gridOptions['materials']])
                <div class="hc-card" style="padding:12px 16px;margin-block-start:8px;display:flex;flex-wrap:wrap;gap:6px 28px">
                    <span>{{ __('applications.budget.total') }}: <strong class="num">{{ $money($budgetTotal) }}</strong></span>
                    <span>{{ __('applications.budget.local') }}: <strong class="num">{{ $money($budgetLocal) }}</strong></span>
                    <span>{{ __('applications.budget.remaining') }}: <strong class="num">{{ $money(bcsub($budgetTotal, $budgetLocal, 4)) }}</strong></span>
                </div>
            @endif
        </x-hc.section>
        @else
        <x-hc.section :title="__('applications.sections.'.$group)" :step="$step">
            <div class="hc-form-grid">
            @foreach($fields as $field => $spec)
                <x-hc.field :label="__('applications.fields.'.$field)" :for="'pr-'.$field" :required="($spec['required'] ?? false) || ($stage === 'full' && in_array($field, ['fund_type_id', 'requested_amount'], true))" :error="$errors->first('form.project.'.$field)"
                    :hint="isset($spec['parent']) && ($form['project'][$spec['parent']] ?? '') !== '' ? __('applications.filtered_by', ['parent' => __('applications.fields.'.$spec['parent'])]) : null">
                    @include('people._input', ['model' => 'form.project.'.$field, 'id' => 'pr-'.$field, 'options' => $options[$field] ?? collect(), 'parents' => in_array($field, $parentFields, true)])
                </x-hc.field>
            @endforeach
            </div>
            @foreach($sectionGrids($group) as $kind)
                @include('applications._grid', ['kind' => $kind, 'options' => $gridOptions[$kind]])
            @endforeach
        </x-hc.section>

        @endif
        @else
        {{-- The beneficiary's story, work, family and support, each with its grids --}}
        @php($fields = $applicantGroups[$group] ?? [])
        @continue($fields === [] && $sectionGrids($group) === [] && $group !== 'family')
        @php($step++)
        <x-hc.section :title="__('applications.sections.'.$group)" :step="$step">
            {{-- Desktop family block (t21): the family number comes first, then "lives with family" and the family table. --}}
            @if($group === 'family')
            <div class="hc-form-grid" style="margin-block-end:12px">
                <x-hc.field :label="__('people.family_number')" for="a-family" :error="$errors->first('family_number')" :hint="$form['person_id'] ? __('applications.family_from_person') : __('people.family_number_hint')">
                    <input class="hc-input ltr" id="a-family" wire:model.live.debounce.400ms="form.family_number" maxlength="50" autocomplete="off" @disabled($form['person_id'] !== null && $form['family_number'] !== '')>
                </x-hc.field>
            </div>
            @endif
            {{-- Plain fields of the section (fields governed by a question appear under that question instead). --}}
            @php($plain = array_diff_key($fields, $switches, array_flip($governedFields)))
            {{-- Story (desktop t2): story, goal, the goal checkboxes, how it is reached, applicant notes. --}}
            @php($chunks = $group === 'story' ? [array_intersect_key($plain, array_flip(['loss_story', 'goal'])), 'goals', array_diff_key($plain, array_flip(['loss_story', 'goal']))] : [$plain])
            @foreach($chunks as $chunk)
            @if($chunk === 'goals')
                @if(in_array('goals', $grids, true))
                @php($chosen = collect($form['grids']['goals'] ?? [])->pluck('goal_id')->map(fn ($g) => (string) $g)->all())
                <div class="hc-field" style="margin-block:12px">
                    <span class="hc-label">{{ __('applications.grids.goals') }} <span class="muted">({{ count($chosen) }})</span></span>
                    <div class="hc-choice" role="group" aria-label="{{ __('applications.grids.goals') }}" style="display:flex;flex-wrap:wrap;gap:8px 20px">
                        @foreach($goalOptions as $g)
                            <label style="min-width:220px"><input type="checkbox" wire:key="goal-{{ $g->id }}" wire:click="toggleGoal({{ $g->id }})" @checked(in_array((string) $g->id, $chosen, true))>{{ $g->label }}</label>
                        @endforeach
                    </div>
                </div>
                @endif
            @elseif($chunk !== [] && $group !== 'work')
            <div class="hc-form-grid" @if($group === 'story') style="grid-template-columns:1fr" @endif>
            @foreach($chunk as $field => $spec)
                <x-hc.field :label="__('applications.fields.'.$field)" :for="'af-'.$field" :error="$errors->first('form.applicant.'.$field)">
                    @include('people._input', ['model' => 'form.applicant.'.$field, 'id' => 'af-'.$field, 'options' => collect()])
                </x-hc.field>
            @endforeach
            </div>
            @endif
            @endforeach

            {{-- Desktop family block: the family table comes before the loss, illness and property questions. --}}
            @if($group === 'family')
                @foreach(array_diff($sectionGrids($group), array_keys($gridSwitches)) as $kind)
                    @include('applications._grid', ['kind' => $kind, 'options' => $gridOptions[$kind]])
                    @if($kind === 'family')
                    <div class="hc-card" style="padding:12px 16px;margin-block:8px" aria-label="{{ __('applications.family_stats.title') }}">
                        <strong>{{ __('applications.family_stats.title') }}</strong>
                        <div style="display:flex;flex-wrap:wrap;gap:6px 24px;margin-block-start:6px">
                            @foreach(['total', 'active', 'inactive', 'working', 'not_working', 'studying', 'not_studying'] as $stat)
                                <span>{{ __('applications.family_stats.'.$stat) }}: <strong class="num">{{ $familyStats[$stat] }}</strong></span>
                            @endforeach
                        </div>
                        <p class="hc-hint" style="margin:6px 0 0">{{ __('applications.family_stats.hint') }}</p>
                    </div>
                    @endif
                @endforeach
            @endif

            {{-- Each yes/no question with what it opens, right under it. --}}
            @foreach(array_intersect_key($switches, $fields) as $switch => $rule)
                @php($yes = ($form['applicant'][$switch] ?? '') === '1')
                <div class="hc-question" wire:key="q-{{ $switch }}" style="border:1px solid var(--line);border-radius:var(--radius);padding:12px 16px;margin-block-start:12px;{{ $yes ? 'border-color:var(--brand-soft, var(--line))' : '' }}">
                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px">
                        <label class="hc-label" for="af-{{ $switch }}" style="margin:0;flex:1;min-width:180px">{{ __('applications.fields.'.$switch) }}</label>
                        <div class="hc-choice" role="radiogroup" aria-label="{{ __('applications.fields.'.$switch) }}">
                            <label><input type="radio" id="af-{{ $switch }}" value="1" wire:model.live="form.applicant.{{ $switch }}">{{ __('people.yes') }}</label>
                            <label><input type="radio" value="0" wire:model.live="form.applicant.{{ $switch }}">{{ __('people.no') }}</label>
                        </div>
                    </div>
                    @error('form.applicant.'.$switch)<p class="hc-error">{{ $message }}</p>@enderror
                    @if(isset($undo[$switch]))
                        <x-hc.alert type="info" style="margin-block-start:8px">{{ __('applications.gate.taken', ['count' => count($undo[$switch]['rows']) + count($undo[$switch]['fields'])]) }}
                            <x-hc.button size="sm" variant="ghost" wire:click="undoSwitch('{{ $switch }}')">{{ __('applications.gate.undo') }}</x-hc.button></x-hc.alert>
                    @endif
                    @if($yes && ($rule['fields'] ?? []) !== [])
                        <div class="hc-form-grid" style="margin-block-start:12px">
                        @foreach(array_intersect_key($fields, array_flip($rule['fields'])) as $field => $spec)
                            <x-hc.field :label="__('applications.fields.'.$field)" :for="'af-'.$field" :error="$errors->first('form.applicant.'.$field)">
                                @include('people._input', ['model' => 'form.applicant.'.$field, 'id' => 'af-'.$field, 'options' => collect()])
                            </x-hc.field>
                        @endforeach
                        </div>
                    @endif
                    @if(isset($rule['grid']) && in_array($rule['grid'], $grids, true))
                        @if($yes || isset($rule['only']))
                            @include('applications._grid', ['kind' => $rule['grid'], 'options' => $gridOptions[$rule['grid']], 'previousOnly' => ! $yes && isset($rule['only'])])
                        @else
                            <p class="hc-hint" style="margin-block-start:8px">{{ __('applications.gate.closed') }}</p>
                        @endif
                    @endif
                </div>
                {{-- Desktop work block: "works now", its table, then the years of experience, then education and courses. --}}
                @if($plain !== [] && $group === 'work' && $switch === 'has_work')
                <div class="hc-form-grid">
                @foreach($plain as $field => $spec)
                    <x-hc.field :label="__('applications.fields.'.$field)" :for="'af-'.$field" :error="$errors->first('form.applicant.'.$field)">
                        @include('people._input', ['model' => 'form.applicant.'.$field, 'id' => 'af-'.$field, 'options' => collect()])
                    </x-hc.field>
                @endforeach
                </div>
                @endif
            @endforeach

            {{-- Tables of the section that no question governs. --}}
            @foreach($group === 'family' ? [] : array_diff($sectionGrids($group), array_keys($gridSwitches), ['goals']) as $kind)
                @include('applications._grid', ['kind' => $kind, 'options' => $gridOptions[$kind]])
            @endforeach
        </x-hc.section>
        @endif
        @endforeach

    </div>

    <x-hc.action-bar>
        <a class="hc-btn hc-btn-ghost" href="{{ $projectId ? route('projects.show', ['locale' => $locale, 'project' => $projectId]) : route('projects.index', ['locale' => $locale]) }}">{{ __('people.cancel') }}</a>
        <span class="spacer"></span>
        @if($draftSavedAt !== '')<span class="muted" style="font-size:.85rem" title="{{ __('applications.drafts.autosave_hint') }}"><x-hc.icon name="check" :size="14"/> {{ __('applications.drafts.saved_at', ['time' => $draftSavedAt]) }}</span>@endif
        <span wire:loading wire:target="save" class="muted">{{ __('people.saving') }}</span>
        @if($stage === 'full')<x-hc.button variant="secondary" icon="check" wire:click="checkReadiness">{{ __('applications.check_ready') }}</x-hc.button>@endif
        <x-hc.button type="submit" icon="check" wire:loading.attr="disabled">{{ $projectId ? __('applications.save') : __('applications.save_new') }}</x-hc.button>
    </x-hc.action-bar>
    </form>
</div>
