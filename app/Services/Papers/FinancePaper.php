<?php

declare(strict_types=1);

namespace App\Services\Papers;

use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\Finance\FinanceService;
use App\Services\Finance\Money;
use Illuminate\Support\Facades\DB;

/**
 * M4.20 wave 2: the financial and legal papers of a project redrawn on the print engine: the promissory note (F10, سند أمانة), the clearance
 * deed (F23, صك براءة ذمة), the disbursement request (F19, طلب صرف) and the partnership contract (F18, عقد شركة محاصة). They are filled from
 * the saved project: the beneficiary's identity, the loan, the purchases, the stored contract / request details and the centre's signatories
 * of the city (schema v1.6). What is not stored prints as a dotted line to fill by hand. The reader needs applications.view and finance.view
 * in the city (FinanceService::project). Audited `papers.printed` (`paper:F10` …). Non-Syrian beneficiaries (people.nationality = other)
 * print the identity document instead of the national number, as the owner's separate non-Syrian copies do.
 */
final class FinancePaper
{
    /** URL part => paper id. */
    public const PAPERS = ['promissory-note' => 'F10', 'clearance' => 'F23', 'disbursement-request' => 'F19', 'partnership-contract' => 'F18'];

    private const CURRENCY_WORDS = ['SYP' => 'ليرة سورية', 'USD' => 'دولاراً أمريكياً'];

    public function __construct(private FinanceService $finance, private AccountAuditTrail $audit) {}

    /** @return array<string, mixed> */
    public function forPrint(WebAccount $account, int $projectId, string $kind): array
    {
        $id = self::PAPERS[$kind] ?? abort(404);
        $project = $this->finance->project($account, $projectId);
        $city = DB::table('cities')->where('id', $project->city_id)->first();
        $loan = $this->finance->loan($projectId);
        $person = $this->beneficiary($projectId);
        $en = app()->isLocale('en');

        $d = [
            'paper' => $id, 'kind' => $kind, 'projectId' => $projectId, 'city' => ['id' => (int) $city->id, 'code' => (string) $city->code, 'name' => $en && $city->name_en ? $city->name_en : $city->name_ar],
            'place' => PaperCity::placeName((string) $city->code), 'number' => \App\Services\ProjectNumberAllocator::display((string) $city->code, (int) $project->project_number),
            'date' => now()->format('Y/m/d'), 'project' => $project, 'person' => $this->identity($person), 'rep' => $this->signatory((int) $city->id, 'legal_representative'),
            'amount' => $this->amount($loan),
        ];
        match ($kind) {
            'disbursement-request' => $d += $this->disbursement($project, $loan, $person, (int) $city->id),
            'partnership-contract' => $d += $this->partnership($project, $person),
            default => null,
        };
        $this->audit->record($account->id, 'papers.printed', 'succeeded', (int) $city->id, subjectType: 'application_entity', subjectId: $projectId, decision: 'paper:'.$id);

        return $d;
    }

    private function beneficiary(int $projectId): ?object
    {
        $link = DB::table('project_applicants')->where('project_id', $projectId)->whereNull('deleted_at')->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [\App\Services\Applications\ApplicationService::BENEFICIARY])->orderBy('id')->first();

