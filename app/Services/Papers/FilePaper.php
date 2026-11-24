<?php

declare(strict_types=1);

namespace App\Services\Papers;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Assessments\ChecklistService;

/**
 * M4.18: the whole beneficiary file in one print, in procedure order: a cover with the contents, the application form (the preliminary
 * form while the project is preliminary), then the checklist. The application needs applications.view in the city (as ApplicationPaper
 * checks); the checklist joins only when the reader also holds assessments.manage there (as its own print needs), otherwise it is left out
 * and the cover says so. Audited once as `papers.printed` with `paper:FILE`.
 */
final class FilePaper
{
    public function __construct(private ApplicationPaper $application, private ChecklistService $checklists, private AccountAccess $access, private AccountAuditTrail $audit) {}

    /** @return array<string, mixed> */
    public function forPrint(WebAccount $account, int $projectId): array
    {
        $application = $this->application->build($account, $projectId);
        $checklist = $this->access->check($account, (int) $application['city']['id'], 'assessments.manage') ? $this->checklists->printable($account, $projectId) : null;
        $this->audit->record($account->id, 'papers.printed', 'succeeded', (int) $application['city']['id'], subjectType: 'application_entity', subjectId: $projectId, decision: 'paper:FILE');

        return ['d' => $application, 'checklist' => $checklist, 'projectId' => $projectId];
    }
}
