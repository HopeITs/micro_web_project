<?php

declare(strict_types=1);

namespace App\Services\People;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The desktop's family: a HOUSEHOLD, the `family_members` rows of one family book that share a provider (the beneficiary it belongs
 * to, desktop person_family.P_Provider_ID). One book number can hold several households. The beneficiary's own row has the
 * relation "مستفيد" and is its own provider; a row without a stored provider counts as its own household only when its relation is
 * "مستفيد", otherwise it belongs to the family's "no recorded provider" group (head id 0), never to a guessed head.
 *
 * The stored counts follow the desktop: people.family_size = the provider's rows, the beneficiary included (P_NumOfMembers);
 * people.household_size = those whose residence state type is "Active" (P_NumAtHome).
 */
final class Households
{
    /** The residence state the desktop gives a new beneficiary and a new family row. */
    public const RESIDENT = 'مقيم/ة';

    public static function beneficiaryRelationId(): ?int
    {
        $id = DB::table('relations')->where('name_ar', PeopleService::BENEFICIARY)->value('id');

        return $id === null ? null : (int) $id;
    }

    public static function residentStateId(): ?int
    {
        $id = DB::table('person_states')->where('name_ar', self::RESIDENT)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * SQL of the head (provider) person id of a `family_members` row; 0 when it cannot be known. Pure text (no query here), so a
     * dataset can be described without a database.
     */
    public static function keySql(string $alias): string
    {
        $beneficiary = "SELECT br.id FROM relations br WHERE br.name_ar = '".PeopleService::BENEFICIARY."'"; // a constant of ours

        return "COALESCE(NULLIF({$alias}.provider_person_id, 0), CASE WHEN {$alias}.relation_id IN ({$beneficiary}) THEN {$alias}.person_id ELSE 0 END)";
    }

    /**
     * Every household the person is in: the one they head first, then by the age of the row.
     *
     * @return Collection<int, object{id: int, family_id: int, head_id: int, city_id: int, relation_id: ?int, family_number: string, is_head: bool}>
     */
    public static function membershipsOf(int $personId): Collection
    {
        return DB::table('family_members as m')->join('families as f', 'f.id', '=', 'm.family_id')
            ->where('m.person_id', $personId)->whereNull('m.deleted_at')->whereNull('f.deleted_at')->orderBy('m.id')
            ->get(['m.id', 'm.family_id', 'm.relation_id', 'f.city_id', 'f.family_number', DB::raw(self::keySql('m').' as head_id')])
            ->map(function (object $row) use ($personId) {
                $row->id = (int) $row->id;
                $row->family_id = (int) $row->family_id;
                $row->head_id = (int) $row->head_id;
                $row->city_id = (int) $row->city_id;
                $row->relation_id = $row->relation_id === null ? null : (int) $row->relation_id;
                $row->is_head = $row->head_id === $personId;

                return $row;
            })
            ->sortBy(fn (object $row) => [$row->is_head ? 0 : 1, $row->id])->values();
    }

    /** The live rows of one household, as a query on `family_members as m` joined to `people as p`. */
    public static function rows(int $familyId, int $headId): Builder
    {
        return DB::table('family_members as m')->join('people as p', 'p.id', '=', 'm.person_id')
            ->where('m.family_id', $familyId)->whereNull('m.deleted_at')->whereNull('p.deleted_at')
            ->whereRaw(self::keySql('m').' = ?', [$headId]);
    }

    /** @return array{family_size: int, household_size: int} the two counts of a beneficiary's household, computed now */
    public static function counts(int $headId): array
    {
        $row = DB::table('family_members as m')->join('people as p', 'p.id', '=', 'm.person_id')->join('families as f', 'f.id', '=', 'm.family_id')
            ->leftJoin('person_states as s', 's.id', '=', 'p.person_state_id')
            ->whereNull('m.deleted_at')->whereNull('p.deleted_at')->whereNull('f.deleted_at')
            ->where(fn ($q) => $q->where('m.provider_person_id', $headId)->orWhere(fn ($own) => $own->where('m.person_id', $headId)->whereRaw(self::keySql('m').' = ?', [$headId])))
            ->selectRaw("COUNT(*) as members, SUM(CASE WHEN LOWER(s.type) = 'active' THEN 1 ELSE 0 END) as residents")->first();

        return ['family_size' => (int) ($row->members ?? 0), 'household_size' => (int) ($row->residents ?? 0)];
    }

    /**
     * Writes the computed counts on one beneficiary (the household being edited, as the desktop does after saving the family grid).
     * Callers authorize and audit.
     *
     * @return list<array{field: string, before: mixed, after: int}> what changed, for the audit details
     */
    public static function recount(int $headId): array
    {
        $head = $headId > 0 ? DB::table('people')->where('id', $headId)->whereNull('deleted_at')->first(['family_size', 'household_size']) : null;
        if ($head === null) {
            return [];
        }
        $changes = [];
        foreach (self::counts($headId) as $field => $value) {
            if ($head->{$field} === null || (int) $head->{$field} !== $value) {
                $changes[] = ['field' => $field, 'before' => $head->{$field}, 'after' => $value];
            }
        }
        if ($changes !== []) {
            DB::table('people')->where('id', $headId)->update(array_column($changes, 'after', 'field') + ['updated_at' => now()]);
        }

        return $changes;
    }
}
