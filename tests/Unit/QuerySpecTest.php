<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Support\Datasets\Dataset;
use App\Support\Datasets\Field;
use App\Support\Datasets\InvalidQuerySpec;
use App\Support\Datasets\QuerySpec;
use App\Support\Datasets\SearchTerms;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Tests\TestCase;

/** QuerySpec: strict validation against the field registry and the SQL it adds (smart-search plan 2026-11). */
class QuerySpecTest extends TestCase
{
    private function dataset(): Dataset
    {
        return new class extends Dataset
        {
            public function key(): string
            {
                return 'test';
            }

            public function query(): Builder
            {
                return Project::query();
            }

            public function search(Builder $query, string $text): Builder
            {
                return $query;
            }

            protected function define(): array
            {
                return [
                    new Field('stage', 'hc.stage', 'enum', 'projects.application_stage', options: fn () => ['preliminary' => 'P', 'full' => 'F']),
                    new Field('name', 'hc.project', 'text', 'projects.name'),
                    new Field('amount', 'hc.requested', 'money', 'projects.requested_amount'),
                    new Field('date', 'hc.request_date', 'date', 'projects.request_date'),
                    new Field('number', 'hc.number', 'number', 'projects.project_number'),
                    new Field('hidden', 'hc.number', 'number', 'projects.legacy_id', filterable: false, sortable: false),
                ];
            }
        };
    }

    private function errors(array $spec): array
    {
        return QuerySpec::fromArray($spec)->errors($this->dataset());
    }

    public function test_unknown_fields_and_operators_are_refused(): void
    {
        $this->assertStringContainsString('unknown field [password]', $this->errors(['filters' => [['field' => 'password', 'op' => 'in', 'values' => ['x']]]])[0]);
        $this->assertStringContainsString('unknown operator [like]', $this->errors(['filters' => [['field' => 'stage', 'op' => 'like', 'values' => ['full']]]])[0]);
        $this->assertStringContainsString('not filterable', $this->errors(['filters' => [['field' => 'hidden', 'op' => 'gt', 'values' => [1]]]])[0]);

        $this->expectException(InvalidQuerySpec::class);
        QuerySpec::fromArray(['filters' => [['field' => 'nope', 'op' => 'in', 'values' => [1]]]])->validate($this->dataset());
    }

    public function test_operators_and_values_must_fit_the_field_type(): void
    {
        $refused = [
            ['field' => 'stage', 'op' => 'contains', 'values' => ['f']],     // contains is for text
            ['field' => 'stage', 'op' => 'between', 'values' => ['a', 'b']],
            ['field' => 'stage', 'op' => 'in', 'values' => ['archived']],    // not an option
            ['field' => 'stage', 'op' => 'in', 'values' => []],
            ['field' => 'stage', 'op' => 'empty', 'values' => ['full']],
            ['field' => 'amount', 'op' => 'gt', 'values' => ['lots']],
            ['field' => 'amount', 'op' => 'in', 'values' => [5]],            // money takes ranges only
            ['field' => 'amount', 'op' => 'between', 'values' => ['', '']],
            ['field' => 'date', 'op' => 'between', 'values' => ['2026-02-30', '']],
            ['field' => 'date', 'op' => 'between', 'values' => ['next_decade']],
            ['field' => 'date', 'op' => 'gt', 'values' => ['yesterday']],
            ['field' => 'name', 'op' => 'contains', 'values' => ['  ']],
            ['field' => 'number', 'op' => 'in', 'values' => [['nested']]],
        ];
        foreach ($refused as $filter) {
            $this->assertNotSame([], $this->errors(['filters' => [$filter]]), json_encode($filter));
        }

        $accepted = [
            ['field' => 'stage', 'op' => 'not_in', 'values' => ['full']],
            ['field' => 'stage', 'op' => 'not_empty', 'values' => []],
            ['field' => 'amount', 'op' => 'between', 'values' => ['500', '']],
            ['field' => 'date', 'op' => 'between', 'values' => ['this_year']],
            ['field' => 'date', 'op' => 'lt', 'values' => ['2026-01-31']],
            ['field' => 'name', 'op' => 'contains', 'values' => ['سوق']],
            ['field' => 'number', 'op' => 'in', 'values' => [1001, '1002']],
        ];
        foreach ($accepted as $filter) {
            $this->assertSame([], $this->errors(['filters' => [$filter]]), json_encode($filter));
        }
    }

