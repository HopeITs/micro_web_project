<?php

declare(strict_types=1);

namespace App\Services\Papers;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Finance\FinanceService;
use App\Services\Finance\Money;
use App\Services\ProjectNumberAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * M4.20 wave 4: the five pledge deeds (صك إقرار وتعهد): tools F11, agricultural tools F12, solar F13, agricultural solar F14 and vehicles
 * F15. The identity comes from the beneficiary (a non-Syrian prints the identity document), "السيد" is the city's legal representative, the
 * instalment and the recovery ratio come from the loan, and the grace period is the whole months from the loan date to the first instalment
 * unless one is typed. Terms, the solar system and the vehicle are kept per project (schema v1.6). Reading needs applications.view and
 * finance.view in the city; saving needs finance.manage or executive_files.manage (finance and legal staff). Audited `papers.printed`
 * (`paper:F11` …) and `papers.data_saved`.
 */
final class DeedPaper
{
    /** Deed kind => paper id. */
    public const KINDS = ['tools' => 'F11', 'tools_agri' => 'F12', 'solar' => 'F13', 'solar_agri' => 'F14', 'vehicles' => 'F15'];

    private const SEASONAL = ['season_start_on' => 'date', 'medicines_amount' => 'money', 'delivery_month_year' => 'month', 'recovery_rate' => 'rate'];

    private const MONTHLY = ['grace_months' => 'months', 'recovery_rate' => 'rate'];

    private const SOLAR = ['description' => 500];

    private const VEHICLE = ['make' => 100, 'model' => 100, 'model_year' => 'year', 'color' => 50, 'plate_number' => 30, 'chassis_number' => 60, 'engine_number' => 60];

    private const MONTHS = [1 => 'كانون الثاني', 'شباط', 'آذار', 'نيسان', 'أيار', 'حزيران', 'تموز', 'آب', 'أيلول', 'تشرين الأول', 'تشرين الثاني', 'كانون الأول'];

    private const CURRENCY_WORDS = ['SYP' => 'ليرة سورية', 'USD' => 'دولاراً أمريكياً'];

    public function __construct(private FinanceService $finance, private FinancePaper $parties, private AccountAccess $access, private AccountAuditTrail $audit) {}

    /** The fields of a deed's entry form: its own terms, and the solar system or the vehicle it names. @return array<string, array<string, int|string>> */
    public static function fields(string $kind): array
    {
        return match ($kind) {
            'tools' => ['terms' => self::MONTHLY],
            'tools_agri' => ['terms' => self::SEASONAL],
            'solar' => ['terms' => self::MONTHLY, 'solar' => self::SOLAR],
            'solar_agri' => ['terms' => self::SEASONAL, 'solar' => self::SOLAR],
            'vehicles' => ['terms' => self::MONTHLY, 'vehicle' => self::VEHICLE],
            default => abort(404),
        };
    }

    public function canSave(WebAccount $account, int $city): bool
    {
        return $this->access->check($account, $city, 'finance.manage') || $this->access->check($account, $city, 'executive_files.manage');
    }

    /** @return array<string, mixed> */
    public function forPrint(WebAccount $account, int $projectId, string $kind): array
    {
        $id = self::KINDS[$kind] ?? abort(404);
        $project = $this->finance->project($account, $projectId);
        $city = DB::table('cities')->where('id', $project->city_id)->first();
        $loan = $this->finance->loan($projectId);
        $terms = $this->row('project_deed_terms', $projectId, ['deed_kind' => $kind]);
        $solar = $this->row('project_solar_systems', $projectId);
        $vehicle = $this->row('project_vehicles', $projectId);
        $representative = PaperSettings::signatory((int) $city->id, 'legal_representative');

        $grace = $terms?->grace_months ?? $this->derivedGrace($loan);
        $rate = Money::canonical($terms?->recovery_rate ?? $loan?->rate ?? null);
        $month = (string) ($terms->delivery_month_year ?? '');
        $seasonal = str_ends_with($kind, '_agri');
        $values = [
            'rep' => (string) ($representative->full_name ?? ''),
            'instalment' => $this->money($loan?->installment_amount, (string) ($loan->currency ?? ''), $seasonal),
            'grace' => $grace === null ? '' : self::months((int) $grace),
            'ratio' => $rate === null ? '' : $rate.'%',
            // The paper's "قسط سنوي موسمي …" names the season of each year; the program does not store it, so it is written by hand.
            'season' => '',
            'season_start' => ($terms->season_start_on ?? null) ? str_replace('-', '/', substr((string) $terms->season_start_on, 0, 10)) : '',
            'medicines' => $this->money($terms->medicines_amount ?? null, (string) ($loan->currency ?? ''), false),
            'month' => preg_match('/^\d{4}-(\d{2})$/', $month, $m) === 1 ? (self::MONTHS[(int) $m[1]] ?? '') : '',
            'year' => preg_match('/^(\d{4})-\d{2}$/', $month, $m) === 1 ? $m[1] : '',
            'system' => (string) ($solar->description ?? ''),
            'vehicle' => self::vehicle($vehicle),
        ];
        $this->audit->record($account->id, 'papers.printed', 'succeeded', (int) $city->id, subjectType: 'application_entity', subjectId: $projectId, decision: 'paper:'.$id);

        return [
            'paper' => $id, 'kind' => $kind, 'projectId' => $projectId, 'city' => ['code' => (string) $city->code],
            'number' => ProjectNumberAllocator::display((string) $city->code, (int) $project->project_number),
            'person' => $this->parties->identity($this->parties->beneficiary($projectId)), 'values' => $values,
        ];
    }