        return $link ? DB::table('people')->where('id', $link->person_id)->first() : null;
    }

    /** The identity lines the legal papers print for a person (or the signatory row, which has the same shape). @return array<string, string> */
    private function identity(?object $p, bool $signatory = false): array
    {
        if ($p === null) {
            return array_fill_keys(['name', 'triple', 'father', 'mother', 'birth', 'registry', 'national', 'issue', 'other', 'doc_type', 'doc_number', 'address', 'mobile', 'name_en'], '');
        }
        $name = $signatory ? (string) $p->full_name : trim(implode(' ', array_filter([$p->first_name ?? '', $p->last_name ?? ''])));
        $birth = $p->birth_date ?? null;

        return [
            'name' => $name, 'triple' => $signatory ? $name : trim(implode(' ', array_filter([$p->first_name ?? '', $p->father_name ?? '', $p->last_name ?? '']))), 'father' => (string) ($p->father_name ?? ''), 'mother' => (string) ($p->mother_name ?? ''), 'birth' => $birth ? substr((string) $birth, 0, 10) : '',
            'registry' => (string) ($p->registration_place ?? ''), 'national' => (string) ($p->national_number ?? ''), 'issue' => (string) ($p->id_issue_text ?? ''),
            'other' => ($p->nationality ?? 'syrian') === 'other' ? '1' : '', 'doc_type' => (string) ($p->id_document_type ?? ''), 'doc_number' => (string) ($p->id_document_number ?? ''),
            'address' => (string) ($p->home_address ?? ''), 'mobile' => (string) ($p->mobile ?? ''), 'name_en' => (string) ($p->full_name_en ?? ''),
        ];
    }

    /** @return array<string, string> the centre's signatory of a role in the city today, in the identity shape (empty strings when none) */
    private function signatory(int $city, string $role): array
    {
        return $this->identity(PaperSettings::signatory($city, $role), true);
    }

    /** @return array<string, string> the loan in figures and words */
    private function amount(?object $loan): array
    {
        $blank = ['figures' => '', 'words' => '', 'currency' => ''];
        if ($loan === null || (string) ($loan->amount ?? '') === '') {
            return $blank;
        }
        $value = Money::canonical($loan->amount) ?? '';
        $currency = (string) $loan->currency;
        $whole = (int) explode('.', $value)[0];

        return ['figures' => number_format($whole).(str_contains($value, '.') && (int) explode('.', $value)[1] > 0 ? '.'.explode('.', $value)[1] : ''), 'currency' => $currency,
            'words' => ArabicAmountInWords::convert($whole).' '.(self::CURRENCY_WORDS[$currency] ?? $currency).' فقط لا غير'];
    }

    /** @return array<string, mixed> */
    private function disbursement(object $project, ?object $loan, ?object $person, int $city): array
    {
        $request = DB::table('disbursement_requests')->where('project_id', $project->id)->whereNull('deleted_at')->orderByDesc('id')->first();
        $purchases = DB::table('purchases')->where('project_id', $project->id)->whereNull('deleted_at')->orderBy('id')->get();
        $total = Money::sum($purchases->pluck('price')->map(fn ($p) => Money::canonical($p) ?? '0'));
        $first = $loan ? DB::table('loan_installments')->where('loan_id', $loan->id)->whereNull('deleted_at')->whereNotNull('due_on')->orderBy('due_on')->value('due_on') : null;
        $fmt = fn ($v) => $v === null || $v === '' ? '' : number_format((float) $v);

        return [
            'request' => ['number' => (string) ($request->request_number ?? ''), 'date' => $request && $request->requested_on ? str_replace('-', '/', substr((string) $request->requested_on, 0, 10)) : '',
                'hold' => $fmt($request->hold_amount ?? null), 'extra' => $fmt($request->extra_amount ?? null), 'extra_reason' => (string) ($request->extra_reason ?? '')],
            'donor' => (string) ($project->donor_id ? DB::table('donors')->where('id', $project->donor_id)->value(app()->isLocale('en') ? 'name_en' : 'name_ar') : ''),
            'requested' => $fmt($project->requested_amount), 'rate' => $loan && $loan->rate !== null ? rtrim(rtrim((string) $loan->rate, '0'), '.') : '', 'installment' => $fmt($loan->installment_amount ?? null),
            'first_due' => $first ? str_replace('-', '/', substr((string) $first, 0, 10)) : '',
            'invoices' => $purchases->map(fn ($r) => ['amount' => number_format((float) $r->price), 'number' => (string) $r->invoice_number, 'description' => trim($r->item_name.' '.($r->brand ?? '')), 'notes' => (string) ($r->trader_name ?? '')])->all(),
            'invoice_total' => $purchases->isEmpty() ? '' : number_format((float) $total),
            'manager' => $this->signatory($city, 'projects_manager')['name'], 'finance' => $this->signatory($city, 'finance_manager')['name'], 'purchasing' => $this->signatory($city, 'purchasing_officer')['name'],
        ];
    }

    /** @return array<string, mixed> */
    private function partnership(object $project, ?object $person): array
    {
        $c = DB::table('partnership_contracts')->where('project_id', $project->id)->whereNull('deleted_at')->orderByDesc('id')->first();
        $equipment = DB::table('purchases')->where('project_id', $project->id)->whereNull('deleted_at')->orderBy('id')->pluck('item_name')->filter()->implode('، ');
        $day = fn ($v) => $v ? str_replace('-', '/', substr((string) $v, 0, 10)) : '';

        return ['contract' => [
            'work' => (string) ($c->work_text ?? '') !== '' ? $c->work_text : (string) ($project->career_id ? DB::table('careers')->where('id', $project->career_id)->value(app()->isLocale('en') ? 'name_en' : 'name_ar') : ''),
            'purpose' => (string) ($c->purpose ?? '') !== '' ? $c->purpose : (string) $project->name, 'equipment' => (string) ($c->financed_equipment ?? '') !== '' ? $c->financed_equipment : $equipment,
            'profit_period' => (string) ($c->profit_period ?? ''), 'duration' => $c && $c->duration_months ? (string) $c->duration_months : '', 'starts' => $day($c->starts_on ?? null), 'ends' => $day($c->ends_on ?? null),
            'address' => (string) ($c->notice_address ?? '') !== '' ? $c->notice_address : (string) ($person->home_address ?? ''),
        ]];
    }
}
