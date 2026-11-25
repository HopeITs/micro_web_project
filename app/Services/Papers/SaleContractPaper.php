<?php

declare(strict_types=1);

namespace App\Services\Papers;

use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\Finance\Money;
use App\Services\Finance\PurchasingService;
use App\Services\ProjectNumberAllocator;
use Illuminate\Support\Facades\DB;

/**
 * M4.20 wave 4: the two sale contracts: the final car sale (عقد بيع سيارة قطعي, F16, an unbranded sheet) and the sale and purchase contract of
 * a device (عقد بيع وشراء, F17). The seller is typed; the buyer is the city's legal representative, or the beneficiary when chosen at
 * printing. Until a contract is saved, the car contract takes its subject from the project's vehicle and the device contract takes its
 * subject, price and seller from the purchases. One contract of each kind per project (schema v1.6). Reading needs applications.view and
 * purchasing.view in the city, saving purchasing.manage. Audited `papers.printed` (`paper:F16` / `paper:F17`) and `papers.data_saved`.
 */
final class SaleContractPaper
{
    /** URL part => [paper id, sale_contracts.kind]. */
    public const KINDS = ['car' => ['F16', 'vehicle'], 'device' => ['F17', 'equipment']];

    public const BUYERS = ['representative', 'beneficiary'];

    private const COMMON = ['seller_name' => 255, 'seller_details' => 500, 'subject_text' => 600, 'price' => 'money', 'currency' => 'currency', 'signed_on' => 'date', 'witness_1' => 200, 'witness_2' => 200];

    /** The device contract has no blanks for the hand-over or the chosen address. */
    private const CAR_ONLY = ['handover_on' => 'date', 'handover_time' => 'time', 'notice_address' => 500];

    private const CURRENCY_WORDS = ['SYP' => 'ليرة سورية', 'USD' => 'دولاراً أمريكياً'];

    public function __construct(private PurchasingService $purchasing, private FinancePaper $parties, private AccountAuditTrail $audit) {}

    /** @return array<string, int|string> */
    public static function fields(string $kind): array
    {
        return match ($kind) {
            'car' => self::COMMON + self::CAR_ONLY,
            'device' => self::COMMON,
            default => abort(404),
        };
    }

    /** @return array<string, mixed> */
    public function forPrint(WebAccount $account, int $projectId, string $kind, string $buyer): array
    {
        [$id, $type] = self::KINDS[$kind] ?? abort(404);
        $buyer = in_array($buyer, self::BUYERS, true) ? $buyer : self::BUYERS[0];
        $project = $this->purchasing->project($account, $projectId);
        $city = DB::table('cities')->where('id', $project->city_id)->first();
        $contract = $this->contract($projectId, $type);
        $values = $contract ? (array) $contract : $this->suggestion($projectId, $kind);
        $party = $buyer === 'representative'
            ? $this->parties->identity(PaperSettings::signatory((int) $city->id, 'legal_representative'), true)
            : $this->parties->identity($this->parties->beneficiary($projectId));
        $price = Money::canonical($values['price'] ?? null);
        $this->audit->record($account->id, 'papers.printed', 'succeeded', (int) $city->id, subjectType: 'application_entity', subjectId: $projectId, decision: 'paper:'.$id);

        $text = fn (string $key) => (string) ($values[$key] ?? '');
        $day = fn (string $key) => $text($key) !== '' ? str_replace('-', '/', substr($text($key), 0, 10)) : '';

        return [
            'paper' => $id, 'kind' => $kind, 'buyer' => $buyer, 'projectId' => $projectId, 'city' => ['code' => (string) $city->code],
            'court' => (string) $city->name_ar, 'place' => PaperCity::placeName((string) $city->code),
            'number' => ProjectNumberAllocator::display((string) $city->code, (int) $project->project_number), 'party' => $party,
            'seller' => $text('seller_name'), 'seller_details' => $text('seller_details'), 'subject' => $text('subject_text'),
            'price' => $this->price($price, $text('currency')), 'car' => DeedPaper::vehicle(DB::table('project_vehicles')->where('project_id', $projectId)->whereNull('deleted_at')->orderByDesc('id')->first()),
            'signed_on' => $day('signed_on'), 'handover_on' => $day('handover_on'), 'handover_time' => $text('handover_time'),
            'notice' => $text('notice_address') !== '' ? $text('notice_address') : $party['address'], 'witness_1' => $text('witness_1'), 'witness_2' => $text('witness_2'),
        ];
    }

