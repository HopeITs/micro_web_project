<?php

declare(strict_types=1);

namespace App\Services\Lookups;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maintaining the lists (M4.2, owner decisions of 20 Nov 2026): `lookups.manage` is held by the administrator and data-entry roles;
 * a city list (streets, priests, checklist members) is edited by an account holding it in that city, a shared list only by an account
 * holding it in every city, because one edit changes the list everywhere. Deleting a value that records use only hides it (it leaves
 * new forms and stays on old records); an unused value is deleted. Every change is written to the account audit trail.
 */
final class LookupAdminService
{
    public const CAPABILITY = 'lookups.manage';

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit) {}

    /** Cities where the account may edit city lists. */
    public function cities(WebAccount $account): Collection
    {
        return $this->access->cities($account, self::CAPABILITY);
    }

    /** Whether the account may edit shared lists: it holds the capability in every city. */
    public function sharedEditor(WebAccount $account): bool
    {
        return $this->cities($account)->count() === DB::table('cities')->count();
    }

    private function deny(WebAccount $account): never
    {
        $this->audit->record($account->id, 'access.denied', 'denied');
        abort(403);
    }

    /** @return list<int> */
    private function managed(WebAccount $account): array
    {
        $ids = $this->cities($account)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ids !== [] || $this->deny($account);

        return $ids;
    }

    /**
     * Every list with its counts (active / hidden) within what the account may see, and whether it may edit it.
     *
     * @return list<array{key: string, group: string, city: bool, active: int, hidden: int, editable: bool}>
     */
    public function overview(WebAccount $account): array
    {
        $cities = $this->managed($account);
        $shared = $this->sharedEditor($account);
        $out = [];
        foreach (LookupCatalog::LISTS as $key => $list) {
            $query = DB::table($list['table']);
            if ($list['city']) {
                $query->whereIn('city_id', $cities);
            }
            $counts = $query->selectRaw('SUM(CASE WHEN is_active THEN 1 ELSE 0 END) as active, SUM(CASE WHEN is_active THEN 0 ELSE 1 END) as hidden')->first();
            $out[] = ['key' => $key, 'group' => $list['group'], 'city' => $list['city'], 'active' => (int) $counts->active, 'hidden' => (int) $counts->hidden, 'editable' => $list['city'] || $shared];
        }

        return $out;
    }

    /** Whether the account may change values of the list (in the city, for a city list). */
    public function canEdit(WebAccount $account, string $key, ?int $city): bool
    {
        $list = LookupCatalog::get($key);

        return $list['city'] ? $city !== null && in_array($city, $this->managed($account), true) : $this->sharedEditor($account);
    }

    /**
     * The values of one list. A city list is limited to the requested city (one the account manages).
     *
     * @return Collection<int, object>
     */
    public function rows(WebAccount $account, string $key, ?int $city, string $search = '', bool $hidden = false): Collection
    {
        $list = LookupCatalog::get($key);
        $query = $this->scoped($account, $key, $city);
        $term = trim(mb_substr($search, 0, 100));
        if ($term !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $query->where(fn (Builder $q) => $q->whereRaw("t.name_ar LIKE ? ESCAPE '!'", [$like])->orWhereRaw("t.name_en LIKE ? ESCAPE '!'", [$like]));
        }
        if (! $hidden) {
            $query->where('t.is_active', true);
        }
        $columns = ['t.id', 't.name_ar', 't.name_en', 't.sort_order', 't.is_active'];
        foreach ($list['fields'] as $field => $spec) {
            $columns[] = 't.'.$field;
            if ($spec['kind'] === 'parent') {
                $parent = LookupCatalog::get($spec['list'])['table'];
                $query->leftJoin($parent.' as p_'.$field, 'p_'.$field.'.id', '=', 't.'.$field);
                $columns[] = 'p_'.$field.'.name_ar as '.$field.'_name';
            }
        }

        return $query->orderBy('t.sort_order')->orderBy('t.name_ar')->orderBy('t.id')->get($columns);
    }

    /** The list's table aliased `t`, limited to the city for a city list. */
    private function scoped(WebAccount $account, string $key, ?int $city): Builder
    {
        $list = LookupCatalog::get($key);
        $query = DB::table($list['table'].' as t');
        if ($list['city']) {
            ($city !== null && in_array($city, $this->managed($account), true)) || $this->deny($account);
            $query->where('t.city_id', $city);
        } else {
            $this->managed($account);
        }

        return $query;
    }

    /** Values of a parent list for a select (active ones, plus the current value when it is hidden). */
    public function options(string $key, ?int $keep = null): Collection
    {
        $table = LookupCatalog::get($key)['table'];

        return DB::table($table)->where(fn (Builder $q) => $q->where('is_active', true)->when($keep, fn ($q) => $q->orWhere('id', $keep)))
            ->orderBy('sort_order')->orderBy('name_ar')->get(['id', 'name_ar', 'name_en']);
    }

    /** How many records use each value, for the given ids. @return array<int, int> */
    public function usage(string $key, array $ids): array
    {
        $counts = [];
        if ($ids === []) {
            return $counts;
        }
        foreach (LookupCatalog::references($key) as [$table, $column]) {
            foreach (DB::table($table)->whereIn($column, $ids)->selectRaw($column.' as v, COUNT(*) as n')->groupBy($column)->get() as $row) {
                $counts[(int) $row->v] = ($counts[(int) $row->v] ?? 0) + (int) $row->n;
            }
        }

        return $counts;
    }

    /** @param  array<string, mixed>  $input */
    public function create(WebAccount $account, string $key, ?int $city, array $input): int
    {
        $list = LookupCatalog::get($key);
        $this->canEdit($account, $key, $city) || $this->deny($account);
        $values = $this->validated($key, $city, $input, null);
        $now = now();
        $order = (int) DB::table($list['table'])->when($list['city'], fn ($q) => $q->where('city_id', $city))->max('sort_order') + 1;
        $id = (int) DB::table($list['table'])->insertGetId($values + ($list['city'] ? ['city_id' => $city] : []) + ['sort_order' => $order, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $this->audit->record($account->id, 'lookups.created', 'succeeded', $list['city'] ? $city : null, subjectType: 'lookup_value', subjectId: $id, decision: 'lookup:'.$key);

        return $id;
    }

    /** @param  array<string, mixed>  $input */
    public function update(WebAccount $account, string $key, int $id, array $input): void
    {
        $list = LookupCatalog::get($key);
        $row = $this->row($account, $key, $id);
        $values = $this->validated($key, $list['city'] ? (int) $row->city_id : null, $input, $id);
        DB::table($list['table'])->where('id', $id)->update($values + ['updated_at' => now()]);
        $this->audit->record($account->id, 'lookups.updated', 'succeeded', $list['city'] ? (int) $row->city_id : null, subjectType: 'lookup_value', subjectId: $id, decision: 'lookup:'.$key);
    }

    public function setActive(WebAccount $account, string $key, int $id, bool $active): void
    {
        $list = LookupCatalog::get($key);
        $row = $this->row($account, $key, $id);
        DB::table($list['table'])->where('id', $id)->update(['is_active' => $active, 'updated_at' => now()]);
        $this->audit->record($account->id, $active ? 'lookups.shown' : 'lookups.hidden', 'succeeded', $list['city'] ? (int) $row->city_id : null, subjectType: 'lookup_value', subjectId: $id, decision: 'lookup:'.$key);
    }

    /** Deletes an unused value; a value that records use is hidden instead. Returns "deleted" or "hidden". */
    public function delete(WebAccount $account, string $key, int $id): string
    {
        $list = LookupCatalog::get($key);
        $row = $this->row($account, $key, $id);
        $city = $list['city'] ? (int) $row->city_id : null;

        return DB::transaction(function () use ($account, $key, $id, $list, $city) {
            DB::table($list['table'])->where('id', $id)->lockForUpdate()->first();
            $used = ($this->usage($key, [$id])[$id] ?? 0) > 0
                || DB::table('lookup_legacy_map')->where('lookup_table', $list['table'])->where('lookup_id', $id)->exists();
            if ($used) {
                DB::table($list['table'])->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
                $this->audit->record($account->id, 'lookups.hidden', 'succeeded', $city, subjectType: 'lookup_value', subjectId: $id, decision: 'lookup:'.$key);

                return 'hidden';
            }
            DB::table($list['table'])->where('id', $id)->delete();
            $this->audit->record($account->id, 'lookups.deleted', 'succeeded', $city, subjectType: 'lookup_value', subjectId: $id, decision: 'lookup:'.$key);

            return 'deleted';
        });
    }

    /** One value the account may change, or 403/404. */
    private function row(WebAccount $account, string $key, int $id): object
    {
        $list = LookupCatalog::get($key);
        $row = DB::table($list['table'])->where('id', $id)->first();
        abort_if($row === null, 404);
        $this->canEdit($account, $key, $list['city'] ? (int) $row->city_id : null) || $this->deny($account);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(string $key, ?int $city, array $input, ?int $id): array
    {
        $list = LookupCatalog::get($key);
        $clean = fn ($v) => trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
        $values = ['name_ar' => $clean($input['name_ar'] ?? ''), 'name_en' => $clean($input['name_en'] ?? '') ?: null];
        $errors = [];
        if ($values['name_ar'] === '' || mb_strlen($values['name_ar']) > 255) {
            $errors['name_ar'] = __('lookups.errors.name');
        }
        if ($values['name_en'] !== null && mb_strlen($values['name_en']) > 255) {
            $errors['name_en'] = __('lookups.errors.name');
        }
        foreach ($list['fields'] as $field => $spec) {
            $value = $input[$field] ?? null;
            $value = $value === '' ? null : $value;
            if ($spec['kind'] === 'text') {
                $value = $value === null ? null : $clean($value);
                if (($spec['required'] && ($value === null || $value === '')) || ($value !== null && mb_strlen($value) > $spec['max'])) {
                    $errors[$field] = __('lookups.errors.required');
                }
            } elseif ($spec['kind'] === 'choice') {
                if (($value === null && $spec['required']) || ($value !== null && ! in_array($value, $spec['options'], true))) {
                    $errors[$field] = __('lookups.errors.required');
                }
            } elseif ($spec['kind'] === 'parent') {
                $value = $value === null ? null : (int) $value;
                $current = $id === null ? null : DB::table($list['table'])->where('id', $id)->value($field);
                $valid = $value === null ? ! $spec['required']
                    : DB::table(LookupCatalog::get($spec['list'])['table'])->where('id', $value)->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $current))->exists();
                if (! $valid) {
                    $errors[$field] = __('lookups.errors.required');
                }
            }
            $values[$field] = $value;
        }
        if (! isset($errors['name_ar'])) {
            $duplicate = DB::table($list['table'])->where('name_ar', $values['name_ar'])->when($list['city'], fn ($q) => $q->where('city_id', $city))
                ->when($id !== null, fn ($q) => $q->where('id', '<>', $id));
            foreach ($list['fields'] as $field => $spec) {
                if ($spec['kind'] === 'parent') {
                    $duplicate->where($field, $values[$field]);
                }
            }
            if ($duplicate->exists()) {
                $errors['name_ar'] = __('lookups.errors.duplicate');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /** Rows for the Excel/CSV export of one list (the desktop's "Export to Excel"). @return array{0: list<string>, 1: list<list<mixed>>} */
    public function export(WebAccount $account, string $key, ?int $city): array
    {
        $list = LookupCatalog::get($key);
        $rows = $this->rows($account, $key, $city, '', true);
        $usage = $this->usage($key, $rows->pluck('id')->all());
        $headings = ['#', __('lookups.name_ar'), __('lookups.name_en')];
        foreach ($list['fields'] as $field => $spec) {
            $headings[] = __('lookups.fields.'.$field);
        }
        $headings[] = __('lookups.used');
        $headings[] = __('lookups.status');
        $data = $rows->map(function ($r) use ($list, $usage) {
            $line = [(int) $r->id, $r->name_ar, $r->name_en];
            foreach ($list['fields'] as $field => $spec) {
                $line[] = match ($spec['kind']) {
                    'parent' => $r->{$field.'_name'}, 'choice' => $r->{$field} === null ? null : __('lookups.choices.'.$field.'.'.$r->{$field}), default => $r->{$field}
                };
            }
            $line[] = $usage[(int) $r->id] ?? 0;
            $line[] = $r->is_active ? __('lookups.active') : __('lookups.hidden');

            return $line;
        })->all();
        $this->audit->record($account->id, 'lookups.exported', 'succeeded', $list['city'] ? $city : null, decision: 'lookup:'.$key);

        return [$headings, $data];
    }
}
