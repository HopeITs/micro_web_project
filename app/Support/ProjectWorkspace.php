<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Project;
use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\Applications\ApplicationService;
use App\Services\Applications\ProjectAnnexService;
use App\Services\Applications\ProjectScores;
use App\Services\Attachments\AttachmentService;
use App\Services\Papers\ProjectPaper;
use App\Services\People\PeopleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read model of the project page: which actions, modules and papers the account may open, the live summary of each module and the
 * "needs attention" list. Every fact of the page is produced here once, so the view only arranges it. The page decides nothing about
 * access on its own: the rules below are the ones the destinations enforce again (applications.view, finance.view, ...), and a summary
 * is only computed for a module the account may open.
 */
final class ProjectWorkspace
{
    /** @var array<string, bool> */
    private array $held = [];

    private WebAccount $account;

    private int $city;

    private string $locale;

    public function __construct(
        private AccountAccess $access,
        private ApplicationService $applications,
        private ProjectAnnexService $annexes,
        private ProjectScores $scores,
        private AttachmentService $attachments,
        private PeopleService $people,
    ) {}

    /**
     * @param  Project  $project  with city, projectApplicants.person, projectStateHistory.projectState and loans.loanInstallments loaded
     * @return array<string, mixed>
     */
    public function build(WebAccount $account, Project $project): array
    {
        $this->account = $account;
        $this->city = (int) $project->city_id;
        $this->locale = app()->getLocale();
        $this->held = [];

        $views = $this->can('applications.view');
        $flags = [
            'write' => $this->applications->canWrite($account, $this->city),
            // Next step, state card, tasks and scores follow the application itself.
            'tracks' => $views,
            'checklist' => $this->can('assessments.view'),
            'finance' => $this->can('finance.view') && $views,
            'visits' => $views && ($this->can('visits.view') || $this->can('me_visits.view')),
            'specialCases' => $views && $this->can('special_cases.view'),
            'executive' => $views && $this->can('executive_files.view'),
            'purchasing' => $views && $this->can('purchasing.view'),
        ];

        $applicant = $this->applicant($project);
        $loan = $this->loan($project);
        $files = $this->files($project);
        $scores = $flags['tracks'] ? $this->scoreSheet($project) : null;
        $papers = $this->papers((int) $project->id, $flags);

        $tabs = array_keys(array_filter(['overview' => true, 'progress' => $flags['tracks'], 'attachments' => $files !== null, 'papers' => $papers !== []]));

        return $flags + [
            'status' => $this->status($project),
            'applicant' => $applicant,
            'loan' => $loan,
            'files' => $files,
            'scores' => $scores,
            'papers' => $papers,
            'modules' => $this->modules($project, $flags, $loan),
            'attention' => $this->attention($project, $flags, $applicant, $loan, $files, $scores),
            'tabs' => $tabs,
        ];
    }

    private function can(string $capability, ?int $city = null): bool
    {
        $city ??= $this->city;

        return $this->held[$city.':'.$capability] ??= $this->access->check($this->account, $city, $capability);
    }

    /** "19,600,000 SYP" in both languages (isolated, so a right-to-left sentence keeps that order). */
    public static function money(float $amount, string $currency): string
    {
        return "\u{2066}".trim(number_format($amount).' '.$currency)."\u{2069}";
    }

    private function url(string $route, array $parameters): string
    {
        return route($route, ['locale' => $this->locale] + $parameters);
    }

    /** The state in force with its colour and icon, and where the stage bar stands. */
    private function status(Project $project): ?array
    {
        $current = $project->projectStateHistory->firstWhere('is_current', true);
        if ($current === null) {
            return null;
        }
        $state = $current->projectState;
        $english = $state?->name_en;
        $earlier = $project->projectStateHistory->reject(fn ($h) => $h->is_current)->map(fn ($h) => $h->projectState?->name_en)->values()->all();

        return [
            'label' => $state ? ($this->locale === 'en' && $state->name_en ? $state->name_en : $state->name_ar) : __('hc.not_set'),
            'tone' => ProjectStatus::tone($english),
            'icon' => ProjectStatus::icon($english),
            'path' => ProjectStatus::path($english, $earlier),
        ];
    }

