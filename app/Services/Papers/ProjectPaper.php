<?php

declare(strict_types=1);

namespace App\Services\Papers;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Assessments\ChecklistService;
use App\Services\Field\SpecialCaseService;
use Illuminate\Support\Facades\DB;

/**
 * M4.20 wave 1: the small per-project papers redrawn on the print engine: the audio-recording consent (F04), Special Case (F05) and the
 * experience test (F06), and the three photo consents (F24 adult and F25 child for Caritas Polska, F27 Hope Center's informed consent),
 * which print only for projects whose donor is Caritas (owner decision, 25 Nov 2026); the choices are ticked by hand. Each is filled from the saved project (names, number, mobile, city) through {@see ApplicationPaper::build}, so the
 * reader needs applications.view in the city. Special Case also needs special_cases.view and fills the chosen case (blank without one);
 * the experience test fills the checklist's test fields only when the reader also holds assessments.view. Audited `papers.printed`.
 */
final class ProjectPaper
{
    /** URL part => paper id (the library's id), view, extra capability. */
    public const PAPERS = ['audio-consent' => 'F04', 'special-case' => 'F05', 'experience-test' => 'F06', 'photo-adult' => 'F24', 'photo-child' => 'F25', 'photo-informed' => 'F27'];

    /** The consents printed only for Caritas projects. */
    public const CARITAS = ['photo-adult', 'photo-child', 'photo-informed'];

    public function __construct(private ApplicationPaper $application, private SpecialCaseService $cases, private ChecklistService $checklists, private AccountAccess $access, private AccountAuditTrail $audit) {}

    /** @return array<string, mixed> */
    public function forPrint(WebAccount $account, int $projectId, string $kind, ?int $caseId = null): array
    {
        $id = self::PAPERS[$kind] ?? abort(404);
        $d = $this->application->build($account, $projectId);
        abort_if(in_array($kind, self::CARITAS, true) && ! self::isCaritas($projectId), 404);
        $city = (int) $d['city']['id'];
        $p = $d['person'];
        $out = [
            'paper' => $id, 'kind' => $kind, 'projectId' => $projectId, 'city' => $d['city'], 'number' => $d['number'], 'place' => PaperCity::placeName($d['city']['code']),
            'name' => trim(implode(' ', array_filter([$p['first_name'] ?? '', $p['father_name'] ?? '', $p['last_name'] ?? '']))), 'mobile' => (string) ($p['mobile'] ?? ''),
            'career' => (string) ($d['project']['career_id'] ?? ''), 'date' => now()->format('Y/m/d'),
        ];
        if (in_array($kind, self::CARITAS, true)) {
            $out = $this->consent($projectId, $out['name']) + $out;
        } elseif ($kind === 'special-case') {
            $out += $this->specialCase($account, $projectId, $caseId);
        } elseif ($kind === 'experience-test') {
            $out += $this->experienceTest($account, $city, $projectId);
        }
        $this->audit->record($account->id, 'papers.printed', 'succeeded', $city, subjectType: 'application_entity', subjectId: $projectId, decision: 'paper:'.$id);

        return $out;
    }

    /** Whether the project's donor is Caritas, by the donor's name in either language. */
    public static function isCaritas(int $projectId): bool
    {
        $donor = DB::table('projects as p')->join('donors as d', 'd.id', '=', 'p.donor_id')->where('p.id', $projectId)->first(['d.name_ar', 'd.name_en']);

        return $donor !== null && preg_match('/caritas|كاريتاس/iu', $donor->name_ar.' '.$donor->name_en) === 1;
    }

    /**
     * The names a consent writes: the beneficiary's (with the Latin spelling for the Polish column when it is stored), the members of their
     * family who are under eighteen today, and the project's name.
     *
     * @return array<string, mixed>
     */
    private function consent(int $projectId, string $name): array
    {
        $person = DB::table('project_applicants as a')->join('people as p', 'p.id', '=', 'a.person_id')->where('a.project_id', $projectId)->whereNull('a.deleted_at')
            ->orderByRaw('CASE WHEN a.role = ? THEN 0 ELSE 1 END', [\App\Services\Applications\ApplicationService::BENEFICIARY])->orderBy('a.id')->first(['p.id', 'p.full_name_en']);
        $children = [];
        if ($person !== null) {
            $families = DB::table('family_members')->where('person_id', $person->id)->whereNull('deleted_at')->pluck('family_id');
            $children = DB::table('family_members as m')->join('people as p', 'p.id', '=', 'm.person_id')->whereIn('m.family_id', $families)->whereNull('m.deleted_at')->whereNull('p.deleted_at')
                ->where('p.id', '!=', $person->id)->where('p.birth_date', '>', now()->subYears(18)->toDateString())->orderBy('p.birth_date')->orderBy('p.id')->limit(12)
                ->get(['p.id', 'p.first_name', 'p.father_name', 'p.last_name', 'p.full_name_en'])->unique('id')
                ->map(function ($c) {
                    $full = trim(implode(' ', array_filter([$c->first_name, $c->father_name, $c->last_name])));

                    return ['name' => $full, 'name_latin' => (string) ($c->full_name_en ?: $full)];
                })->values()->all();
        }

        return ['name_latin' => (string) (($person->full_name_en ?? '') ?: $name), 'children' => $children, 'project_name' => (string) DB::table('projects')->where('id', $projectId)->value('name')];
    }

    /** @return array<string, mixed> */
    private function specialCase(WebAccount $account, int $projectId, ?int $caseId): array
    {
        $page = $this->cases->page($account, $projectId);
        $case = $caseId === null ? null : ($page['cases']->firstWhere('id', $caseId) ?? abort(404));
        $type = (string) ($case->type ?? '');

        return ['case' => $case, 'mode' => str_contains($type, 'مقابل') || stripos($type, 'interview') !== false ? 'interview' : (str_contains($type, 'هاتف') || stripos($type, 'phone') !== false ? 'phone' : null)];
    }

    /** @return array<string, mixed> */
    private function experienceTest(WebAccount $account, int $city, int $projectId): array
    {
        $test = ['date' => '', 'tester' => '', 'result' => ''];
        if ($this->access->check($account, $city, 'assessments.view')) {
            $state = $this->checklists->load($account, $projectId);
            $h = $state['header'];
            if (($h['has_professional_test'] ?? '') === '1') {
                $tester = ($h['professional_tester_id'] ?? '') !== '' ? DB::table('checklist_members')->where('id', (int) $h['professional_tester_id'])->value('name_ar') : '';
                $test = ['date' => (string) ($h['professional_test_date'] ?? ''), 'tester' => (string) ($tester ?: ($h['professional_test_note'] ?? '')),
                    'result' => match ((string) ($h['professional_test_result'] ?? '')) { '1' => 'good', '0' => 'none', default => '' }];
            }
        }

        return ['test' => $test];
    }
}
