<?php

declare(strict_types=1);

namespace App\Services\People;

use App\Models\Person;
use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\ProjectNumberAllocator;
use App\Support\ArabicSearch;
use App\Support\CityContext;
use App\Support\Datasets\Dataset;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * People, families and the person sub-tables on the unified schema (M4.3), following the desktop Application_New_Form person block:
 * reading needs `people.view` in the person's city, writing `people.manage`. Duplicates are found as the desktop does
 * (Check_National_Number: the national number with and without its leading zero; Check_Family_Num: other beneficiaries with the same
 * family book number) plus the same full name in the city; saving over a found duplicate needs an explicit confirmation.
 * A beneficiary joins its family as "مستفيد", resident, and is its own provider; the members of its household have it as provider
 * whoever adds them, and the household's stored counts are recomputed on every member change (see {@see Households}).
 */
final class PeopleService
{
    public const PER_PAGE = 20;

    public const BENEFICIARY = 'مستفيد';

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit) {}

    /** @return list<int> */
    public function cityIds(WebAccount $account, string $capability = 'people.view'): array
    {
        return $this->access->cities($account, $capability)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function cities(WebAccount $account, string $capability = 'people.view'): Collection
    {
        return $this->access->cities($account, $capability);
    }

    public function canManage(WebAccount $account, int $city): bool
    {
        return $this->access->check($account, $city, 'people.manage');
    }

    private function deny(WebAccount $account): never
    {
        $this->audit->record($account->id, 'access.denied', 'denied');
        abort(403);
    }

    /** A person the account may read (404 for other cities, as if it did not exist). */
    public function person(WebAccount $account, int $id): object
    {
        $person = DB::table('people')->where('id', $id)->whereNull('deleted_at')->whereIn('city_id', $this->cityIds($account) ?: [0])->first();
        abort_if($person === null, 404);

        return $person;
    }

    private function writable(WebAccount $account, int $id): object
    {
        $person = $this->person($account, $id);
        $this->canManage($account, (int) $person->city_id) || $this->deny($account);

        return $person;
    }

    /** "first father last" as the desktop prints it ("first last ابن/ة father" in its messages). */
    public static function fullName(object $p): string
    {
        return trim(preg_replace('/\s+/u', ' ', ($p->first_name ?? '').' '.($p->father_name ?? '').' '.($p->last_name ?? '')) ?? '');
    }

    // ---------------------------------------------------------------- search

    /** @param  array{q?: string, cities?: list<int>, projects?: string}  $filters */
    public function search(WebAccount $account, array $filters): LengthAwarePaginator
    {
        $permitted = $this->cityIds($account);
        $permitted !== [] || $this->deny($account);
        $chosen = array_values(array_intersect(array_map('intval', $filters['cities'] ?? []), $permitted));
        $family = DB::table('family_members')->whereNull('deleted_at')->selectRaw('person_id, MIN(family_id) as family_id')->groupBy('person_id');
        $projects = DB::table('project_applicants')->whereNull('deleted_at')->selectRaw('person_id, COUNT(*) as n')->groupBy('person_id');
        $query = DB::table('people as p')->join('cities as c', 'c.id', '=', 'p.city_id')
            ->leftJoinSub($family, 'fm', 'fm.person_id', '=', 'p.id')->leftJoin('families as f', 'f.id', '=', 'fm.family_id')
            ->leftJoinSub($projects, 'pa', 'pa.person_id', '=', 'p.id')
            ->whereNull('p.deleted_at')->whereIn('p.city_id', $chosen ?: $permitted);
        $term = trim(mb_substr((string) ($filters['q'] ?? ''), 0, 100));
        if ($term !== '') {
            $digits = preg_replace('/\D/', '', $term);
            if ($digits !== '' && strlen($digits) >= 3 && preg_match('/^[\d\s+\-]+$/', $term)) {
                $like = '%'.ltrim($digits, '0').'%';
                $query->where(fn (Builder $q) => $q->where('p.national_number', 'like', $like)->orWhere('p.mobile', 'like', '%'.$digits.'%')
                    ->orWhere('p.mobile_2', 'like', '%'.$digits.'%')->orWhere('f.family_number', 'like', '%'.$digits.'%'));
            } else {
                foreach (preg_split('/\s+/u', $term) as $word) {
                    $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%';
                    // Arabic letters folded on both sides (أ/ا، ة/ه، ى/ي), owner request 20 Nov 2026.
                    $query->where(function (Builder $q) use ($word, $like) {
                        foreach (['p.first_name', 'p.father_name', 'p.last_name'] as $column) {
                            ArabicSearch::orWhereLike($q, $column, $word);
                        }
                        $q->orWhere('p.full_name_en', 'like', $like)->orWhere('f.family_number', 'like', $like);
                    });
                }
            }
        }
        match ($filters['projects'] ?? '') {
            'with' => $query->whereNotNull('pa.n'),
            'without' => $query->whereNull('pa.n'),
            default => null,
        };

        return $query->orderBy('p.first_name')->orderBy('p.last_name')->orderBy('p.id')
            ->paginate(self::PER_PAGE, ['p.id', 'p.first_name', 'p.father_name', 'p.last_name', 'p.national_number', 'p.birth_date', 'p.mobile', 'p.sex',
                'c.code as city_code', 'c.name_ar as city_ar', 'c.name_en as city_en', 'f.family_number', DB::raw('COALESCE(pa.n, 0) as projects')]);
    }

    /**
     * The people list's base query from the smart-filter dataset (CityScope on top of `people.view` cities, so neither can be bypassed
     * by a chosen city). @param  list<int>  $cities  the chosen city chips (ignored when outside the permitted ones)
     *
     * @return \Illuminate\Database\Eloquent\Builder<Person>
     */
    public function listQuery(WebAccount $account, Dataset $dataset, array $cities = []): \Illuminate\Database\Eloquent\Builder
    {
        $permitted = $this->cityIds($account);
        $permitted !== [] || $this->deny($account);
        $context = app(CityContext::class);
        $chosen = array_values(array_intersect(array_map('intval', $cities), $context->active() ? array_intersect($permitted, $context->cityIds()) : $permitted));

        return $dataset->query()->whereIn('people.city_id', $chosen ?: $permitted);
    }

    /** One page of the people list (see listQuery). */
    public function listPage(\Illuminate\Database\Eloquent\Builder $query): LengthAwarePaginator
    {
        return $query->orderBy('people.first_name')->orderBy('people.last_name')->orderBy('people.id')
            ->paginate(self::PER_PAGE, ['people.id', 'people.first_name', 'people.father_name', 'people.last_name', 'people.national_number', 'people.birth_date', 'people.mobile', 'people.sex',
                'c.code as city_code', 'c.name_ar as city_ar', 'c.name_en as city_en', 'f.family_number', DB::raw('COALESCE(pa.n, 0) as projects')]);
    }

    // ---------------------------------------------------------------- duplicates

    /**
     * The national number as compared: digits only, without leading zeros. The desktop matched the number with and without one
     * leading zero; dropping all of them also matches numbers typed with two or none, and spaces or dashes typed in between.
     */
    public static function nationalKey(string $number): string
    {
        return ltrim(preg_replace('/\D/', '', $number) ?? '', '0');
    }

    /**
     * People that may be the same person, in the cities the account may read: same national number (either spelling) anywhere, or
     * the same first, father and last name in the city. Each carries the projects it applied to.
     *
     * @return list<object{id: int, name: string, city: string, national_number: ?string, reason: string, projects: list<string>}>
     */
    public function duplicates(WebAccount $account, int $city, array $input, ?int $except = null): array
    {
        $permitted = $this->cityIds($account);
        $found = [];
        $key = self::nationalKey((string) ($input['national_number'] ?? ''));
        if (strlen($key) >= 5) {
            // Narrowed by LIKE on the significant digits, then compared exactly in PHP (stored numbers may hold spaces or dashes).
            foreach (DB::table('people')->whereNull('deleted_at')->whereIn('city_id', $permitted)->where('national_number', 'like', '%'.substr($key, -5).'%')
                ->when($except, fn ($q) => $q->where('id', '<>', $except))->get()->filter(fn ($p) => self::nationalKey((string) $p->national_number) === $key)->take(10) as $p) {
                $found[(int) $p->id] = [$p, 'national'];
            }
        }
        $first = trim((string) ($input['first_name'] ?? ''));
        $last = trim((string) ($input['last_name'] ?? ''));
        $father = trim((string) ($input['father_name'] ?? ''));
        if ($first !== '' && $last !== '' && $father !== '') {
            foreach (DB::table('people')->whereNull('deleted_at')->where('city_id', $city)->where('first_name', $first)->where('last_name', $last)->where('father_name', $father)
                ->when($except, fn ($q) => $q->where('id', '<>', $except))->limit(10)->get() as $p) {
                $found[(int) $p->id] ??= [$p, 'name'];
            }
        }
        if ($found === []) {
            return [];
        }
        $codes = DB::table('cities')->pluck('code', 'id');
        $names = DB::table('cities')->pluck(app()->getLocale() === 'en' ? 'name_en' : 'name_ar', 'id');
        $projects = DB::table('project_applicants as a')->join('projects as pr', 'pr.id', '=', 'a.project_id')->whereNull('a.deleted_at')
            ->whereIn('a.person_id', array_keys($found))->get(['a.person_id', 'pr.city_id', 'pr.project_number'])->groupBy('person_id');

        return array_values(array_map(fn ($pair) => (object) [
            'id' => (int) $pair[0]->id, 'name' => self::fullName($pair[0]), 'city' => (string) $names[(int) $pair[0]->city_id], 'national_number' => $pair[0]->national_number,
            'reason' => $pair[1],
            'projects' => ($projects->get($pair[0]->id) ?? collect())->map(fn ($r) => ProjectNumberAllocator::display((string) $codes[(int) $r->city_id], (int) $r->project_number))->all(),
        ], $found));
    }

    /** Beneficiaries of an existing family with this book number in the city (desktop Check_Family_Num). @return Collection<int, object> */
    public function familyHolders(int $city, string $number, ?int $except = null): Collection
    {
        $number = trim($number);
        if ($number === '') {
            return collect();
        }

        return DB::table('families as f')->join('family_members as m', 'm.family_id', '=', 'f.id')->join('people as p', 'p.id', '=', 'm.person_id')
            ->leftJoin('relations as r', 'r.id', '=', 'm.relation_id')
            ->where('f.city_id', $city)->where('f.family_number', $number)->whereNull('f.deleted_at')->whereNull('m.deleted_at')->whereNull('p.deleted_at')
            ->where(fn ($q) => $q->where('r.name_ar', self::BENEFICIARY)->orWhereColumn('m.provider_person_id', 'm.person_id'))
            ->when($except, fn ($q) => $q->where('p.id', '<>', $except))
            ->get(['p.id', 'p.first_name', 'p.father_name', 'p.last_name', 'f.id as family_id'])
            ->map(fn ($p) => (object) ['id' => (int) $p->id, 'name' => self::fullName($p), 'family_id' => (int) $p->family_id]);
    }

    // ---------------------------------------------------------------- lists

    /** Active values of a list for a select, plus the current value when it was hidden since. */
    /**
     * Values of a list for a select: active ones (plus the current value when it was hidden since), only those under the chosen parent
     * when the list depends on another field (`$parent` = [column, value]), and with their group's name (`$group` = [table, column]) so
     * a long list can be searched and shown by group.
     *
     * @return Collection<int, object{id: int, label: string, group: ?string}>
     */
    public function options(string $table, ?int $city = null, mixed $keep = null, ?array $parent = null, ?array $group = null): Collection
    {
        $en = app()->getLocale() === 'en';
        $query = DB::table($table.' as o')->where(fn ($q) => $q->where('o.is_active', true)->when($keep, fn ($q) => $q->orWhere('o.id', (int) $keep)))
            ->when($city !== null, fn ($q) => $q->where('o.city_id', $city))
            ->when($parent !== null && $parent[1] !== null && $parent[1] !== '', fn ($q) => $q->where(fn ($w) => $w->where('o.'.$parent[0], (int) $parent[1])->when($keep, fn ($k) => $k->orWhere('o.id', (int) $keep))));
        $columns = ['o.id', 'o.name_ar', 'o.name_en'];
        if ($group !== null) {
            $query->leftJoin($group['table'].' as g', 'g.id', '=', 'o.'.$group['column'])->orderBy('g.sort_order')->orderBy('g.name_ar');
            $columns[] = 'g.name_ar as group_ar';
            $columns[] = 'g.name_en as group_en';
        }

        return $query->orderBy('o.sort_order')->orderBy('o.name_ar')->get($columns)
            ->map(fn ($o) => (object) [
                'id' => (int) $o->id, 'label' => $en && $o->name_en ? $o->name_en : $o->name_ar,
                'group' => $group === null ? null : ($en && $o->group_en ? $o->group_en : $o->group_ar),
            ]);
    }

    /**
     * Options for every lookup field of a field set. `$values` holds the form's current values: they keep a hidden current value
     * selectable and give the parent value of dependent lists.
     *
     * @param  array<string, array>  $fields
     * @return array<string, Collection>
     */
    public function optionsFor(array $fields, int $city, array $values = []): array
    {
        $out = [];
        foreach ($fields as $field => $spec) {
            if ($spec['kind'] === 'lookup') {
                $parent = isset($spec['parent']) ? [$spec['parent_column'], $values[$spec['parent']] ?? null] : null;
                $out[$field] = $this->options($spec['table'], ($spec['city'] ?? false) ? $city : null, $values[$field] ?? null, $parent, $spec['group'] ?? null);
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- validation

    /**
     * Cleans and checks values for a field set; lookup values must be active (or unchanged) and of the city for city lists.
     *
     * @param  array<string, array>  $fields
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    public function validate(array $fields, array $input, int $city, array $current = [], string $prefix = ''): array
    {
        $values = [];
        $errors = [];
        foreach ($fields as $field => $spec) {
            $raw = $input[$field] ?? null;
            $raw = is_string($raw) ? trim(preg_replace('/[ \t]+/u', ' ', $raw) ?? '') : $raw;
            $empty = $raw === null || $raw === '';
            $value = null;
            $error = null;
            switch ($spec['kind']) {
                case 'text':
                case 'textarea':
                    $value = $empty ? null : (string) $raw;
                    if ($value !== null && mb_strlen($value) > ($spec['max'] ?? 5000)) {
                        $error = __('people.errors.too_long', ['max' => $spec['max'] ?? 5000]);
                    }
                    break;
                case 'date':
                    if (! $empty) {
                        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $raw);
                        $date && $date->format('Y-m-d') === $raw && $raw >= '1900-01-01' && $raw <= now()->addYear()->format('Y-m-d') ? $value = $raw : $error = __('people.errors.date');
                    }
                    break;
                case 'int':
                    if (! $empty) {
                        filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $spec['min'] ?? 0, 'max_range' => $spec['max'] ?? 32767]]) !== false ? $value = (int) $raw : $error = __('people.errors.number');
                    }
                    break;
                case 'decimal':
                    if (! $empty) {
                        is_numeric(str_replace(',', '', (string) $raw)) && (float) str_replace(',', '', (string) $raw) >= 0 && (float) str_replace(',', '', (string) $raw) < 1e12
                            ? $value = round((float) str_replace(',', '', (string) $raw), 2) : $error = __('people.errors.number');
                    }
                    break;
                case 'member':
                    // Who the row is about: "self" (the beneficiary) or a family member's person id; checked against the family when saved.
                    $value = $empty ? 'self' : (($raw === 'self' || ctype_digit((string) $raw) || preg_match('/\Arow-\d+\z/', (string) $raw)) ? (string) $raw : 'self');
                    break;
                case 'bool':
                    $value = $empty ? null : in_array($raw, [true, 1, '1', 'yes', 'true'], true);
                    break;
                case 'sex':
                case 'choice':
                    $allowed = $spec['options'] ?? ['male', 'female'];
                    $value = $empty ? null : (in_array($raw, $allowed, true) ? $raw : null);
                    $empty || $value !== null || $error = __('people.errors.choice');
                    break;
                case 'lookup':
                    if (! $empty) {
                        $id = (int) $raw;
                        $ok = DB::table($spec['table'])->where('id', $id)->when($spec['city'] ?? false, fn ($q) => $q->where('city_id', $city))
                            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', (int) ($current[$field] ?? 0)))->exists();
                        $ok ? $value = $id : $error = __('people.errors.choice');
                        // A dependent list: the value must belong to the chosen parent (an unchanged old value is kept as is).
                        $parentValue = isset($spec['parent']) ? ($input[$spec['parent']] ?? null) : null;
                        if ($ok && $parentValue !== null && $parentValue !== '' && $id !== (int) ($current[$field] ?? 0)
                            && (int) DB::table($spec['table'])->where('id', $id)->value($spec['parent_column']) !== (int) $parentValue) {
                            $value = null;
                            $error = __('people.errors.parent', ['parent' => __('applications.fields.'.$spec['parent'])]);
                        }
                    }
                    break;
            }
            // A column opened by a yes/no column of the same row ("works" → work, "medical aid" → its source): cleared unless "Yes".
            if (isset($spec['when']) && ! in_array($input[$spec['when']] ?? null, [true, 1, '1'], true)) {
                $value = null;
                $error = null;
            }
            if ($error === null && $value === null && ($spec['required'] ?? false)) {
                $error = __('people.errors.required');
            }
            if ($error !== null) {
                $errors[$prefix.$field] = $error;
            }
            $values[$field] = $value;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /** @return array<string, mixed> person values; military fields are cleared for women */
    private function personValues(array $input, int $city, array $current = []): array
    {
        $values = $this->validate(PersonFields::personFlat(), $input, $city, $current, 'person.');
        if ($values['sex'] === 'female') {
            foreach (array_keys(PersonFields::PERSON['military']) as $field) {
                $values[$field] = null;
            }
        }

        return $values;
    }

    // ---------------------------------------------------------------- person writes

    /**
     * Creates a person in a city the account manages, with its family by book number (an existing family of that number in the city
     * is joined, as the desktop does). Found duplicates must be confirmed.
     */
    public function create(WebAccount $account, int $city, array $input, ?string $familyNumber, bool $confirmed): int
    {
        $this->canManage($account, $city) || $this->deny($account);
        $values = $this->personValues($input, $city);
        // The desktop registers a new beneficiary as resident.
        $values['person_state_id'] ??= Households::residentStateId();
        $this->guardDuplicates($account, $city, $values, null, $familyNumber, $confirmed);
        $familyNumber = trim((string) $familyNumber);
        if (mb_strlen($familyNumber) > 50) {
            throw ValidationException::withMessages(['family_number' => __('people.errors.too_long', ['max' => 50])]);
        }

        return DB::transaction(function () use ($account, $city, $values, $familyNumber) {
            $now = now();
            $id = (int) DB::table('people')->insertGetId($values + ['city_id' => $city, 'created_at' => $now, 'updated_at' => $now]);
            $this->joinFamily($id, $city, $familyNumber);
            $this->audit->record($account->id, 'people.created', 'succeeded', $city, subjectType: 'person_entity', subjectId: $id);

            return $id;
        });
    }

    /**
     * Puts a person (with no family yet) in the family of this book number in the city, or in a new family, as its beneficiary
     * ("مستفيد", provider of itself). Callers authorize.
     */
    public function joinFamily(int $personId, int $city, string $familyNumber): int
    {
        $now = now();
        $familyNumber = trim($familyNumber);
        $family = $familyNumber === '' ? null : DB::table('families')->where('city_id', $city)->where('family_number', $familyNumber)->whereNull('deleted_at')->value('id');
        $family ??= DB::table('families')->insertGetId(['city_id' => $city, 'family_number' => $familyNumber !== '' ? $familyNumber : $this->nextFamilyNumber($city), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('family_members')->insert([
            'city_id' => $city, 'family_id' => $family, 'person_id' => $personId, 'relation_id' => Households::beneficiaryRelationId(), 'provider_person_id' => $personId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        // After the whole save (the application form writes the family rows in the same transaction), as the desktop counts them.
        DB::afterCommit(fn () => Households::recount($personId));

        return (int) $family;
    }

    public function update(WebAccount $account, int $id, array $input, bool $confirmed): void
    {
        $person = $this->writable($account, $id);
        $city = (int) $person->city_id;
        $values = $this->personValues($input, $city, (array) $person);
        if ($values['national_number'] !== $person->national_number || $values['first_name'] !== $person->first_name || $values['last_name'] !== $person->last_name || $values['father_name'] !== $person->father_name) {
            $this->guardDuplicates($account, $city, $values, $id, null, $confirmed);
        }
        DB::transaction(function () use ($account, $id, $city, $person, $values) {
            DB::table('people')->where('id', $id)->update($values + ['updated_at' => now()]);
            $this->audit->record($account->id, 'people.updated', 'succeeded', $city, subjectType: 'person_entity', subjectId: $id, details: AccountAuditTrail::changes($person, $values));
            // A changed residence state changes who is at home: only the households this person is in are recounted.
            if ((int) ($person->person_state_id ?? 0) !== (int) ($values['person_state_id'] ?? 0)) {
                foreach (Households::membershipsOf($id)->pluck('head_id')->unique() as $head) {
                    $this->recounted($account, (int) $head);
                }
            }
        });
    }

    /** Recounts one household and records the change on its beneficiary. */
    private function recounted(WebAccount $account, int $headId): void
    {
        $changes = Households::recount($headId);
        if ($changes !== []) {
            $city = (int) DB::table('people')->where('id', $headId)->value('city_id');
            $this->audit->record($account->id, 'people.updated', 'succeeded', $city, subjectType: 'person_entity', subjectId: $headId, details: $changes);
        }
    }

    private function guardDuplicates(WebAccount $account, int $city, array $values, ?int $except, ?string $familyNumber, bool $confirmed): void
    {
        if ($confirmed) {
            return;
        }
        if ($this->duplicates($account, $city, $values, $except) !== [] || ($familyNumber !== null && $this->familyHolders($city, $familyNumber, $except)->isNotEmpty())) {
            throw ValidationException::withMessages(['confirm' => __('people.errors.duplicates')]);
        }
    }

    /** A family book number for a person whose book number is unknown: "<CODE>-W<n>", unique in the city. */
    private function nextFamilyNumber(int $city): string
    {
        $code = (string) DB::table('cities')->where('id', $city)->value('code');
        $n = DB::table('families')->where('city_id', $city)->where('family_number', 'like', $code.'-W%')->count() + 1;
        while (DB::table('families')->where('city_id', $city)->where('family_number', $code.'-W'.$n)->exists()) {
            $n++;
        }

        return $code.'-W'.$n;
    }

    // ---------------------------------------------------------------- household (desktop family grid)

    /** A four-digit year typed for a birth date is stored as the first of January, as in the application form. */
    public static function birthDate(mixed $value): mixed
    {
        return is_string($value) && preg_match('/\A\d{4}\z/', trim($value)) ? trim($value).'-01-01' : $value;
    }

    /**
     * The household a change is made in: the one named (the person must be in it), or the person's own household (the one they
     * head, else the first they belong to). Writing needs `people.manage` in the family's city.
     *
     * @param  ?array{0: int, 1: int}  $household  [family id, head person id]
     * @return array{family: object, head: int, city: int}
     */
    private function householdFor(WebAccount $account, int $personId, ?array $household): array
    {
        $this->writable($account, $personId);
        if ($household === null) {
            $own = Households::membershipsOf($personId)->first() ?? abort(404);
            [$familyId, $head] = [$own->family_id, $own->head_id];
        } else {
            [$familyId, $head] = [(int) $household[0], (int) $household[1]];
            Households::rows($familyId, $head)->where('m.person_id', $personId)->exists() || abort(404);
        }
        $family = DB::table('families')->where('id', $familyId)->whereNull('deleted_at')->first() ?? abort(404);
        in_array((int) $family->city_id, $this->cityIds($account), true) || abort(404);
        $this->canManage($account, (int) $family->city_id) || $this->deny($account);

        return ['family' => $family, 'head' => $head, 'city' => (int) $family->city_id];
    }

    private function memberRow(array $household, int $memberId): object
    {
        $row = Households::rows((int) $household['family']->id, $household['head'])->where('m.id', $memberId)->first('m.*');
        abort_if($row === null, 404);

        return $row;
    }

    /** "مستفيد" is the head's own relation (the desktop leaves it out of the member list); a member keeps it only if already stored so. */
    private function guardRelation(array $values, ?int $current, string $prefix): void
    {
        $beneficiary = Households::beneficiaryRelationId();
        if ($beneficiary !== null && ($values['relation_id'] ?? null) === $beneficiary && $current !== $beneficiary) {
            throw ValidationException::withMessages([$prefix.'relation_id' => __('families.errors.beneficiary_relation')]);
        }
    }

    /**
     * Adds a member to a household: an existing person of the city (`person_id`) or a new one with the desktop family grid's columns.
     * The new row's provider is the household's head, whoever's page it was added from; a person without a residence state gets
     * "resident"; the head's stored counts are recomputed.
     *
     * @param  ?array{0: int, 1: int}  $household  [family id, head person id]; the person's own household when null
     */
    public function addMember(WebAccount $account, int $personId, array $input, ?array $household = null): int
    {
        $home = $this->householdFor($account, $personId, $household);
        [$city, $family, $head] = [$home['city'], $home['family'], $home['head']];
        if (! DB::table('people')->where('id', $head)->where('city_id', $city)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['member' => __('families.errors.no_head')]);
        }
        $member = $this->validate(PersonFields::MEMBER, $input, $city, [], 'member.');
        $this->guardRelation($member, null, 'member.');
        $existing = (int) ($input['person_id'] ?? 0);
        $new = [];
        $state = null;
        if ($existing > 0) {
            $found = DB::table('people')->where('id', $existing)->where('city_id', $city)->whereNull('deleted_at')->first()
                ?? throw ValidationException::withMessages(['member.person_id' => __('people.errors.choice')]);
            if (DB::table('family_members')->where('family_id', $family->id)->where('person_id', $existing)->whereNull('deleted_at')->exists()) {
                throw ValidationException::withMessages(['member.person_id' => __('people.errors.already_member')]);
            }
            if ($found->person_state_id === null) {
                $fields = ['person_state_id' => PersonFields::personFlat()['person_state_id']];
                $state = $this->validate($fields, $input, $city, [], 'member.')['person_state_id'] ?? Households::residentStateId();
            }
        } else {
            $input['birth_date'] = self::birthDate($input['birth_date'] ?? null);
            $fields = array_intersect_key(PersonFields::personFlat(), array_flip(PersonFields::MEMBER_PERSON));
            $new = $this->validate($fields, $input, $city, [], 'member.');
            $new['person_state_id'] ??= Households::residentStateId();
        }

        return DB::transaction(function () use ($account, $city, $family, $head, $member, $existing, $new, $state) {
            $now = now();
            $memberPerson = $existing > 0 ? $existing : (int) DB::table('people')->insertGetId($new + ['city_id' => $city, 'created_at' => $now, 'updated_at' => $now]);
            if ($state !== null) {
                DB::table('people')->where('id', $memberPerson)->update(['person_state_id' => $state, 'updated_at' => $now]);
                $this->audit->record($account->id, 'people.updated', 'succeeded', $city, subjectType: 'person_entity', subjectId: $memberPerson,
                    details: [['field' => 'person_state_id', 'before' => null, 'after' => $state]]);
            }
            $id = (int) DB::table('family_members')->insertGetId($member + ['city_id' => $city, 'family_id' => $family->id, 'person_id' => $memberPerson, 'provider_person_id' => $head, 'created_at' => $now, 'updated_at' => $now]);
            $name = self::fullName(DB::table('people')->where('id', $memberPerson)->first());
            $this->audit->record($account->id, 'people.updated', 'succeeded', $city, subjectType: 'person_entity', subjectId: $head,
                details: [['field' => 'family.member', 'before' => null, 'after' => $name], ...Households::recount($head)]);

            return $id;
        });
    }

    /**
     * Changes a member's family row and, when sent, the person columns of the desktop family grid (names, sex, birth date, national
     * number, residence state). Only changed person values are checked and written; a new name or national number that matches
     * someone else needs `$confirmed`. The head's relation stays as it is.
     *
     * @param  ?array{0: int, 1: int}  $household
     */
    public function updateMember(WebAccount $account, int $personId, int $memberId, array $input, ?array $household = null, bool $confirmed = false): void
    {
        $home = $this->householdFor($account, $personId, $household);
        [$city, $head] = [$home['city'], $home['head']];
        $row = $this->memberRow($home, $memberId);
        $current = array_intersect_key((array) $row, PersonFields::MEMBER);
        $isHead = (int) $row->person_id === $head;
        // Fields not sent keep their value.
        $family = array_intersect_key($input, PersonFields::MEMBER) + $current;
        if ($isHead) {
            $family['relation_id'] = $current['relation_id'];
        }
        $family['has_work'] = $family['has_work'] === null || $family['has_work'] === '' ? '' : ((int) $family['has_work'] === 1 ? '1' : '0');
        $values = $this->validate(PersonFields::MEMBER, $family, $city, (array) $row, 'edit.');
        if (! $isHead) {
            $this->guardRelation($values, $current['relation_id'] === null ? null : (int) $current['relation_id'], 'edit.');
        }

        $person = DB::table('people')->where('id', $row->person_id)->where('city_id', $city)->whereNull('deleted_at')->first() ?? abort(404);
        $sent = array_intersect_key($input, array_flip(PersonFields::MEMBER_PERSON));
        if (array_key_exists('birth_date', $sent)) {
            $sent['birth_date'] = self::birthDate($sent['birth_date']);
        }
        $stored = fn (string $field) => trim((string) ($field === 'birth_date' ? substr((string) $person->birth_date, 0, 10) : $person->{$field}));
        $sent = array_filter($sent, fn ($value, $field) => trim((string) $value) !== $stored($field), ARRAY_FILTER_USE_BOTH);
        $changed = [];
        if ($sent !== []) {
            $changed = $this->validate(array_intersect_key(PersonFields::personFlat(), $sent), $sent, $city, (array) $person, 'edit.');
            if (($changed['sex'] ?? null) === 'female') {
                $changed += array_fill_keys(array_keys(PersonFields::PERSON['military']), null);
            }
            if (! $confirmed && array_intersect_key($changed, array_flip(['national_number', 'first_name', 'father_name', 'last_name'])) !== []
                && $this->duplicates($account, $city, $changed + (array) $person, (int) $person->id) !== []) {
                throw ValidationException::withMessages(['confirm' => __('people.errors.duplicates')]);
            }
        }

        DB::transaction(function () use ($account, $city, $head, $row, $person, $values, $changed, $memberId) {
            $now = now();
            DB::table('family_members')->where('id', $memberId)->update($values + ['updated_at' => $now]);
            if ($changed !== []) {
                DB::table('people')->where('id', $person->id)->update($changed + ['updated_at' => $now]);
                $this->audit->record($account->id, 'people.updated', 'succeeded', $city, subjectType: 'person_entity', subjectId: (int) $person->id,
                    details: AccountAuditTrail::changes($person, $changed));
            }
            $subject = DB::table('people')->where('id', $head)->where('city_id', $city)->exists() ? $head : (int) $person->id;
            $details = [...(self::prefixed('family', AccountAuditTrail::changes($row, $values)) ?? []), ...Households::recount($head)];
            $this->audit->record($account->id, 'people.updated', 'succeeded', $city, subjectType: 'person_entity', subjectId: $subject,
                details: $details === [] ? null : $details);
            // A person who also belongs to other households changes who is at home there too.
            if (array_key_exists('person_state_id', $changed)) {
                foreach (Households::membershipsOf((int) $person->id)->pluck('head_id')->unique()->reject(fn ($other) => $other === $head) as $other) {
                    $this->recounted($account, (int) $other);
                }
            }
        });
    }

    /**
     * Takes a member out of a household (the person record stays). The head cannot be removed; from a person's own page that person
     * cannot be removed either (`$self` allows it on the family page).
     *
     * @param  ?array{0: int, 1: int}  $household
     */
    public function removeMember(WebAccount $account, int $personId, int $memberId, ?array $household = null, bool $self = false): void
    {
        $home = $this->householdFor($account, $personId, $household);
        [$city, $head] = [$home['city'], $home['head']];
        $row = $this->memberRow($home, $memberId);
        (int) $row->person_id !== $head || throw ValidationException::withMessages(['member' => __('families.errors.head_member')]);
        $self || (int) $row->person_id !== $personId || throw ValidationException::withMessages(['member' => __('people.errors.self_member')]);
        DB::transaction(function () use ($account, $city, $head, $row, $memberId) {
            DB::table('family_members')->where('id', $memberId)->update(['deleted_at' => now(), 'updated_at' => now()]);
            $subject = DB::table('people')->where('id', $head)->where('city_id', $city)->exists() ? $head : (int) $row->person_id;
            $this->audit->record($account->id, 'people.updated', 'succeeded', $city, subjectType: 'person_entity', subjectId: $subject,
                details: [['field' => 'family.member', 'before' => self::fullName(DB::table('people')->where('id', $row->person_id)->first()), 'after' => null], ...Households::recount($head)]);
        });
    }

    /**
     * Writes the household's computed counts on its beneficiary, on request (the family page shows when they differ).
     *
     * @param  array{0: int, 1: int}  $household
     */
    public function recountHousehold(WebAccount $account, int $personId, array $household): void
    {
        $home = $this->householdFor($account, $personId, $household);
        $home['head'] > 0 || abort(404);
        $this->recounted($account, $home['head']);
    }

    /** People of the city for the "existing person" picker, by name or national number. */
    public function pick(WebAccount $account, int $city, string $term, array $exclude = []): Collection
    {
        in_array($city, $this->cityIds($account), true) || $this->deny($account);
        $term = trim(mb_substr($term, 0, 60));
        if (mb_strlen($term) < 2) {
            return collect();
        }
        $query = DB::table('people')->where('city_id', $city)->whereNull('deleted_at')->whereNotIn('id', $exclude ?: [0]);
        foreach (preg_split('/\s+/u', $term) as $word) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%';
            $query->where(fn ($q) => $q->where('first_name', 'like', $like)->orWhere('father_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('national_number', 'like', $like));
        }

        return $query->orderBy('first_name')->limit(8)->get(['id', 'first_name', 'father_name', 'last_name', 'national_number', 'birth_date', 'person_state_id'])
            ->map(fn ($p) => (object) ['id' => (int) $p->id, 'name' => self::fullName($p), 'national_number' => $p->national_number, 'birth_year' => $p->birth_date ? substr((string) $p->birth_date, 0, 4) : null,
                'person_state_id' => $p->person_state_id === null ? null : (int) $p->person_state_id]);
    }

    // ---------------------------------------------------------------- sub-tables

    /** Rows of one sub-table with the labels of their lookup values. */
    public function details(WebAccount $account, string $kind, int $personId): Collection
    {
        $this->person($account, $personId);

        return $this->detailRows($kind, [$personId]);
    }

    /**
     * Rows of one sub-table for several people (the family page), with the labels of their lookup values. Callers authorize.
     *
     * @param  list<int>  $personIds
     */
    public function detailRows(string $kind, array $personIds): Collection
    {
        $definition = PersonFields::DETAILS[$kind] ?? abort(404);
        $query = DB::table($definition['table'].' as t')->whereIn('t.person_id', $personIds ?: [0])->whereNull('t.deleted_at');
        $columns = ['t.id', 't.person_id'];
        $en = app()->getLocale() === 'en';
        foreach ($definition['fields'] as $field => $spec) {
            $columns[] = 't.'.$field;
            if ($spec['kind'] === 'lookup') {
                $query->leftJoin($spec['table'].' as l_'.$field, 'l_'.$field.'.id', '=', 't.'.$field);
                $columns[] = DB::raw(($en ? "COALESCE(l_$field.name_en, l_$field.name_ar)" : "l_$field.name_ar").' as '.$field.'_label');
            }
        }

        return $query->orderBy('t.id')->get($columns);
    }

    /** Adds (`$rowId` null) or changes one sub-table row. */
    public function saveDetail(WebAccount $account, string $kind, int $personId, ?int $rowId, array $input): int
    {
        $person = $this->writable($account, $personId);
        $definition = PersonFields::DETAILS[$kind] ?? abort(404);
        $current = $rowId === null ? [] : (array) $this->detailRow($definition['table'], $personId, $rowId);
        $values = $this->validate($definition['fields'], $input, (int) $person->city_id, $current, $rowId === null ? 'draft.' : 'edit.');
        if (array_filter($values, fn ($v) => $v !== null) === []) {
            throw ValidationException::withMessages([($rowId === null ? 'draft.' : 'edit.').array_key_first($definition['fields']) => __('people.errors.empty_row')]);
        }
        $now = now();
        if ($rowId === null) {
            $rowId = (int) DB::table($definition['table'])->insertGetId($values + ['city_id' => $person->city_id, 'person_id' => $personId, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            DB::table($definition['table'])->where('id', $rowId)->update($values + ['updated_at' => $now]);
        }
        $this->audit->record($account->id, 'people.updated', 'succeeded', (int) $person->city_id, subjectType: 'person_entity', subjectId: $personId,
            details: self::prefixed($kind, AccountAuditTrail::changes($current, $values)));

        return $rowId;
    }

    public function deleteDetail(WebAccount $account, string $kind, int $personId, int $rowId): void
    {
        $person = $this->writable($account, $personId);
        $definition = PersonFields::DETAILS[$kind] ?? abort(404);
        $row = $this->detailRow($definition['table'], $personId, $rowId);
        DB::table($definition['table'])->where('id', $rowId)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->audit->record($account->id, 'people.updated', 'succeeded', (int) $person->city_id, subjectType: 'person_entity', subjectId: $personId,
            details: self::prefixed($kind, AccountAuditTrail::changes($row, array_fill_keys(array_keys($definition['fields']), null))));
    }

    /** v1.3 change details of a sub-table or the family, each field named "<table>.<field>". */
    private static function prefixed(string $prefix, ?array $details): ?array
    {
        return $details === null ? null : array_map(fn (array $item) => ['field' => $prefix.'.'.$item['field']] + $item, $details);
    }

    private function detailRow(string $table, int $personId, int $rowId): object
    {
        $row = DB::table($table)->where('id', $rowId)->where('person_id', $personId)->whereNull('deleted_at')->first();
        abort_if($row === null, 404);

        return $row;
    }

    public function audit(WebAccount $account, string $event, ?int $city = null, ?int $personId = null): void
    {
        $this->audit->record($account->id, $event, 'succeeded', $city, subjectType: $personId ? 'person_entity' : null, subjectId: $personId);
    }
}
