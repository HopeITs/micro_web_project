<?php

declare(strict_types=1);

namespace App\Services\People;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\ProjectNumberAllocator;
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
 * A beneficiary joins its family as "مستفيد" and is its own provider; the members it adds have it as provider.
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
                    $query->where(fn (Builder $q) => $q->where('p.first_name', 'like', $like)->orWhere('p.father_name', 'like', $like)
                        ->orWhere('p.last_name', 'like', $like)->orWhere('p.full_name_en', 'like', $like)->orWhere('f.family_number', 'like', $like));
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
    public function options(string $table, ?int $city = null, mixed $keep = null): Collection
    {
        $en = app()->getLocale() === 'en';

        return DB::table($table)->where(fn ($q) => $q->where('is_active', true)->when($keep, fn ($q) => $q->orWhere('id', (int) $keep)))
            ->when($city !== null, fn ($q) => $q->where('city_id', $city))
            ->orderBy('sort_order')->orderBy('name_ar')->get(['id', 'name_ar', 'name_en'])
            ->map(fn ($o) => (object) ['id' => (int) $o->id, 'label' => $en && $o->name_en ? $o->name_en : $o->name_ar]);
    }

    /** Options for every lookup field of a field set. @param  array<string, array>  $fields  @return array<string, Collection> */
    public function optionsFor(array $fields, int $city, array $current = []): array
    {
        $out = [];
        foreach ($fields as $field => $spec) {
            if ($spec['kind'] === 'lookup') {
                $out[$field] = $this->options($spec['table'], ($spec['city'] ?? false) ? $city : null, $current[$field] ?? null);
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
                case 'bool':
                    $value = $empty ? null : in_array($raw, [true, 1, '1', 'yes', 'true'], true);
                    break;
                case 'sex':
                    $value = $empty ? null : (in_array($raw, ['male', 'female'], true) ? $raw : null);
                    $empty || $value !== null || $error = __('people.errors.choice');
                    break;
                case 'lookup':
                    if (! $empty) {
                        $id = (int) $raw;
                        $ok = DB::table($spec['table'])->where('id', $id)->when($spec['city'] ?? false, fn ($q) => $q->where('city_id', $city))
                            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', (int) ($current[$field] ?? 0)))->exists();
                        $ok ? $value = $id : $error = __('people.errors.choice');
                    }
                    break;
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
        $this->guardDuplicates($account, $city, $values, null, $familyNumber, $confirmed);
        $familyNumber = trim((string) $familyNumber);
        if (mb_strlen($familyNumber) > 50) {
            throw ValidationException::withMessages(['family_number' => __('people.errors.too_long', ['max' => 50])]);
        }

        return DB::transaction(function () use ($account, $city, $values, $familyNumber) {
            $now = now();
            $id = (int) DB::table('people')->insertGetId($values + ['city_id' => $city, 'created_at' => $now, 'updated_at' => $now]);
            $family = $familyNumber === '' ? null : DB::table('families')->where('city_id', $city)->where('family_number', $familyNumber)->whereNull('deleted_at')->value('id');
            $family ??= DB::table('families')->insertGetId(['city_id' => $city, 'family_number' => $familyNumber !== '' ? $familyNumber : $this->nextFamilyNumber($city), 'created_at' => $now, 'updated_at' => $now]);
            DB::table('family_members')->insert([
                'city_id' => $city, 'family_id' => $family, 'person_id' => $id, 'relation_id' => $this->beneficiaryRelation(), 'provider_person_id' => $id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->record($account->id, 'people.created', 'succeeded', $city, subjectType: 'person_entity', subjectId: $id);

            return $id;
        });
    }

    public function update(WebAccount $account, int $id, array $input, bool $confirmed): void
    {
        $person = $this->writable($account, $id);
        $city = (int) $person->city_id;
        $values = $this->personValues($input, $city, (array) $person);
        if ($values['national_number'] !== $person->national_number || $values['first_name'] !== $person->first_name || $values['last_name'] !== $person->last_name || $values['father_name'] !== $person->father_name) {
            $this->guardDuplicates($account, $city, $values, $id, null, $confirmed);
        }
        DB::table('people')->where('id', $id)->update($values + ['updated_at' => now()]);
        $this->audit->record($account->id, 'people.updated', 'succeeded', $city, subjectType: 'person_entity', subjectId: $id);
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

    private function beneficiaryRelation(): ?int
    {
        $id = DB::table('relations')->where('name_ar', self::BENEFICIARY)->value('id');

        return $id === null ? null : (int) $id;
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

    // ---------------------------------------------------------------- family

    /** The person's family (the first one it belongs to) with its members. */
    public function family(WebAccount $account, int $personId): ?array
    {
        $this->person($account, $personId);
        $familyId = DB::table('family_members')->where('person_id', $personId)->whereNull('deleted_at')->orderBy('id')->value('family_id');
        if ($familyId === null) {
            return null;
        }
        $en = app()->getLocale() === 'en';
        $label = fn (string $alias, string $as) => DB::raw(($en ? "COALESCE($alias.name_en, $alias.name_ar)" : "$alias.name_ar")." as $as");
        $members = DB::table('family_members as m')->join('people as p', 'p.id', '=', 'm.person_id')
            ->leftJoin('relations as r', 'r.id', '=', 'm.relation_id')->leftJoin('education_levels as el', 'el.id', '=', 'm.education_level_id')
            ->leftJoin('education_specialties as es', 'es.id', '=', 'm.education_specialty_id')
            ->where('m.family_id', $familyId)->whereNull('m.deleted_at')->whereNull('p.deleted_at')->orderByRaw('CASE WHEN m.person_id = m.provider_person_id THEN 0 ELSE 1 END')->orderBy('p.birth_date')->orderBy('m.id')
            ->get(['m.id', 'm.person_id', 'm.relation_id', 'm.education_level_id', 'm.education_specialty_id', 'm.has_work', 'm.work_name', 'm.provider_person_id',
                'p.first_name', 'p.father_name', 'p.last_name', 'p.sex', 'p.birth_date', 'p.national_number',
                $label('r', 'relation'), $label('el', 'education_level'), $label('es', 'education_specialty')]);

        return ['family' => DB::table('families')->where('id', $familyId)->first(), 'members' => $members];
    }

    /**
     * Adds a member to the person's family: an existing person of the city (`person_id`) or a new one (first/last/father name, sex,
     * birth date, national number, as the desktop family grid).
     */
    public function addMember(WebAccount $account, int $personId, array $input): int
    {
        $person = $this->writable($account, $personId);
        $city = (int) $person->city_id;
        $family = $this->family($account, $personId) ?? abort(404);
        $member = $this->validate(PersonFields::MEMBER, $input, $city, [], 'member.');
        $existing = (int) ($input['person_id'] ?? 0);
        $new = [];
        $now = now();
        if ($existing > 0) {
            DB::table('people')->where('id', $existing)->where('city_id', $city)->whereNull('deleted_at')->exists()
                || throw ValidationException::withMessages(['member.person_id' => __('people.errors.choice')]);
            $family['members']->contains('person_id', $existing) && throw ValidationException::withMessages(['member.person_id' => __('people.errors.already_member')]);
        } else {
            $fields = array_intersect_key(PersonFields::personFlat(), array_flip(['first_name', 'last_name', 'father_name', 'sex', 'birth_date', 'national_number']));
            $new = $this->validate($fields, $input, $city, [], 'member.');
        }

        return DB::transaction(function () use ($account, $city, $family, $member, $existing, $personId, $now, $new) {
            $memberPerson = $existing > 0 ? $existing : (int) DB::table('people')->insertGetId($new + ['city_id' => $city, 'created_at' => $now, 'updated_at' => $now]);
            $id = (int) DB::table('family_members')->insertGetId($member + ['city_id' => $city, 'family_id' => $family['family']->id, 'person_id' => $memberPerson, 'provider_person_id' => $personId, 'created_at' => $now, 'updated_at' => $now]);
            $this->audit->record($account->id, 'people.updated', 'succeeded', $city, subjectType: 'person_entity', subjectId: $personId);

            return $id;
        });
    }

    public function updateMember(WebAccount $account, int $personId, int $memberId, array $input): void
    {
        $person = $this->writable($account, $personId);
        $row = $this->memberRow($personId, $memberId);
        $values = $this->validate(PersonFields::MEMBER, $input, (int) $person->city_id, (array) $row, 'edit.');
        DB::table('family_members')->where('id', $memberId)->update($values + ['updated_at' => now()]);
        $this->audit->record($account->id, 'people.updated', 'succeeded', (int) $person->city_id, subjectType: 'person_entity', subjectId: $personId);
    }

    /** Takes a member out of the family (the person record stays); the person itself cannot be removed from its own family here. */
    public function removeMember(WebAccount $account, int $personId, int $memberId): void
    {
        $person = $this->writable($account, $personId);
        $row = $this->memberRow($personId, $memberId);
        (int) $row->person_id !== $personId || throw ValidationException::withMessages(['member' => __('people.errors.self_member')]);
        DB::table('family_members')->where('id', $memberId)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->audit->record($account->id, 'people.updated', 'succeeded', (int) $person->city_id, subjectType: 'person_entity', subjectId: $personId);
    }

    private function memberRow(int $personId, int $memberId): object
    {
        $familyId = DB::table('family_members')->where('person_id', $personId)->whereNull('deleted_at')->orderBy('id')->value('family_id');
        $row = DB::table('family_members')->where('id', $memberId)->where('family_id', $familyId)->whereNull('deleted_at')->first();
        abort_if($row === null, 404);

        return $row;
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

        return $query->orderBy('first_name')->limit(8)->get(['id', 'first_name', 'father_name', 'last_name', 'national_number', 'birth_date'])
            ->map(fn ($p) => (object) ['id' => (int) $p->id, 'name' => self::fullName($p), 'national_number' => $p->national_number, 'birth_year' => $p->birth_date ? substr((string) $p->birth_date, 0, 4) : null]);
    }

    // ---------------------------------------------------------------- sub-tables

    /** Rows of one sub-table with the labels of their lookup values. */
    public function details(WebAccount $account, string $kind, int $personId): Collection
    {
        $this->person($account, $personId);
        $definition = PersonFields::DETAILS[$kind] ?? abort(404);
        $query = DB::table($definition['table'].' as t')->where('t.person_id', $personId)->whereNull('t.deleted_at');
        $columns = ['t.id'];
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
        $this->audit->record($account->id, 'people.updated', 'succeeded', (int) $person->city_id, subjectType: 'person_entity', subjectId: $personId);

        return $rowId;
    }

    public function deleteDetail(WebAccount $account, string $kind, int $personId, int $rowId): void
    {
        $person = $this->writable($account, $personId);
        $definition = PersonFields::DETAILS[$kind] ?? abort(404);
        $this->detailRow($definition['table'], $personId, $rowId);
        DB::table($definition['table'])->where('id', $rowId)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->audit->record($account->id, 'people.updated', 'succeeded', (int) $person->city_id, subjectType: 'person_entity', subjectId: $personId);
    }

    private function detailRow(string $table, int $personId, int $rowId): object
    {
        $row = DB::table($table)->where('id', $rowId)->where('person_id', $personId)->whereNull('deleted_at')->first();
        abort_if($row === null, 404);

        return $row;
    }

    /** Projects the person applied to (any role), newest first. */
    public function projects(int $personId): Collection
    {
        return DB::table('project_applicants as a')->join('projects as p', 'p.id', '=', 'a.project_id')->join('cities as c', 'c.id', '=', 'p.city_id')
            ->whereNull('a.deleted_at')->whereNull('p.deleted_at')->where('a.person_id', $personId)->orderByDesc('p.request_date')
            ->get(['p.id', 'p.project_number', 'p.name', 'p.application_stage', 'p.request_date', 'c.code', 'a.role']);
    }

    public function audit(WebAccount $account, string $event, ?int $city = null, ?int $personId = null): void
    {
        $this->audit->record($account->id, $event, 'succeeded', $city, subjectType: $personId ? 'person_entity' : null, subjectId: $personId);
    }
}
