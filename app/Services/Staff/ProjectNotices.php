<?php

declare(strict_types=1);

namespace App\Services\Staff;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The notices the desktop generated as a side effect of other work (Loans_Form, ExecutiveFiles_Form, AdminPanel_From and the visit
 * forms), now addressed to web accounts through their roles, on the unified schema (M4.9: `projects`, `loan_installments`,
 * `web_notices.loan_installment_id` / `legacy_pay_key`). Every method runs inside the caller's transaction, under the project row lock,
 * and only reads or writes the project's own notices. Nothing is delivered outside the application.
 */
final class ProjectNotices
{
    /** Desktop sub-categories 1 and 2 (Taxi, Other Vehicles): their visit notices carry the letter V. */
    private const VEHICLE_SUBCATEGORIES = [1, 2];

    private const VEHICLE_NAMES = ['Taxi', 'Other Vehicles'];

    public function __construct(private NoticeService $notices) {}

    /** "first last ابن/ة father" of the project's beneficiary (the loan form's person name), or ''. */
    public function beneficiary(object $project): string
    {
        $person = DB::table('project_applicants as a')->join('people as p', 'p.id', '=', 'a.person_id')->where('a.project_id', $project->id)->whereNull('a.deleted_at')
            ->orderByRaw("CASE WHEN a.role = 'مستفيد' THEN 0 ELSE 1 END")->orderBy('a.id')->first(['p.first_name', 'p.last_name', 'p.father_name']);

        return $person === null ? '' : trim(trim(($person->first_name ?? '').' '.($person->last_name ?? '')).' ابن/ة '.($person->father_name ?? ''));
    }

    /** Whether the project has any shared visit notice, seen or not (the guard that stops a second schedule). */
    public function hasVisitNotices(object $project): bool
    {
        return $this->notices->ofProject($project)->whereIn('kind', NoticeService::SHARED)->exists();
    }

    /**
     * Visit notices of a new loan (`Loans_Form.generate_me_*_visits_notifications`), once per project: none is created when the project
     * already has any, or when the city has no account with the monitoring-and-evaluation duty (the desktop refused with a message). They
     * are shared copies carrying the kind letter V (vehicle sub-category 1 or 2) or O. Returns the number created.
     */
    public function generateVisitSchedule(object $project, string $loanDate, ?int $sender): int
    {
        $city = (int) $project->city_id;
        if ($this->hasVisitNotices($project) || ! $this->notices->hasMonitoring($city)) {
            return 0;
        }
        $type = (string) (DB::table('project_types')->where('id', $project->project_type_id ?? 0)->value('name_ar') ?? '');
        $fund = (string) (DB::table('fund_types')->where('id', $project->fund_type_id ?? 0)->value('name_ar') ?? '');
        $letter = $this->letter($project);
        $opening = CarbonImmutable::parse($loanDate)->startOfDay()->addDays(5);
        $kind = match (true) {
            str_contains($type, 'زراع') => 'agricultural',
            str_contains($type, 'طاقة شمسية') => 'solar',
            str_contains($type, 'ترميم') && str_contains($fund, 'منحة') => 'rehabilitation',
            default => 'standard',
        };
        $suffix = $kind === 'solar' ? ' (Solar)' : '';
        $visits = match ($kind) {
            'rehabilitation' => ['Visit Closing-R' => $opening],
            'agricultural' => ['Visit Opening-'.$letter => $opening] + $this->agriculturalVisits($opening, $letter),
            'solar' => ['Visit Opening-'.$letter.$suffix => $opening] + $this->sixMonthly($opening, ['M1', 'M2'], $letter.$suffix),
            default => ['Visit Opening-'.$letter => $opening] + $this->sixMonthly($opening, ['M1', 'M2', 'M3', 'MFinal'], $letter),
        };
        $name = $this->beneficiary($project);
        foreach ($visits as $body => $date) {
            $this->notices->add($city, 0, $project, NoticeService::VISIT, $date->toDateString(), $name, $body, null, $sender);
        }

        return count($visits);
    }