    /** The beneficiary (else the first applicant), with the links the account may follow to that person. */
    private function applicant(Project $project): ?array
    {
        $person = $project->projectApplicants
            ->sortBy(fn ($a) => [$a->role === ApplicationService::BENEFICIARY ? 0 : 1, $a->id])
            ->first(fn ($a) => $a->person !== null)?->person;
        if ($person === null) {
            return null;
        }
        $city = (int) $person->city_id;
        $reads = $this->can('people.view', $city);
        $mobile = trim((string) $person->mobile);

        return [
            'name' => trim(implode(' ', array_filter([$person->first_name, $person->father_name, $person->last_name]))),
            'national' => trim((string) $person->national_number),
            'mobile' => $mobile,
            // Only what a dialler understands; a number written with words stays copyable but is not linked.
            'tel' => preg_match('/\A\+?[\d\s\-\/().]{5,}\z/', $mobile) === 1 ? preg_replace('/(?!\A\+)\D/', '', preg_split('/\s*\/\s*/', $mobile)[0]) : null,
            'url' => $reads ? $this->url('people.show', ['person' => $person->id]) : null,
            'editUrl' => $reads && $this->people->canManage($this->account, $city) ? $this->url('people.edit', ['person' => $person->id]) : null,
        ];
    }

    /** The loan in short; "overdue" is the finance module's rule: unpaid and due before today. */
    private function loan(Project $project): ?array
    {
        $loan = $project->loans->first();
        if ($loan === null) {
            return null;
        }
        $today = CarbonImmutable::today()->toDateString();
        $rows = $loan->loanInstallments->values()->map(fn ($i) => [
            'amount' => (float) $i->amount,
            'due' => $i->due_on?->format('Y-m-d'),
            'paid' => (bool) $i->is_paid,
            'late' => ! $i->is_paid && $i->due_on !== null && $i->due_on->format('Y-m-d') < $today,
        ]);
        $amount = (float) $loan->amount;
        $paid = (float) $rows->where('paid', true)->sum('amount');
        $late = $rows->where('late', true);

        return [
            'amount' => $amount,
            'currency' => (string) $loan->currency,
            'paid' => $paid,
            'percent' => $amount > 0 ? (int) min(100, round($paid / $amount * 100)) : 0,
            'rows' => $rows->all(),
            'overdue' => $late->count(),
            'overdueAmount' => (float) $late->sum('amount'),
            'nextDue' => $rows->filter(fn ($r) => ! $r['paid'] && $r['due'] !== null && $r['due'] >= $today)->min('due'),
        ];
    }

    /** Attachment counts for those who may see attachments here; "hidden" are files of categories the account does not hold. */
    private function files(Project $project): ?array
    {
        if (! $this->attachments->canSee($this->account, $this->city)) {
            return null;
        }
        $list = $this->attachments->list($this->account, 'project', (int) $project->id);

        return ['visible' => array_sum($list['counts']), 'hidden' => $list['hidden'], 'canUpload' => $this->attachments->uploadTypes($this->account, $this->city)->isNotEmpty()];
    }

    /** The ten study scores: rows for the table, the favourable total, and whether anything was filled. */
    private function scoreSheet(Project $project): array
    {
        $values = $this->scores->load((int) $project->id);
        $filled = collect($values)->contains(fn ($s) => $s['value'] !== '' || $s['notes'] !== '');
        $rows = [];
        if ($filled) {
            foreach ($this->scores->criteria() as $c) {
                $s = $values[$c->id] ?? ['value' => '', 'notes' => ''];
                $rows[] = [
                    'label' => $c->label,
                    'answer' => $s['value'] === '' ? '—' : ($c->options->firstWhere('value', (int) $s['value'])->label ?? $s['value']),
                    'notes' => $s['notes'],
                ];
            }
        }

        return ['filled' => $filled, 'rows' => $rows, 'total' => ProjectScores::total($values)];
    }

