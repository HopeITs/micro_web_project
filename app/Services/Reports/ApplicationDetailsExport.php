<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\Applications\ApplicationFields;
use App\Services\Attachments\AttachmentService;
use App\Services\Papers\ApplicationPaper;
use App\Services\Reports\Search\SearchModes;
use App\Services\Reports\Search\SearchService;
use Illuminate\Support\Facades\DB;

/**
 * M4.18, the desktop's "all application details" (كل معلومات الاستمارة): one row per project of the applications search with every field
 * of the application form (project, beneficiary, applicant) in the form's order, and each internal table as one cell (a row per line). Every
 * value is a name in the page language, never an id. Cities are those where the account may read the applications search and export it
 * (applications.view, people.view, reports.export); each project is read through the application's own permission. Capped so one export
 * stays reasonable: narrow the filters above the cap. Audited `reports.exported` before the first byte.
 */
final class ApplicationDetailsExport
{
    public const MAX_PROJECTS = 3000;

    public function __construct(private SearchService $search, private ApplicationPaper $paper, private AccountAuditTrail $audit, private AttachmentService $attachments) {}

    public function canExport(WebAccount $account): bool
    {
        return $this->search->canExport($account, 'applications');
    }

    /** @return list<string> the column headings: city, project, stage, then the form's fields and tables */
    public function headings(): array
    {
        $h = [__('search.columns.city'), __('search.columns.number'), __('search.columns.stage'), __('search.columns.photo')];
        foreach ($this->fields() as [, , $label]) {
            $h[] = $label;
        }
        foreach (ApplicationFields::GRIDS as $kind => $_) {
            $h[] = __('applications.grids.'.$kind);
        }

        return $h;
    }

    /** @return list<array{0: string, 1: string, 2: string}> [part, field, label] in the form's order */
    private function fields(): array
    {
        $out = [];
        foreach ([['project', ApplicationFields::flat(ApplicationFields::PROJECT, 'full')], ['person', ApplicationFields::person('full')], ['applicant', ApplicationFields::flat(ApplicationFields::APPLICANT, 'full')]] as [$part, $specs]) {
            foreach (array_keys($specs) as $field) {
                $out[] = [$part, $field, self::label($field)];
            }
        }

        return $out;
    }

    private static function label(string $key): string
    {
        $base = str_starts_with($key, 'c_') ? substr($key, 2) : $key;
        foreach (['applications', 'people'] as $ns) {
            if (__($ns.'.fields.'.$base) !== $ns.'.fields.'.$base) {
                return __($ns.'.fields.'.$base);
            }
        }

        return str_replace('_', ' ', $base);
    }

    /** The link to the beneficiary's personal photo (opens only for accounts that may see it), or empty: the workbook is text, so a link instead of a picture. */
    private function photoLink(WebAccount $account, ?int $personId): string
    {
        if ($personId === null) {
            return '';
        }
        try {
            $photo = $this->attachments->photo($account, $personId);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            return '';
        }

        return $photo !== null && $photo->available ? url('/'.app()->getLocale().'/attachments/'.$photo->id) : '';
    }

    private static function word(string $value): string
    {
        return match ($value) {
            'yes' => __('people.yes'), 'no' => __('people.no'), 'male' => __('people.male'), 'female' => __('people.female'), default => $value,
        };
    }

    /**
     * Authorised and audited before it returns, so before the first byte.
     *
     * @return array{0: list<string>, 1: iterable<list<string>>, 2: int}
     */
    public function export(WebAccount $account, array $filters): array
    {
        $mode = SearchModes::make('applications')->for($account);
        $permitted = $this->search->cities($account, $mode, true);
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $filters = SearchService::filters($filters);
        $all = $permitted->pluck('id')->map(fn ($id) => (int) $id)->all();
        $chosen = array_values(array_intersect($filters['cities'], $all));
        $query = $mode->query($chosen !== [] ? $chosen : $all, $filters);
        $ids = (clone $query)->cloneWithout(['columns', 'orders'])->cloneWithoutBindings(['select', 'order'])->select('p.id as project_id', 'p.city_id', 'p.project_number')->distinct()
            ->orderBy('p.city_id')->orderBy('p.project_number')->pluck('project_id');
        if ($ids->count() > self::MAX_PROJECTS) {
            abort(422, __('search.details_too_many', ['max' => number_format(self::MAX_PROJECTS)]));
        }
        $this->audit->record($account->id, 'reports.exported', 'succeeded', decision: 'search:applications-details/rows:'.$ids->count());
        $fields = $this->fields();

        $rows = (function () use ($ids, $account, $fields) {
            $cities = DB::table('cities')->get(['id', 'name_ar', 'name_en'])->keyBy('id');
            foreach ($ids as $id) {
                $d = $this->paper->build($account, (int) $id);
                $city = $cities[$d['city']['id']];
                $line = [app()->isLocale('en') && $city->name_en ? $city->name_en : $city->name_ar, $d['number'], __('search.stage.'.$d['stage']), $this->photoLink($account, $d['person_id'] ?? null)];
                foreach ($fields as [$part, $field]) {
                    $line[] = self::word((string) ($d[$part][$field] ?? ''));
                }
                foreach (array_keys(ApplicationFields::GRIDS) as $kind) {
                    $line[] = collect($d['grids'][$kind] ?? [])->map(function (array $row) use ($kind) {
                        $cells = [];
                        foreach (ApplicationFields::grid($kind)['fields'] as $field => $_) {
                            $value = self::word((string) ($row[$field] ?? ''));
                            $value !== '' && $cells[] = self::label($field).': '.$value;
                        }

                        return implode('; ', $cells);
                    })->filter()->implode("\n");
                }
                yield $line;
            }
        })();

        return [$this->headings(), $rows, $ids->count()];
    }
}