    /** V for the desktop's vehicle sub-categories (through the city's list mapping, else by name), O otherwise. */
    private function letter(object $project): string
    {
        $sub = $project->project_subcategory_id ?? null;
        if ($sub === null) {
            return 'O';
        }
        $legacy = DB::table('lookup_legacy_map')->where('city_id', $project->city_id)->where('lookup_table', 'project_subcategories')->where('lookup_id', $sub)->value('legacy_id');
        if ($legacy !== null) {
            return in_array((int) $legacy, self::VEHICLE_SUBCATEGORIES, true) ? 'V' : 'O';
        }
        $names = DB::table('project_subcategories')->where('id', $sub)->first(['name_ar', 'name_en']);

        return $names !== null && (in_array($names->name_ar, self::VEHICLE_NAMES, true) || in_array($names->name_en, self::VEHICLE_NAMES, true)) ? 'V' : 'O';
    }

    /**
     * Visits six months apart, each counted from the previous one (so a month-end clamp carries on, as the desktop's chained `AddMonths`).
     *
     * @param  list<string>  $labels
     * @return array<string, CarbonImmutable>
     */
    private function sixMonthly(CarbonImmutable $from, array $labels, string $suffix): array
    {
        $visits = [];
        foreach ($labels as $label) {
            $from = $from->addMonthsNoOverflow(6);
            $visits['Visit '.$label.'-'.$suffix] = $from;
        }

        return $visits;
    }

    /**
     * M1–M5 of an agricultural project: the first M&E visit falls in the April or November nearest two months after the opening, then they
     * alternate seven and five months apart (the desktop's calendar, with .NET month arithmetic).
     *
     * @return array<string, CarbonImmutable>
     */
    private function agriculturalVisits(CarbonImmutable $opening, string $letter): array
    {
        $reference = $opening->addMonthsNoOverflow(2);
        $day = $reference->day === 31 ? 30 : $reference->day;
        if ($reference->month < 4) {
            $first = CarbonImmutable::create($reference->year, 4, $day);
            $steps = [7, 5, 7, 5];
        } elseif ($reference->month < 11) {
            $first = CarbonImmutable::create($reference->year, 11, $day);
            $steps = [5, 7, 5, 7];
        } else {
            $first = CarbonImmutable::create($reference->year + 1, 4, $day);
            $steps = [7, 5, 7, 5];
        }
        $visits = ['Visit M1-'.$letter => $first];
        $date = $first;
        foreach ($steps as $index => $months) {
            $date = $date->addMonthsNoOverflow($months);
            $visits['Visit M'.($index + 2).'-'.$letter] = $date;
        }

        return $visits;
    }

    /**
     * The payment notices of one instalment: those linked to it (web rows) and, for an imported instalment, those carrying its desktop
     * payment number (`legacy_pay_key`). `$installment` is a `loan_installments` row (id, city_id, legacy_id).
     */
    public function ofInstallment(object $installment): Builder
    {
        return DB::table('web_notices')->where('city_id', $installment->city_id)->where('kind', NoticeService::PAYMENT)
            ->where(function (Builder $q) use ($installment) {
                $q->where('loan_installment_id', $installment->id);
                if (($installment->legacy_id ?? null) !== null) {
                    $q->orWhere(fn (Builder $l) => $l->whereNull('loan_installment_id')->where('legacy_pay_key', $installment->legacy_id));
                }
            });
    }

    /** One notice per financial account (the cashier duty is the financial role) for a new unpaid instalment: dated its due date, the body is its amount. */
    public function paymentDue(object $project, object $installment, string $amount, string $due, ?int $sender): int
    {
        return $this->notices->broadcast((int) $project->city_id, 0, $project, 'payment', NoticeService::PAYMENT, $due, $this->beneficiary($project), $amount, $sender,
            installmentId: (int) $installment->id);
    }

    /** `Update_NotificationWithPaymentID`: the instalment's notices follow its amount and due date, and are seen exactly when it is paid. */
    public function paymentChanged(object $installment, string $amount, string $due, bool $paid): int
    {
        return $this->ofInstallment($installment)->update(['body' => $amount, 'notice_date' => $due, 'seen' => $paid ? 1 : 0]);
    }