    /** @return array{number: string, canSave: bool, deeds: array<string, array<string, array{value: string, hint: string, kind: int|string}>>} */
    public function page(WebAccount $account, int $projectId): array
    {
        $project = $this->finance->project($account, $projectId);
        $code = (string) DB::table('cities')->where('id', $project->city_id)->value('code');
        $loan = $this->finance->loan($projectId);
        $shared = ['solar' => $this->row('project_solar_systems', $projectId), 'vehicle' => $this->row('project_vehicles', $projectId)];
        $hints = ['grace_months' => (string) ($this->derivedGrace($loan) ?? ''), 'recovery_rate' => (string) Money::canonical($loan->rate ?? null)];
        $deeds = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $terms = $this->row('project_deed_terms', $projectId, ['deed_kind' => $kind]);
            foreach (self::fields($kind) as $group => $fields) {
                $source = $group === 'terms' ? $terms : $shared[$group];
                foreach ($fields as $field => $type) {
                    $stored = $source->{$field} ?? null;
                    $deeds[$kind][$field] = ['kind' => $type, 'hint' => $hints[$field] ?? '', 'value' => $stored === null ? '' : match ($type) {
                        'date' => substr((string) $stored, 0, 10),
                        'money', 'rate' => (string) Money::canonical($stored),
                        default => (string) $stored,
                    }];
                }
            }
        }

        return ['number' => ProjectNumberAllocator::display($code, (int) $project->project_number), 'canSave' => $this->canSave($account, (int) $project->city_id), 'deeds' => $deeds];
    }

    /** @param array<string, mixed> $input */
    public function save(WebAccount $account, int $projectId, string $kind, array $input): void
    {
        $groups = self::fields($kind);
        $project = $this->finance->project($account, $projectId);
        $city = (int) $project->city_id;
        if (! $this->canSave($account, $city)) {
            $this->audit->record($account->id, 'access.denied', 'denied', $city);
            abort(403);
        }
        $clean = [];
        foreach ($groups as $group => $fields) {
            foreach ($fields as $field => $type) {
                $clean[$group][$field] = PaperInput::clean($input[$field] ?? null, $type, $field);
            }
        }
        DB::transaction(function () use ($account, $projectId, $kind, $city, $clean) {
            DB::table('projects')->where('id', $projectId)->lockForUpdate()->value('id');
            $changes = PaperInput::upsert('project_deed_terms', $city, $projectId, $clean['terms'], ['deed_kind' => $kind]);
            if (isset($clean['solar'])) {
                $changes = array_merge($changes, PaperInput::upsert('project_solar_systems', $city, $projectId, $clean['solar'], prefix: 'solar.'));
            }
            if (isset($clean['vehicle'])) {
                $changes = array_merge($changes, PaperInput::upsert('project_vehicles', $city, $projectId, $clean['vehicle'], prefix: 'vehicle.'));
            }
            $this->audit->record($account->id, 'papers.data_saved', 'succeeded', $city, subjectType: 'application_entity', subjectId: $projectId, decision: 'paper-data:deed-'.$kind, details: $changes ?: null);
        });
    }

    /** The short description a paper writes wherever it names the vehicle. */
    public static function vehicle(?object $vehicle): string
    {
        if ($vehicle === null) {
            return '';
        }
        $name = trim(implode(' ', array_filter([$vehicle->make ?? '', $vehicle->model ?? '', $vehicle->model_year ?? ''])));
        $plate = (string) ($vehicle->plate_number ?? '');

        return trim($name.($plate !== '' ? ' رقم '.$plate : ''));
    }

    /** An Arabic count of months: شهر واحد، شهرين، 3 أشهر، 11 شهراً. */
    public static function months(int $count): string
    {
        return match (true) {
            $count === 0 => '',
            $count === 1 => 'شهر واحد',
            $count === 2 => 'شهرين',
            $count <= 10 => $count.' أشهر',
            default => $count.' شهراً',
        };
    }

    private function row(string $table, int $projectId, array $match = []): ?object
    {
        return DB::table($table)->where('project_id', $projectId)->where($match)->whereNull('deleted_at')->orderByDesc('id')->first();
    }

    /** Whole months from the day the loan was taken to its first instalment; null when either date is missing or less than a month apart. */
    private function derivedGrace(?object $loan): ?int
    {
        if ($loan === null || empty($loan->taken_on)) {
            return null;
        }
        $first = DB::table('loan_installments')->where('loan_id', $loan->id)->whereNull('deleted_at')->whereNotNull('due_on')->orderBy('due_on')->value('due_on');
        if ($first === null) {
            return null;
        }
        $months = (int) CarbonImmutable::parse(substr((string) $loan->taken_on, 0, 10))->diffInMonths(CarbonImmutable::parse(substr((string) $first, 0, 10)), false);

        return $months >= 1 && $months <= 120 ? $months : null;
    }

    /** "125,000 ليرة سورية (…)" with the amount in words; `$only` adds "فقط لا غير" where the paper does not print it after the blank. */
    private function money(mixed $amount, string $currency, bool $only): string
    {
        $value = Money::canonical($amount);
        if ($value === null || Money::isZero($value)) {
            return '';
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $figures = number_format((int) $whole).($fraction !== '' ? '.'.$fraction : '');
        $word = self::CURRENCY_WORDS[$currency] ?? $currency;
        // Words cover whole units only; an amount with a fraction prints its figures, never rounded words.
        if ($fraction !== '') {
            return trim($figures.' '.$word);
        }

        return trim($figures.' ('.ArabicAmountInWords::convert((int) $whole).' '.$word.($only ? ' فقط لا غير' : '').')');
    }
}
