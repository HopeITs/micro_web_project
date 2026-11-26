<div x-data x-on:form-errors.window="$nextTick(() => document.querySelector('.hc-error')?.scrollIntoView({block: 'center', behavior: 'smooth'}))">
    @php
        $fields = \App\Services\Assessments\ChecklistService::fields();
        $h = $form['header'];
        $opts = fn (string $field) => match ($fields[$field]['kind']) {
            'member' => ($fields[$field]['members'] ?? null) === null ? $allMembers : $memberOptions[$fields[$field]['members']],
            'account' => $senders,
            default => collect(),
        };
        // One field, rendered with the shared inputs (members and accounts are long, searchable lists).
        $input = function (string $field) use ($fields, $opts) {
            $spec = $fields[$field];
            return ['model' => 'form.header.'.$field, 'id' => 'cl-'.$field, 'field' => $field, 'options' => $opts($field),
                'spec' => in_array($spec['kind'], ['member', 'account'], true) ? ['kind' => 'lookup'] : $spec];
        };
        // The yes/no blocks: a question with the fields it opens right under it.
        $blocks = [
            'visits' => ['has_home_visit', 'has_project_visit'],
            'experience' => ['needs_professional_test', 'has_professional_test', 'has_professional_training', 'has_management_training'],
            'decision' => ['rejection_message'],
        ];
        $opened = fn (string $switch) => array_keys(array_filter($fields, fn ($s) => ($s['when'] ?? null) === $switch));
    @endphp

    @if($applicants->count() > 1)
        <nav class="hc-chips" aria-label="{{ __('checklists.applicants') }}" style="margin-block-end:16px">
            <span class="hc-label" style="margin:0;align-self:center">{{ __('checklists.applicants') }}</span>
            @foreach($applicants as $applicant)
                <a class="hc-btn hc-btn-sm {{ $applicant->id === $personId ? 'hc-btn-primary' : 'hc-btn-secondary' }}" @if($applicant->id === $personId) aria-current="true" @endif
                    href="{{ route('checklists.show', ['locale' => app()->getLocale(), 'project' => $projectId, 'person' => $applicant->id]) }}">
                    {{ $applicant->name }}@if($applicant->role) · {{ $applicant->role }}@endif · {{ __($applicant->has_checklist ? 'checklists.has_checklist' : 'checklists.no_checklist') }}
                </a>
            @endforeach
        </nav>
    @endif
    @if($message !== '')<x-hc.alert type="success" style="margin-block-end:16px">{{ $message }}</x-hc.alert>@endif
    @if($errors->any())<x-hc.alert type="danger" style="margin-block-end:16px">{{ __('applications.errors.fix', ['count' => count($errors->all())]) }}</x-hc.alert>@endif
    @unless($editable)<x-hc.alert type="info" style="margin-block-end:16px">{{ __('checklists.read_only') }}</x-hc.alert>@endunless
    @if(! $form['person_id'])<x-hc.alert type="warning" style="margin-block-end:16px">{{ __('checklists.errors.no_beneficiary') }}</x-hc.alert>@endif

    <form wire:submit="save">
    <fieldset @disabled(! $editable) style="border:0;padding:0;margin:0;min-width:0">
    <div class="hc-grid">
        {{-- Key persons, then the visits --}}
        @foreach(['k' => 'key_people'] as $location => $title)
            @include('checklists._members', ['location' => $location, 'title' => __('checklists.lists.'.$title), 'step' => 1])
        @endforeach

        @foreach(['visits' => 2, 'experience' => 3] as $section => $step)
        <x-hc.section :title="__('checklists.sections.'.$section)" :step="$step">
            @foreach($blocks[$section] as $switch)
                @php($yes = ($h[$switch] ?? '') === '1')
                <div class="hc-question" wire:key="cq-{{ $switch }}" style="border:1px solid var(--line);border-radius:var(--radius);padding:12px 16px;margin-block-end:12px">
                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px">
                        <span class="hc-label" style="margin:0;flex:1;min-width:180px">{{ __('checklists.fields.'.$switch) }}</span>
                        <div class="hc-choice" role="radiogroup" aria-label="{{ __('checklists.fields.'.$switch) }}">
                            <label><input type="radio" value="1" wire:model.live="form.header.{{ $switch }}">{{ __('people.yes') }}</label>
                            <label><input type="radio" value="0" wire:model.live="form.header.{{ $switch }}">{{ __('people.no') }}</label>
                        </div>
                    </div>
                    @if($yes)
                    <div class="hc-form-grid" style="margin-block-start:12px">
                        @foreach($opened($switch) as $field)
                            <x-hc.field :label="__('checklists.fields.'.$field)" :for="'cl-'.$field" :required="$fields[$field]['needed'] ?? false" :error="$errors->first('form.header.'.$field)">
                                @include('people._input', $input($field))
                            </x-hc.field>
                        @endforeach
                    </div>
                    @endif
                </div>
            @endforeach
            @if($section === 'visits')
                <x-hc.field :label="__('checklists.fields.visits_report')" for="cl-visits_report" :error="$errors->first('form.header.visits_report')">
                    @include('people._input', $input('visits_report'))
                </x-hc.field>
            @endif
        </x-hc.section>
        @endforeach

        {{-- Team and committee opinions, each with its meeting date --}}
        @include('checklists._members', ['location' => 't', 'title' => __('checklists.lists.team'), 'step' => 4, 'dateField' => 'team_date'])
        @include('checklists._members', ['location' => 'c', 'title' => __('checklists.lists.committee'), 'step' => 5, 'dateField' => 'committee_date'])

        {{-- The need assessment: computed from the application data, read only (desktop view need_evaluation) --}}
        <x-hc.section :title="__('checklists.sections.need')" step="6">
            <p class="hc-hint" style="margin-block:0 12px">{{ __('checklists.need.hint') }}</p>
            @if($need === null)
                <p class="muted">{{ __('checklists.errors.no_beneficiary') }}</p>
            @else
            <div class="hc-table-wrap"><table class="hc-table stack" aria-label="{{ __('checklists.sections.need') }}">
                <thead><tr><th>{{ __('checklists.criterion') }}</th><th>{{ __('checklists.need.indicator') }}</th><th class="num">{{ __('checklists.need.mark') }}</th></tr></thead>
                <tbody>
                @foreach($need['rows'] as $row)
                    <tr wire:key="need-{{ $row['key'] }}">
                        <th scope="row" data-label="{{ __('checklists.criterion') }}" style="font-weight:600">{{ __('checklists.need.criteria.'.$row['key']) }}</th>
                        <td data-label="{{ __('checklists.need.indicator') }}" style="white-space:pre-line">{{ $row['indicator'] !== '' ? $row['indicator'] : '—' }}</td>
                        <td data-label="{{ __('checklists.need.mark') }}" class="num">{{ $row['mark'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr><th scope="row" colspan="2" style="text-align:end">{{ __('checklists.need.total') }}</th><td class="num" data-label="{{ __('checklists.need.total') }}"><strong>{{ $need['total'] }}</strong></td></tr></tfoot>
            </table></div>
            @endif
        </x-hc.section>

        {{-- Notes and the rejection message --}}
        <x-hc.section :title="__('checklists.sections.decision')" step="7">
            <x-hc.field :label="__('checklists.fields.notes')" for="cl-notes" :error="$errors->first('form.header.notes')">@include('people._input', $input('notes'))</x-hc.field>
            @foreach($blocks['decision'] as $switch)
                @php($yes = ($h[$switch] ?? '') === '1')
                <div class="hc-question" style="border:1px solid var(--line);border-radius:var(--radius);padding:12px 16px;margin-block-start:12px">
                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px">
                        <span class="hc-label" style="margin:0;flex:1;min-width:180px">{{ __('checklists.fields.'.$switch) }}</span>
                        <div class="hc-choice" role="radiogroup" aria-label="{{ __('checklists.fields.'.$switch) }}">
                            <label><input type="radio" value="1" wire:model.live="form.header.{{ $switch }}">{{ __('people.yes') }}</label>
                            <label><input type="radio" value="0" wire:model.live="form.header.{{ $switch }}">{{ __('people.no') }}</label>
                        </div>
                    </div>
                    @if($yes)
                    <div class="hc-form-grid" style="margin-block-start:12px">
                        @foreach($opened($switch) as $field)
                            <x-hc.field :label="__('checklists.fields.'.$field)" :for="'cl-'.$field" :required="$fields[$field]['needed'] ?? false" :error="$errors->first('form.header.'.$field)">
                                @include('people._input', $input($field))
                            </x-hc.field>
                        @endforeach
                    </div>
                    @endif
                </div>
            @endforeach
        </x-hc.section>
    </div>
    </fieldset>

    <x-hc.action-bar>
        <a class="hc-btn hc-btn-ghost" href="{{ route('projects.show', ['locale' => app()->getLocale(), 'project' => $projectId]) }}">{{ __('hc.back') }}</a>
        <span class="spacer"></span>
        @if($editable)
            @if($form['exists'])
                <a class="hc-btn hc-btn-secondary" href="{{ route('checklists.print', ['locale' => app()->getLocale(), 'project' => $projectId, 'person' => $personId]) }}" target="_blank"><x-hc.icon name="file" :size="18"/>{{ __('checklists.print') }}</a>
                <x-hc.button variant="ghost" wire:click="remove" wire:confirm="{{ __('checklists.confirm_delete') }}" style="color:var(--danger, #b42318)">{{ __('checklists.delete') }}</x-hc.button>
            @endif
            <span wire:loading wire:target="save" class="muted">{{ __('people.saving') }}</span>
            <x-hc.button type="submit" icon="check" wire:loading.attr="disabled" :disabled="! $form['person_id']">{{ __('checklists.save') }}</x-hc.button>
        @endif
    </x-hc.action-bar>
    </form>
</div>