    /** @return array{number: string, canSave: bool, contracts: array<string, array{saved: bool, fields: array<string, array{value: string, hint: string, kind: int|string}>}>} */
    public function page(WebAccount $account, int $projectId): array
    {
        $project = $this->purchasing->project($account, $projectId);
        $code = (string) DB::table('cities')->where('id', $project->city_id)->value('code');
        $contracts = [];
        foreach (self::KINDS as $kind => [, $type]) {
            $record = $this->contract($projectId, $type);
            $values = $record ? (array) $record : $this->suggestion($projectId, $kind);
            $fields = [];
            foreach (self::fields($kind) as $field => $cast) {
                $stored = (string) ($values[$field] ?? '');
                $fields[$field] = ['kind' => $cast, 'hint' => '', 'value' => $stored === '' ? '' : match ($cast) {
                    'date' => substr($stored, 0, 10),
                    'money' => (string) Money::canonical($stored),
                    default => $stored,
                }];
            }
            $contracts[$kind] = ['saved' => $record !== null, 'fields' => $fields];
        }

        return ['number' => ProjectNumberAllocator::display($code, (int) $project->project_number), 'canSave' => $this->purchasing->canManage($account, (int) $project->city_id), 'contracts' => $contracts];
    }

    /** @param array<string, mixed> $input */
    public function save(WebAccount $account, int $projectId, string $kind, array $input): void
    {
        $fields = self::fields($kind);
        $project = $this->purchasing->project($account, $projectId);
        $city = (int) $project->city_id;
        if (! $this->purchasing->canManage($account, $city)) {
            $this->audit->record($account->id, 'access.denied', 'denied', $city);
            abort(403);
        }
        $clean = [];
        foreach ($fields as $field => $cast) {
            $clean[$field] = PaperInput::clean($input[$field] ?? null, $cast, $field);
        }
        DB::transaction(function () use ($account, $projectId, $kind, $city, $clean) {
            DB::table('projects')->where('id', $projectId)->lockForUpdate()->value('id');
            $changes = PaperInput::upsert('sale_contracts', $city, $projectId, $clean, ['kind' => self::KINDS[$kind][1]]);
            $this->audit->record($account->id, 'papers.data_saved', 'succeeded', $city, subjectType: 'application_entity', subjectId: $projectId, decision: 'paper-data:sale-'.$kind, details: $changes ?: null);
        });
    }

    private function contract(int $projectId, string $type): ?object
    {
        return DB::table('sale_contracts')->where('project_id', $projectId)->where('kind', $type)->whereNull('deleted_at')->orderByDesc('id')->first();
    }

    /** What the contract starts from before it is saved. @return array<string, string> */
    private function suggestion(int $projectId, string $kind): array
    {
        if ($kind === 'car') {
            $vehicle = DB::table('project_vehicles')->where('project_id', $projectId)->whereNull('deleted_at')->orderByDesc('id')->first();
            if ($vehicle === null) {
                return [];
            }
            $parts = array_filter([
                DeedPaper::vehicle($vehicle), ($vehicle->color ?? '') !== '' && $vehicle->color !== null ? 'اللون '.$vehicle->color : '',
                $vehicle->chassis_number ? 'رقم الهيكل '.$vehicle->chassis_number : '', $vehicle->engine_number ? 'رقم المحرك '.$vehicle->engine_number : '',
            ]);

            return ['subject_text' => implode('، ', $parts)];
        }
        $purchases = DB::table('purchases')->where('project_id', $projectId)->whereNull('deleted_at')->orderBy('id')->limit(20)->get();
        if ($purchases->isEmpty()) {
            return [];
        }
        $traders = $purchases->pluck('trader_name')->filter()->unique()->values();
        $currencies = $purchases->pluck('currency')->unique()->values();
        $subject = mb_substr($purchases->map(fn ($p) => trim($p->item_name.' '.($p->brand ?? '')).((int) $p->quantity > 1 ? ' (عدد '.$p->quantity.')' : ''))->implode('، '), 0, 600);

        // A price and a seller are suggested only when they are unambiguous: one currency, one trader.
        return ['subject_text' => $subject]
            + ($traders->count() === 1 ? ['seller_name' => (string) $traders[0]] : [])
            + ($currencies->count() === 1 ? ['price' => Money::sum($purchases->pluck('price')->map(fn ($p) => Money::canonical($p) ?? '0')), 'currency' => (string) $currencies[0]] : []);
    }

    private function price(?string $value, string $currency): string
    {
        if ($value === null || Money::isZero($value)) {
            return '';
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $figures = number_format((int) $whole).($fraction !== '' ? '.'.$fraction : '');
        $word = self::CURRENCY_WORDS[$currency] ?? $currency;

        // Words cover whole units only; an amount with a fraction prints its figures, never rounded words.
        return $fraction !== '' ? trim($figures.' '.$word) : trim($figures.' ('.ArabicAmountInWords::convert((int) $whole).' '.$word.')');
    }
}
