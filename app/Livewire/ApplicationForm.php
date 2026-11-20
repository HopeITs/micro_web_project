<?php

namespace App\Livewire;

use App\Services\Applications\ApplicationFields;
use App\Services\Applications\ApplicationService;
use App\Services\Drafts\ApplicationDraftStore;
use App\Services\People\PeopleService;
use App\Services\People\PersonFields;
use App\Services\ProjectNumberAllocator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The interactive application form (M4.5): preliminary or full, in the paper order, with the beneficiary, the internal grids and the
 * project number, saved at once by ApplicationService. Nothing is written before "Save".
 */
class ApplicationForm extends Component
{
    #[Locked]
    public ?int $projectId = null;

    /** Stage when the form was opened: a full application cannot go back. */
    #[Locked]
    public string $savedStage = '';

    /** @var array<string, mixed> */
    public array $form = [];

    public bool $confirmed = false;

    public string $personSearch = '';

    /** @var list<string> */
    public array $readinessIssues = [];

    public bool $readinessChecked = false;

    /** The draft this form autosaves to (one per application being edited, a new one per new form). */
    #[Locked]
    public string $draftId = '';

    /** Changed since the last autosave. */
    public bool $dirty = false;

    public string $draftSavedAt = '';

    /** An older draft of this application that may be restored (edit page). */
    public string $pendingDraftAt = '';

    /** @var array<string, array{rows: list<array>, fields: array<string, string>}> what a "No" answer took out, per question */
    public array $undo = [];

    public function mount(?int $projectId = null, ?int $personId = null, ?string $draft = null): void
    {
        $service = app(ApplicationService::class);
        $drafts = app(ApplicationDraftStore::class);
        $account = auth()->user();
        if ($draft !== null && $projectId === null) {
            $saved = $drafts->load($account, $draft) ?? abort(404);
            if ($saved['project_id'] !== null) {
                $this->redirectRoute('applications.edit', ['locale' => app()->getLocale(), 'project' => $saved['project_id']]);

                return;
            }
            $this->form = $saved['state'];
            $this->draftId = $draft;
            $this->draftSavedAt = substr((string) $saved['saved_at'], 11, 5);

            return;
        }
        if ($projectId !== null) {
            $this->form = $service->load($account, $projectId);
            $service->canWrite($account, (int) $this->form['city']) || abort(403);
            $this->projectId = $projectId;
            $this->savedStage = $this->form['stage'];
            $this->draftId = ApplicationDraftStore::newId($projectId);
            $pending = $drafts->load($account, $this->draftId);
            $this->pendingDraftAt = $pending ? str_replace('T', ' ', substr((string) $pending['saved_at'], 0, 16)) : '';
        } else {
            $this->draftId = ApplicationDraftStore::newId();
            $cities = $service->cities($account);
            $cities !== [] || abort(403);
            $city = $cities[0];
            if ($personId !== null) {
                $person = app(PeopleService::class)->person($account, $personId);
                in_array((int) $person->city_id, $cities, true) || abort(403);
                $city = (int) $person->city_id;
            }
            $this->form = $service->blank($account, $city);
            if ($personId !== null) {
                $this->pickPerson($personId);
            }
            $this->dirty = false;
        }
    }

    private function service(): ApplicationService
    {
        return app(ApplicationService::class);
    }

    public function updatedFormCity(): void
    {
        $this->dirty = true;
        if ($this->projectId !== null) {
            return;
        }
        $city = (int) $this->form['city'];
        $this->service()->canWrite(auth()->user(), $city) || abort(403);
        $fresh = $this->service()->blank(auth()->user(), $city, $this->form['stage']);
        // Keep what was typed, except the city's own lists, the number and any picked person of another city.
        $fresh['project'] = array_merge($this->form['project'], ['street_id' => '']);
        $fresh['applicant'] = $this->form['applicant'];
        $fresh['person'] = array_merge($this->form['person'], ['street_id' => '', 'priest_id' => '', 'second_priest_id' => '']);
        $fresh['grids'] = array_merge($fresh['grids'], array_intersect_key($this->form['grids'], array_flip(['materials', 'project_power_sources', 'risks'])));
        $this->form = $fresh;
        $this->confirmed = false;
    }

