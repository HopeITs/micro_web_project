<div x-data x-on:form-errors.window="$nextTick(() => document.querySelector('.hc-error, .hc-alert-danger')?.scrollIntoView({block: 'center', behavior: 'smooth'}))">
    @php
        $en = app()->getLocale() === 'en';
        $locale = app()->getLocale();
        $sectionGrids = fn (string $section) => array_values(array_filter($grids, fn ($k) => \App\Services\Applications\ApplicationFields::GRIDS[$k]['section'] === $section));
        $step = 0;
    @endphp
    <form wire:submit="save">
    <div class="hc-grid">
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

        {{-- 1. Beneficiary --}}
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

            @foreach($personGroups as $group => $fields)
                @continue($group === 'military' && ($form['person']['sex'] ?? '') !== 'male')
                @continue($fields === [])
                <h3 class="hc-label" style="margin-block:12px 8px">{{ __('people.groups.'.$group) }}</h3>
                <div class="hc-form-grid">
                @foreach($fields as $field => $spec)
                    <x-hc.field :label="__('people.fields.'.$field)" :for="'ap-'.$field" :required="$spec['required'] ?? false" :error="$errors->first('form.person.'.$field)">
                        @include('people._input', ['model' => 'form.person.'.$field, 'id' => 'ap-'.$field, 'options' => $options[$field] ?? collect(), 'live' => in_array($field, ['first_name', 'last_name', 'father_name', 'national_number'], true)])
                    </x-hc.field>
                @endforeach
                @if($group === 'identity')
                    <x-hc.field :label="__('people.family_number')" for="a-family" :error="$errors->first('family_number')" :hint="$form['person_id'] ? __('applications.family_from_person') : __('people.family_number_hint')">
                        <input class="hc-input ltr" id="a-family" wire:model.live.debounce.400ms="form.family_number" maxlength="50" autocomplete="off" @disabled($form['person_id'] !== null && $form['family_number'] !== '')>
                    </x-hc.field>
                @endif
                </div>
            @endforeach
        </x-hc.section>

        {{-- 2. Project --}}
        @foreach($projectGroups as $group => $fields)
            @continue($fields === [])
            @php($step++)
            <x-hc.section :title="__('applications.sections.'.$group)" :step="$step">
                <div class="hc-form-grid">
                @foreach($fields as $field => $spec)
                    <x-hc.field :label="__('applications.fields.'.$field)" :for="'pr-'.$field" :required="($spec['required'] ?? false) || ($stage === 'full' && in_array($field, ['fund_type_id', 'requested_amount'], true))" :error="$errors->first('form.project.'.$field)">
                        @if($field === 'project_subcategory_id')
                            <select class="hc-select" id="pr-{{ $field }}" wire:model="form.project.{{ $field }}"><option value="">{{ __('people.choose') }}</option>
                                @foreach($subcategoryGroups as $category => $subs)<optgroup label="{{ $category }}">@foreach($subs as $s)<option value="{{ $s->id }}">{{ $en && $s->name_en ? $s->name_en : $s->name_ar }}</option>@endforeach</optgroup>@endforeach
                            </select>
                        @elseif($field === 'project_type_id')
                            <select class="hc-select" id="pr-{{ $field }}" wire:model.live="form.project.{{ $field }}"><option value="">{{ __('people.choose') }}</option>@foreach($options[$field] as $o)<option value="{{ $o->id }}">{{ $o->label }}</option>@endforeach</select>
                        @else
                            @include('people._input', ['model' => 'form.project.'.$field, 'id' => 'pr-'.$field, 'options' => $options[$field] ?? collect()])
                        @endif
                    </x-hc.field>
                @endforeach
                </div>
                @foreach($sectionGrids($group) as $kind)
                    @include('applications._grid', ['kind' => $kind, 'options' => $gridOptions[$kind]])
                @endforeach
            </x-hc.section>
        @endforeach

        {{-- 3. The beneficiary's story, work, family and support, each with its grids --}}
        @foreach($applicantGroups as $group => $fields)
            @php($step++)
            <x-hc.section :title="__('applications.sections.'.$group)" :step="$step">
                @if($fields !== [])
                <div class="hc-form-grid">
                @foreach($fields as $field => $spec)
                    <x-hc.field :label="__('applications.fields.'.$field)" :for="'af-'.$field" :error="$errors->first('form.applicant.'.$field)">
                        @include('people._input', ['model' => 'form.applicant.'.$field, 'id' => 'af-'.$field, 'options' => collect()])
                    </x-hc.field>
                @endforeach
                </div>
                @endif
                @foreach($sectionGrids($group) as $kind)
                    @include('applications._grid', ['kind' => $kind, 'options' => $gridOptions[$kind]])
                @endforeach
            </x-hc.section>
        @endforeach
    </div>

    <x-hc.action-bar>
        <a class="hc-btn hc-btn-ghost" href="{{ $projectId ? route('projects.show', ['locale' => $locale, 'project' => $projectId]) : route('projects.index', ['locale' => $locale]) }}">{{ __('people.cancel') }}</a>
        <span class="spacer"></span>
        <span wire:loading class="muted">{{ __('people.saving') }}</span>
        @if($stage === 'full')<x-hc.button variant="secondary" icon="check" wire:click="checkReadiness">{{ __('applications.check_ready') }}</x-hc.button>@endif
        <x-hc.button type="submit" icon="check" wire:loading.attr="disabled">{{ $projectId ? __('applications.save') : __('applications.save_new') }}</x-hc.button>
    </x-hc.action-bar>
    </form>
</div>