    /**
     * The clearance notice after the last instalment is paid (`Loans_Form.Send_Trophy_Notifications`): a shared notice the day after the last
     * payment and one trophy notice per communication account. Nothing is created when the project already has that clearance notice.
     */
    public function clearance(object $project, string $lastReceived, ?int $sender): bool
    {
        $date = CarbonImmutable::parse($lastReceived)->addDay()->toDateString();
        if ($this->notices->ofProject($project)->where('kind', NoticeService::CLEARANCE)->where('notice_date', $date)->exists()) {
            return false;
        }
        $name = $this->beneficiary($project);
        $city = (int) $project->city_id;
        $this->notices->add($city, 0, $project, NoticeService::CLEARANCE, $date, $name, 'براءة الذمة (Clearance)', null, $sender);
        $this->notices->broadcast($city, 0, $project, 'communication', NoticeService::TROPHY, $date, $name, 'Visit (Trophy)'.$this->letter($project), $sender);

        return true;
    }

    /**
     * What a deleted loan leaves behind (owner decision, 21 Nov 2026): the payment notices of its instalments and the visit notices nobody
     * acted on yet. Notices have no soft delete, so they are removed; returns how many.
     *
     * @param  iterable<object>  $installments
     */
    public function removeLoan(object $project, iterable $installments): int
    {
        $removed = 0;
        foreach ($installments as $installment) {
            $removed += $this->ofInstallment($installment)->delete();
        }

        return $removed + $this->notices->ofProject($project)->where('kind', NoticeService::VISIT)->where('seen', false)->delete();
    }

    /**
     * The executive-file expiry notice for the legal role, dated the expiry date. On creation each legal account gets one; on an edit the
     * existing notices follow the new date.
     */
    public function executiveFile(object $project, ?string $expiry, bool $created, ?int $sender): int
    {
        if ($expiry === null) {
            return 0;
        }
        if (! $created) {
            return $this->notices->ofProject($project)->where('kind', NoticeService::EXECUTIVE_FILE)->update(['notice_date' => $expiry, 'sender_account_id' => $sender]);
        }

        return $this->notices->broadcast((int) $project->city_id, 0, $project, 'executive_file', NoticeService::EXECUTIVE_FILE, $expiry, $this->beneficiary($project), 'الملف التنفيذي', $sender);
    }

    /** The project's executive-file notices go with a deleted file (M4.10; notices have no soft delete). Returns how many were removed. */
    public function removeExecutiveFile(object $project): int
    {
        return $this->notices->ofProject($project)->where('kind', NoticeService::EXECUTIVE_FILE)->delete();
    }

    /** `Update_UserNotification(project, -1)`: every notice of the project is marked seen, whoever it is addressed to. */
    public function clearProject(object $project): int
    {
        return $this->notices->ofProject($project)->update(['seen' => 1]);
    }

    /** The project's shared visit notices of one text are marked seen (optionally only the one titled with the person's name). */
    public function visitDone(object $project, string $body, ?string $name = null): int
    {
        $query = $this->notices->ofProject($project)->where('kind', NoticeService::VISIT)->where('body', $body);
        if ($name !== null) {
            $query->where('title', $name);
        }

        return $query->update(['seen' => 1]);
    }

    /**
     * The state-change notice every account of the city's staff roles receives (`AdminPanel_From`): "تم تبديل حالة المشروع إلى: <state>".
     * Not sent for Rejected or Waiting. `$state` is a `project_states` row.
     */
    public function stateChanged(object $project, object $state, ?int $sender): int
    {
        $en = $state->name_en ?? $state->c_Name_en ?? '';
        if (in_array($en, ['Rejected', 'Waiting'], true)) {
            return 0;
        }
        $body = 'تم تبديل حالة المشروع إلى: '.($state->name_ar ?? $state->c_Name_ar ?? $en);

        return $this->notices->broadcast((int) $project->city_id, 0, $project, 'state', NoticeService::STATE, now()->toDateString(), $this->beneficiary($project), $body, $sender);
    }
}