    public function updated(string $property): void
    {
        if (preg_match('/^form\.(person\.(national_number|first_name|last_name|father_name)|family_number)$/', $property)) {
            $this->confirmed = false;
        }
        if (preg_match('/^form\.project\.(\w+)$/', $property, $m)) {
            $this->followParents($m[1]);
        }
        if (preg_match('/^form\.applicant\.(\w+)$/', $property, $m) && isset(ApplicationFields::SWITCHES[$m[1]]) && ($this->form['applicant'][$m[1]] ?? '') !== '1') {
            $this->closeSwitch($m[1]);
        }
        $this->readinessChecked = false;
        if (str_starts_with($property, 'form.')) {
            $this->dirty = true;
        }
    }

    /**
     * Dependent lists: a changed parent clears a child that no longer belongs to it; a career chosen before its field fills the field.
     */
    private function followParents(string $changed): void
    {
        $fields = ApplicationFields::flat(ApplicationFields::PROJECT, 'full');
        foreach ($fields as $field => $spec) {
            if (($spec['parent'] ?? null) === $changed && ($this->form['project'][$field] ?? '') !== '') {
                $parent = (int) DB::table($spec['table'])->where('id', (int) $this->form['project'][$field])->value($spec['parent_column']);
                if ((string) $parent !== (string) $this->form['project'][$changed]) {
                    $this->form['project'][$field] = '';
                }
            }
        }
        $spec = $fields[$changed] ?? [];
        if (isset($spec['parent']) && ($this->form['project'][$changed] ?? '') !== '' && ($this->form['project'][$spec['parent']] ?? '') === '') {
            $this->form['project'][$spec['parent']] = (string) (DB::table($spec['table'])->where('id', (int) $this->form['project'][$changed])->value($spec['parent_column']) ?? '');
        }
    }

    /** "No" (or no answer) to a question: its rows and fields leave the form, kept for undo until saving. */
    private function closeSwitch(string $switch): void
    {
        $rule = ApplicationFields::SWITCHES[$switch];
        $taken = ['rows' => [], 'fields' => []];
        if (isset($rule['grid'])) {
            $kind = $rule['grid'];
            $keep = [];
            foreach ($this->form['grids'][$kind] as $row) {
                if (ApplicationFields::governed($switch, $row)) {
                    $taken['rows'][] = $row;
                    if (! empty($row['id'])) {
                        $this->form['removed'][$kind][] = (int) $row['id'];
                    }
                } else {
                    $keep[] = $row;
                }
            }
            $this->form['grids'][$kind] = $keep;
        }
        foreach ($rule['fields'] ?? [] as $field) {
            if (($this->form['applicant'][$field] ?? '') !== '') {
                $taken['fields'][$field] = $this->form['applicant'][$field];
                $this->form['applicant'][$field] = '';
            }
        }
        if ($taken['rows'] !== [] || $taken['fields'] !== []) {
            $this->undo[$switch] = $taken;
        }
    }

    /** Brings back what answering "No" took out, and answers "Yes" again. */
    public function undoSwitch(string $switch): void
    {
        $taken = $this->undo[$switch] ?? null;
        if ($taken === null || ! isset(ApplicationFields::SWITCHES[$switch])) {
            return;
        }
        $rule = ApplicationFields::SWITCHES[$switch];
        if (isset($rule['grid'])) {
            $kind = $rule['grid'];
            $ids = array_map(fn ($r) => (int) ($r['id'] ?? 0), $taken['rows']);
            $this->form['removed'][$kind] = array_values(array_diff($this->form['removed'][$kind] ?? [], $ids));
            $this->form['grids'][$kind] = array_merge($this->form['grids'][$kind], $taken['rows']);
        }
        foreach ($taken['fields'] as $field => $value) {
            $this->form['applicant'][$field] = $value;
        }
        $this->form['applicant'][$switch] = '1';
        unset($this->undo[$switch]);
        $this->dirty = true;
    }

    /** Called every 20 seconds by the page: keeps an encrypted draft when something changed. */
    public function autosave(): void
    {
        if (! $this->dirty || $this->draftId === '') {
            return;
        }
        app(ApplicationDraftStore::class)->save(auth()->user(), $this->draftId, $this->form, $this->projectId);
        $this->dirty = false;
        $this->draftSavedAt = now()->format('H:i');
    }

