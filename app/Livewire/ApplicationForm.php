<?php

namespace App\Livewire;

use App\Services\Applications\ApplicationFields;
use App\Services\Applications\ApplicationService;
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

    public function mount(?int $projectId = null, ?int $personId = null): void
    {
        $service = app(ApplicationService::class);
        $account = auth()->user();
        if ($projectId !== null) {
            $this->form = $service->load($account, $projectId);
            $service->canWrite($account, (int) $this->form['city']) || abort(403);
            $this->projectId = $projectId;
            $this->savedStage = $this->form['stage'];
        } else {
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
        }
    }

    private function service(): ApplicationService
    {
        return app(ApplicationService::class);
    }

    public function updatedFormCity(): void
    {
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
        if ($property === 'form.project.project_type_id') {
            $this->form['project']['project_subtype_id'] = '';
        }
        $this->readinessChecked = false;
    }

    public function setStage(string $stage): void
    {
        if ($this->savedStage === 'full') {
            return;
        }
        $this->form['stage'] = $stage === 'full' ? 'full' : 'preliminary';
        $this->readinessChecked = false;
    }

    /** Takes a registered person of the city as the beneficiary, with their family and sub-tables. */
    public function pickPerson(int $id): void
    {
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
        isset(ApplicationFields::GRIDS[$kind]) || abort(404);
        $this->form['grids'][$kind][] = ['id' => null] + array_fill_keys(array_keys(ApplicationFields::grid($kind)['fields']), '');
    }

    public function removeRow(string $kind, int $index): void
    {
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

            return;
        }
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
        $options = $people->optionsFor($projectFields + PersonFields::personFlat(), $city, $this->form['project'] + $this->form['person']);
        $subtypes = DB::table('project_subtypes')->where('is_active', true)->when($this->form['project']['project_type_id'] ?? '', fn ($q, $t) => $q->where('project_type_id', (int) $t))
            ->orderBy('sort_order')->orderBy('name_ar')->get(['id', 'name_ar', 'name_en']);
        $en = app()->getLocale() === 'en';
        $options['project_subtype_id'] = $subtypes->map(fn ($o) => (object) ['id' => (int) $o->id, 'label' => $en && $o->name_en ? $o->name_en : $o->name_ar]);
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
            'options' => $options, 'gridOptions' => $gridOptions, 'grids' => ApplicationFields::grids($stage),
            'subcategoryGroups' => DB::table('project_subcategories as s')->join('project_categories as c', 'c.id', '=', 's.project_category_id')
                ->where(fn ($q) => $q->where('s.is_active', true)->orWhere('s.id', (int) ($this->form['project']['project_subcategory_id'] ?: 0)))
                ->orderBy('c.sort_order')->orderBy('c.name_ar')->orderBy('s.sort_order')->orderBy('s.name_ar')
                ->get(['s.id', 's.name_ar', 's.name_en', 'c.name_ar as category_ar', 'c.name_en as category_en'])
                ->groupBy(fn ($s) => $en && $s->category_en ? $s->category_en : $s->category_ar),
            'duplicates' => $duplicates,
            'holders' => $this->form['person_id'] ? collect() : $people->familyHolders($city, (string) $this->form['family_number']),
            'picks' => $this->form['person_id'] === null && $this->projectId === null ? $people->pick($account, $city, $this->personSearch) : collect(),
            'suggested' => $this->projectId === null ? app(ProjectNumberAllocator::class)->suggest($city) : null,
        ]);
    }
}