    /** @return list<array<string, mixed>> the module tiles the account may open, each with its live summary */
    private function modules(Project $project, array $flags, ?array $loan): array
    {
        $id = (int) $project->id;
        $live = fn (string $table) => DB::table($table)->where('project_id', $id)->whereNull('deleted_at');
        $tile = fn (string $key, string $icon, string $label, string $url, array $lines = [], array $more = []) => $more + [
            'key' => $key, 'icon' => $icon, 'label' => $label, 'url' => $url, 'lines' => array_values(array_filter($lines)), 'percent' => null, 'alert' => false,
        ];
        $modules = [];

        if ($flags['finance']) {
            $modules[] = $tile('finance', 'wallet', __('finance.open'), $this->url('finance.show', ['project' => $id]), $loan === null ? [__('hc.no_loan')] : [
                __('project_page.tile.loan', ['money' => self::money($loan['amount'], $loan['currency'])]),
                __('project_page.tile.paid', ['percent' => $loan['percent']]),
                $loan['nextDue'] ? __('project_page.tile.next_due', ['date' => $loan['nextDue']]) : null,
            ], ['percent' => $loan['percent'] ?? null, 'alert' => ($loan['overdue'] ?? 0) > 0]);
        }
        if ($flags['visits']) {
            // Each kind is counted only for those who may read it.
            $count = 0;
            $last = [];
            foreach (['visits' => 'visits.view', 'me_visits' => 'me_visits.view'] as $table => $capability) {
                if ($this->can($capability)) {
                    $count += $live($table)->count();
                    $last[] = (string) $live($table)->max('visit_date');
                }
            }
            $latest = substr((string) max($last ?: ['']), 0, 10);
            $modules[] = $tile('visits', 'map', __('visits.open'), $this->url('visits.hub', ['project' => $id]), [
                trans_choice('project_page.tile.visits', $count, ['count' => $count]),
                $latest !== '' ? __('project_page.tile.last', ['date' => $latest]) : null,
            ]);
        }
        if ($flags['checklist']) {
            $modules[] = $tile('checklist', 'clipboard', 'Checklist', $this->url('checklists.show', ['project' => $id]),
                [__($this->hasChecklist($project) ? 'project_page.tile.checklist_done' : 'project_page.tile.checklist_none')]);
        }
        if ($flags['specialCases']) {
            $count = $live('special_cases')->count();
            $latest = substr((string) $live('special_cases')->max('case_date'), 0, 10);
            $modules[] = $tile('special-cases', 'flag', __('special_cases.open'), $this->url('special-cases.project', ['project' => $id]), [
                trans_choice('project_page.tile.cases', $count, ['count' => $count]),
                $latest !== '' ? __('project_page.tile.last', ['date' => $latest]) : null,
            ]);
        }
        if ($flags['executive']) {
            $file = $live('executive_files')->orderBy('id')->first(['file_number']);
            $modules[] = $tile('executive', 'shield', __('executive.open'), $this->url('executive.show', ['project' => $id]), [match (true) {
                $file === null => __('project_page.tile.executive_none'),
                (string) $file->file_number !== '' => __('project_page.tile.executive_number', ['number' => $file->file_number]),
                default => __('project_page.tile.executive_has'),
            }]);
        }
        if ($flags['purchasing']) {
            $count = $live('purchases')->count();
            $modules[] = $tile('purchasing', 'cart', __('purchasing.open'), $this->url('purchasing.show', ['project' => $id]),
                [trans_choice('project_page.tile.purchases', $count, ['count' => $count])]);
        }
        if ($kind = $this->annexes->kind($project)) {
            $answered = $flags['tracks'] ? $live($kind === 'agriculture' ? 'project_agriculture_answers' : 'project_rehabilitations')->exists() : null;
            $modules[] = $tile('annex', 'file', __('annexes.'.$kind), $this->url('applications.annex', ['project' => $id]),
                [$answered === null ? null : __($answered ? 'project_page.tile.annex_done' : 'project_page.tile.annex_none')]);
        }

        return $modules;
    }

    /** A checklist of this project, or (old records had none) one of its beneficiary's without a project; as the next step reads it. */
    private function hasChecklist(Project $project): bool
    {
        $person = DB::table('project_applicants')->where('project_id', $project->id)->whereNull('deleted_at')
            ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [ApplicationService::BENEFICIARY])->orderBy('id')->value('person_id');

