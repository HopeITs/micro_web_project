<?php

declare(strict_types=1);

namespace App\Services\Reports\Search;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\Reports\ProjectReportFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Attachments" (desktop `bind_Images`): what is recorded for each project, by attachment type. Only the list: the stored path and the file
 * itself are never part of a report (files open through the audited download route).
 *
 * As the desktop narrowed image types by role (owner decision of 27 Oct 2026, M4.11), an account sees only the attachments whose category it
 * holds the grant for in the city (`attachments.personal`, `.legal`, `.financial`). A type without a known category needs all three.
 */
final class AttachmentSearch extends SearchMode
{
    public function key(): string
    {
        return 'attachments';
    }

    public function group(): string
    {
        return 'applications';
    }

    public function capabilities(): array
    {
        return ['projects.view', 'attachments.download'];
    }

    public function columns(): array
    {
        return ['number', 'beneficiary', 'career', 'attachment_type', 'category', 'uploaded_at'];
    }

    public function cityAllowed(WebAccount $account, int $city): bool
    {
        return $this->granted($account, $city) !== [];
    }

    /** @return list<string> the attachment categories the account may see in the city */
    private function granted(WebAccount $account, int $city): array
    {
        return array_keys(array_filter(AccountAccess::ATTACHMENT_CATEGORIES, fn (string $grant) => $this->access->check($account, $city, $grant)));
    }

    public function query(array $cityIds, array $filters): Builder
    {
        $q = $this->joinProject(DB::table('attachments as at')->whereNull('at.deleted_at'), 'at.project_id', $cityIds)
            ->leftJoin('attachment_types as aty', 'aty.id', '=', 'at.attachment_type_id')
            ->addSelect([...$this->baseColumns(), 'at.id as attachment_id', 'at.uploaded_at', 'aty.category', 'aty.name_ar as type_ar', 'aty.name_en as type_en']);
        $this->lookup($q, 'careers', 'cr', 'p.career_id', 'career');

        // Per city, only the types whose category the account holds the grant for; a type of an unknown category needs every grant.
        $types = DB::table('attachment_types')->get(['id', 'category']);
        $q->where(function ($any) use ($cityIds, $types) {
            $any->whereRaw('1 = 0');
            foreach ($cityIds as $city) {
                $granted = $this->actor === null ? [] : $this->granted($this->actor, $city);
                $all = count($granted) === count(AccountAccess::ATTACHMENT_CATEGORIES);
                $allowed = $types->filter(fn ($t) => in_array((string) $t->category, $granted, true) || ($all && ! isset(AccountAccess::ATTACHMENT_CATEGORIES[(string) $t->category])))->pluck('id')->all();
                // An attachment without any type is an unknown category too.
                $any->orWhere(function ($c) use ($city, $allowed, $all) {
                    $c->where('p.city_id', $city)->where(function ($t) use ($allowed, $all) {
                        $t->whereIn('at.attachment_type_id', $allowed ?: [0]);
                        $all && $t->orWhereNull('at.attachment_type_id');
                    });
                });
            }
        });

        return ProjectReportFilters::apply($q, $filters);
    }

    public function order(Builder $query): Builder
    {
        return $query->orderBy('p.project_number')->orderBy('p.city_id')->orderBy('at.id');
    }

    public function lines(Collection $rows): array
    {
        $people = $this->applicants($rows->pluck('project_id')->all());

        return $rows->map(fn ($r) => [
            $this->number($r), $people[$r->project_id]['all'] ?? '', $this->name($r, 'career'), $this->name($r, 'type'), (string) $r->category, $this->day($r->uploaded_at),
        ])->all();
    }
}