    /** Edit page: replaces the form with the older draft of this application. */
    public function restoreDraft(): void
    {
        $saved = app(ApplicationDraftStore::class)->load(auth()->user(), $this->draftId);
        if ($saved !== null && (int) ($saved['project_id'] ?? 0) === $this->projectId) {
            $this->form = $saved['state'];
            $this->form['city'] = (int) $this->form['city'];
            $this->dirty = true;
        }
        $this->pendingDraftAt = '';
    }

    public function discardDraft(): void
    {
        app(ApplicationDraftStore::class)->discard(auth()->user(), $this->draftId);
        $this->pendingDraftAt = '';
        $this->draftSavedAt = '';
    }

    public function setStage(string $stage): void
    {
        $this->dirty = true;
        if ($this->savedStage === 'full') {
            return;
        }
        $this->form['stage'] = $stage === 'full' ? 'full' : 'preliminary';
        $this->readinessChecked = false;
    }

    /** Takes a registered person of the city as the beneficiary, with their family and sub-tables. */
    public function pickPerson(int $id): void
    {
        $this->dirty = true;
        $people = app(PeopleService::class);
        $person = $people->person(auth()->user(), $id);
        (int) $person->city_id === (int) $this->form['city'] || abort(404);
        $this->form['person_id'] = $id;
        $this->form['person'] = ApplicationService::strings(PersonFields::personFlat(), (array) $person);
        $family = DB::table('family_members as m')->join('families as f', 'f.id', '=', 'm.family_id')->where('m.person_id', $id)->whereNull('m.deleted_at')->orderBy('m.id')->first(['f.id', 'f.family_number']);
        $this->form['family_number'] = $family ? (string) $family->family_number : '';
        foreach (ApplicationFields::GRIDS as $kind => $grid) {
            if ($grid['owner'] === 'project') {
                continue;
            }
            $definition = ApplicationFields::grid($kind);
            $rows = $grid['owner'] === 'family'
                ? ($family ? DB::table('family_members as m')->join('people as p', 'p.id', '=', 'm.person_id')->where('m.family_id', $family->id)->where('m.person_id', '<>', $id)->whereNull('m.deleted_at')->whereNull('p.deleted_at')->orderBy('m.id')
                    ->get(['m.id', 'm.relation_id', 'm.education_level_id', 'm.education_specialty_id', 'm.has_work', 'm.work_name', ...array_map(fn ($c) => 'p.'.$c, ApplicationFields::FAMILY_PERSON)]) : collect())
                : DB::table($definition['table'])->where('person_id', $id)->whereNull('deleted_at')->orderBy('id')->get();
            $this->form['grids'][$kind] = $rows->map(fn ($r) => ['id' => (int) $r->id] + ApplicationService::strings($definition['fields'], (array) $r))->values()->all();
            $this->form['removed'][$kind] = [];
        }
        $this->personSearch = '';
        $this->confirmed = false;
    }

    /** Back to a new beneficiary (only before the application is saved). */
    public function newPerson(): void
    {
        $this->dirty = true;
        if ($this->projectId !== null) {
            return;
        }
        $blank = $this->service()->blank(auth()->user(), (int) $this->form['city'], $this->form['stage']);
        $this->form['person_id'] = null;
        $this->form['person'] = $blank['person'];
        $this->form['family_number'] = '';
        foreach (ApplicationFields::GRIDS as $kind => $grid) {
            if ($grid['owner'] !== 'project') {
                $this->form['grids'][$kind] = [];
                $this->form['removed'][$kind] = [];
            }
        }
    }

    public function addRow(string $kind): void
    {
        $this->dirty = true;
        isset(ApplicationFields::GRIDS[$kind]) || abort(404);
        $row = ['id' => null] + array_fill_keys(array_keys(ApplicationFields::grid($kind)['fields']), '');
        $switch = ApplicationFields::gridSwitches()[$kind] ?? null;
        if ($switch !== null && ($this->form['applicant'][$switch] ?? '') !== '1') {
            $only = ApplicationFields::SWITCHES[$switch]['only'] ?? null;
            if ($only === null) {
                return; // the whole table opens only after "Yes"
            }
            // Only the governed rows are closed: a new row is of the other kind (a previous job).
            foreach ($only as $field => $value) {
                $row[$field] = $value === 'yes' ? 'no' : '';
            }
        }
        $this->form['grids'][$kind][] = $row;
    }