        return DB::table('checklists')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('project_id', $project->id)->orWhere(fn ($q) => $q->whereNull('project_id')->where('person_id', $person ?? 0)))->exists();
    }

    /**
     * Every printable paper of the project and the pages where their data is typed, grouped by kind.
     *
     * @return list<array{key: string, label: string, items: list<array{key: string, label: string, url: string, entry: bool}>}>
     */
    private function papers(int $id, array $flags): array
    {
        $groups = ['application' => [], 'consents' => [], 'finance' => [], 'purchasing' => []];
        $add = function (string $group, string $key, string $label, string $route, array $parameters = [], bool $entry = false) use (&$groups, $id) {
            $groups[$group][] = ['key' => $key, 'label' => $label, 'url' => $this->url($route, ['project' => $id] + $parameters), 'entry' => $entry];
        };

        // The application's papers show to everyone who may open them: applications.view (owner decision, 25 Nov 2026).
        if ($flags['tracks']) {
            $add('application', 'application', __('papers.print_application'), 'applications.print');
            $add('application', 'file', __('papers.print_file'), 'applications.file');
            if ($this->can('special_cases.view')) {
                $add('application', 'special-case', __('paper_project.print_special'), 'applications.paper', ['paper' => 'special-case']);
            }
            $add('application', 'experience-test', __('paper_project.print_test'), 'applications.paper', ['paper' => 'experience-test']);
            $add('consents', 'audio-consent', __('paper_project.print_audio'), 'applications.paper', ['paper' => 'audio-consent']);
            // Hope Center's consent prints for every project; the two Caritas Polska consents only for Caritas projects (owner decisions, 25 Nov 2026).
            $add('consents', 'photo-informed', __('paper_consent.print_photo_informed'), 'applications.paper', ['paper' => 'photo-informed']);
            if (ProjectPaper::isCaritas($id)) {
                $add('consents', 'photo-adult', __('paper_consent.print_photo_adult'), 'applications.paper', ['paper' => 'photo-adult']);
                $add('consents', 'photo-child', __('paper_consent.print_photo_child'), 'applications.paper', ['paper' => 'photo-child']);
            }
        }
        if ($flags['finance']) {
            $add('finance', 'paper-data', __('papers.data.open'), 'applications.paper-data', entry: true);
            foreach (['promissory-note' => 'print_note', 'clearance' => 'print_clearance', 'disbursement-request' => 'print_disbursement', 'partnership-contract' => 'print_partnership', 'beneficiary-card' => 'print_card'] as $paper => $label) {
                $add('finance', $paper, __('paper_finance.'.$label), 'applications.finance-paper', ['paper' => $paper]);
            }
            $add('finance', 'deeds', __('paper_deeds.open_deeds'), 'applications.deeds', entry: true);
        }
        if ($flags['purchasing']) {
            $add('purchasing', 'purchase-data', __('purchase_data.open'), 'applications.purchase-data', entry: true);
            foreach (['purchase-request' => 'print_request', 'price-quote' => 'print_quote', 'equipment-receipt' => 'print_receipt', 'equipment-return' => 'print_return'] as $paper => $label) {
                $add('purchasing', $paper, __('paper_purchase.'.$label), 'applications.purchase-paper', ['paper' => $paper]);
            }
            $add('purchasing', 'sale-contracts', __('paper_deeds.open_sales'), 'applications.sale-contracts', entry: true);
        }
        $out = [];
        foreach (array_filter($groups) as $key => $items) {
            $out[] = ['key' => $key, 'label' => __('project_page.papers.groups.'.$key), 'items' => $items];
        }

        return $out;
    }

    /**
     * What is missing or late, from the saved data only. An action is offered only where the account may act.
     *
     * @return list<array{key: string, tone: string, text: string, url: ?string, action: ?string}>
     */
    private function attention(Project $project, array $flags, ?array $applicant, ?array $loan, ?array $files, ?array $scores): array
    {
        $items = [];
        $item = function (string $key, string $tone, string $text, ?string $url = null, ?string $action = null) use (&$items) {
            $items[] = ['key' => $key, 'tone' => $tone, 'text' => $text, 'url' => $url, 'action' => $url === null ? null : __('project_page.attention.actions.'.$action)];
        };

        if ($loan !== null && $loan['overdue'] > 0) {
            $item('overdue', 'danger',
                trans_choice('project_page.attention.overdue', $loan['overdue'], ['count' => $loan['overdue'], 'money' => self::money($loan['overdueAmount'], $loan['currency'])]),
                $flags['finance'] ? $this->url('finance.show', ['project' => $project->id]) : '#installments',
                $flags['finance'] ? 'finance' : 'installments');
        }
        if ($applicant !== null && $applicant['national'] === '') {
            $item('national', 'warn', __('project_page.attention.national'), $applicant['editUrl'], 'person');
        }
        if ($applicant !== null && $applicant['mobile'] === '') {
            $item('mobile', 'warn', __('project_page.attention.mobile'), $applicant['editUrl'], 'person');
        }
        if ($scores !== null && ! $scores['filled'] && $project->application_stage === 'full') {
            $item('scores', 'warn', __('project_page.attention.scores'),
                $flags['write'] ? $this->url('applications.edit', ['project' => $project->id]) : null, 'scores');
        }
        if ($files !== null && $files['visible'] + $files['hidden'] === 0) {
            $item('attachments', 'warn', __('project_page.attention.attachments'), $files['canUpload'] ? '#attachments' : null, 'attachments');
        }

        return $items;
    }
}