    public function test_sort_is_checked_and_json_must_parse(): void
    {
        $this->assertNotSame([], $this->errors(['sort' => [['field' => 'secret', 'dir' => 'asc']]]));
        $this->assertNotSame([], $this->errors(['sort' => [['field' => 'hidden', 'dir' => 'asc']]]));
        $this->assertNotSame([], $this->errors(['sort' => [['field' => 'date', 'dir' => 'sideways']]]));
        $this->assertSame([], $this->errors(['sort' => [['field' => 'date', 'dir' => 'DESC']]]));

        $this->expectException(InvalidQuerySpec::class);
        QuerySpec::fromJson('{"filters": [');
    }

    public function test_multi_select_becomes_where_in_and_ranges_become_bounds(): void
    {
        $spec = QuerySpec::fromJson(json_encode(['filters' => [
            ['field' => 'stage', 'op' => 'in', 'values' => ['preliminary', 'full']],
            ['field' => 'amount', 'op' => 'between', 'values' => ['500', '']],
        ], 'sort' => [['field' => 'date', 'dir' => 'desc']]]));
        $query = $spec->apply(Project::query(), $this->dataset());

        $wheres = $query->getQuery()->wheres;
        $in = collect($wheres)->firstWhere('type', 'In');
        $this->assertSame('projects.application_stage', $in['column']);
        $this->assertSame(['preliminary', 'full'], $in['values']);
        $this->assertTrue(collect($wheres)->contains(fn ($w) => ($w['column'] ?? null) === 'projects.requested_amount' && $w['operator'] === '>=' && $w['value'] === 500));
        $this->assertFalse(collect($wheres)->contains(fn ($w) => ($w['operator'] ?? null) === '<='), 'An empty bound adds nothing.');
        $this->assertStringContainsString('order by "projects"."request_date" desc', $query->toSql());

        // Facet counts skip the field's own filter.
        $facet = $spec->apply(Project::query(), $this->dataset(), ['stage'], false);
        $this->assertNull(collect($facet->getQuery()->wheres)->firstWhere('type', 'In'));
    }

    public function test_apply_refuses_an_unchecked_spec(): void
    {
        $this->expectException(InvalidQuerySpec::class);
        (new QuerySpec([['field' => 'stage', 'op' => 'in', 'values' => ["full' OR 1=1 --"]]]))->apply(Project::query(), $this->dataset());
    }

    public function test_relative_date_presets(): void
    {
        $today = CarbonImmutable::parse('2026-11-21');
        $this->assertSame(['2026-11-01', '2026-11-30'], QuerySpec::presetRange('this_month', $today));
        $this->assertSame(['2026-08-24', '2026-11-21'], QuerySpec::presetRange('last_90_days', $today));
        $this->assertSame(['2026-01-01', '2026-12-31'], QuerySpec::presetRange('this_year', $today));
    }

    public function test_search_terms_split_on_commas_and_lines_but_keep_spaces_inside_a_term(): void
    {
        $this->assertSame(['DAM-1001', '1002', 'أحمد الحلبي', '0101234567'], SearchTerms::split("DAM-1001, 1002\r\nأحمد   الحلبي،0101234567\n\n1002"));
        $this->assertSame([], SearchTerms::split(" ,\n "));
        $this->assertSame(['أحمد', 'الحلبي'], SearchTerms::words(' أحمد  الحلبي '));
    }
}