    public function removeRow(string $kind, int $index): void
    {
        $this->dirty = true;
        $row = $this->form['grids'][$kind][$index] ?? null;
        if ($row === null) {
            return;
        }
        if (! empty($row['id'])) {
            $this->form['removed'][$kind][] = (int) $row['id'];
        }
        unset($this->form['grids'][$kind][$index]);
        $this->form['grids'][$kind] = array_values($this->form['grids'][$kind]);
        $this->resetErrorBag();
    }

    private function showErrors(ValidationException $e): void
    {
        foreach ($e->errors() as $field => $messages) {
            if ($field === 'readiness') {
                $this->readinessIssues = $messages;
                $this->readinessChecked = true;

                continue;
            }
            $field = $field === 'project_number' ? 'number' : $field; // ProjectNumberAllocator's key
            $this->addError(in_array($field, ['confirm', 'number', 'family_number'], true) ? $field : 'form.'.$field, $messages[0]);
        }
    }

    /** Lists what a full application still lacks, without saving. */
    public function checkReadiness(): void
    {
        $this->resetErrorBag();
        $this->readinessIssues = [];
        try {
            $values = $this->service()->validateState($this->form, $this->projectId, stageRules: false);
            $this->readinessIssues = $this->service()->readiness($this->form, $values);
            $this->readinessChecked = true;
        } catch (ValidationException $e) {
            $this->showErrors($e);
        }
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $this->readinessIssues = [];
        try {
            $id = $this->service()->save(auth()->user(), $this->form, $this->projectId, $this->confirmed);
        } catch (ValidationException $e) {
            $this->showErrors($e);
            $this->dispatch('form-errors');
            $this->dirty = true;
            $this->autosave(); // keep the typed work even when it cannot be saved yet

            return;
        }
        app(ApplicationDraftStore::class)->discard(auth()->user(), $this->draftId);
        session()->flash('status', $this->projectId ? __('applications.updated') : __('applications.created'));
        $this->redirectRoute('projects.show', ['locale' => app()->getLocale(), 'project' => $id]);
    }

    public function render(): View
    {
        $service = $this->service();
        $people = app(PeopleService::class);
        $account = auth()->user();
        $city = (int) $this->form['city'];
        $stage = $this->form['stage'];
        $projectFields = ApplicationFields::flat(ApplicationFields::PROJECT, 'full');
        // Person and project both have street_id: their options are built apart so each keeps its own value.
        $personOptions = $people->optionsFor(PersonFields::personFlat(), $city, $this->form['person']);
        $options = $people->optionsFor($projectFields, $city, $this->form['project']);
        $gridOptions = [];
        foreach (ApplicationFields::grids($stage) as $kind) {
            $gridOptions[$kind] = $people->optionsFor(ApplicationFields::grid($kind)['fields'], $city);
        }
        $duplicates = $people->duplicates($account, $city, $this->form['person'], $this->form['person_id']);
        // The person's own current applications are not a duplicate warning on their own application.
        if ($this->projectId !== null) {
            $duplicates = array_values(array_filter($duplicates, fn ($d) => $d->id !== $this->form['person_id']));
        }

        return view('livewire.application-form', [
            'stage' => $stage, 'city' => DB::table('cities')->where('id', $city)->first(),
            'cities' => $this->projectId === null ? $people->cities($account)->whereIn('id', $service->cities($account))->values() : collect(),
            'projectGroups' => array_map(fn ($g) => array_filter($g, fn ($s) => $stage === 'full' || ! ($s['full'] ?? false)), ApplicationFields::PROJECT),
            'applicantGroups' => array_map(fn ($g) => array_filter($g, fn ($s) => $stage === 'full' || ! ($s['full'] ?? false)), ApplicationFields::APPLICANT),
            'personGroups' => array_map(fn ($g) => array_diff_key($g, $stage === 'full' ? [] : array_flip(ApplicationFields::PERSON_FULL_ONLY)), PersonFields::PERSON),
            'options' => $options, 'personOptions' => $personOptions, 'gridOptions' => $gridOptions, 'grids' => ApplicationFields::grids($stage),
            'duplicates' => $duplicates,
            'holders' => $this->form['person_id'] ? collect() : $people->familyHolders($city, (string) $this->form['family_number']),
            'picks' => $this->form['person_id'] === null && $this->projectId === null ? $people->pick($account, $city, $this->personSearch) : collect(),
            'suggested' => $this->projectId === null ? app(ProjectNumberAllocator::class)->suggest($city) : null,
        ]);
    }
}
