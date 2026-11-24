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
 * experience test (F06). Each is filled from the saved project (names, number, mobile, city) through {@see ApplicationPaper::build}, so the
 * reader needs applications.view in the city. Special Case also needs special_cases.view and fills the chosen case (blank without one);
 * the experience test fills the checklist's test fields only when the reader also holds assessments.view. Audited `papers.printed`.
 */
final class ProjectPaper
{
    /** URL part => paper id (the library's id), view, extra capability. */
    public const PAPERS = ['audio-consent' => 'F04', 'special-case' => 'F05', 'experience-test' => 'F06'];

    public function __construct(private ApplicationPaper $application, private SpecialCaseService $cases, private ChecklistService $checklists, private AccountAccess $access, private AccountAuditTrail $audit) {}

    /** @return array<string, mixed> */
    public function forPrint(WebAccount $account, int $projectId, string $kind, ?int $caseId = null): array
    {
        $id = self::PAPERS[$kind] ?? abort(404);
        $d = $this->application->build($account, $projectId);
        $city = (int) $d['city']['id'];
        $p = $d['person'];
        $out = [
            'paper' => $id, 'kind' => $kind, 'projectId' => $projectId, 'city' => $d['city'], 'number' => $d['number'], 'place' => PaperCity::placeName($d['city']['code']),
            'name' => trim(implode(' ', array_filter([$p['first_name'] ?? '', $p['father_name'] ?? '', $p['last_name'] ?? '']))), 'mobile' => (string) ($p['mobile'] ?? ''),
            'career' => (string) ($d['project']['career_id'] ?? ''), 'date' => now()->format('Y/m/d'),
        ];
        if ($kind === 'special-case') {
            $out += $this->specialCase($account, $projectId, $caseId);
        } elseif ($kind === 'experience-test') {
            $out += $this->experienceTest($account, $city, $projectId);
        }
        $this->audit->record($account->id, 'papers.printed', 'succeeded', $city, subjectType: 'application_entity', subjectId: $projectId, decision: 'paper:'.$id);

        return $out;
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
