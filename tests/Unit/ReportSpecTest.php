<?php

namespace Tests\Unit;

use App\Support\Datasets\Dataset;
use App\Support\Datasets\InstallmentsDataset;
use App\Support\Datasets\InvalidQuerySpec;
use App\Support\Datasets\PeopleDataset;
use App\Support\Datasets\ProjectsDataset;
use App\Support\Datasets\ReportSpec;
use Tests\TestCase;

/** ReportSpec: strict validation against the field registry (smart-search plan 2026-11, phase 3). */
class ReportSpecTest extends TestCase
{
    private function errors(Dataset $dataset, array $spec): array
    {
        return ReportSpec::fromArray($spec)->errors($dataset);
    }

    public function test_a_valid_report_has_no_errors(): void
    {
        $projects = new ProjectsDataset;
        $this->assertSame([], $this->errors($projects, ['rows' => 'state', 'columns' => ['field' => 'request_date', 'grain' => 'month'], 'measure' => 'count']));
        $this->assertSame([], $this->errors($projects, ['rows' => ['field' => 'city'], 'measure' => ['type' => 'share']]));
        $this->assertSame([], $this->errors(new InstallmentsDataset, ['rows' => 'status', 'columns' => 'due_on', 'measure' => ['type' => 'sum', 'field' => 'amount']]));
        $this->assertSame([], $this->errors(new PeopleDataset, ['rows' => 'sex', 'measure' => ['type' => 'avg', 'field' => 'projects_count']]));
    }

    public function test_unknown_and_ungroupable_fields_are_refused(): void
    {
        $projects = new ProjectsDataset;
        $this->assertStringContainsString('cannot be grouped', $this->errors($projects, ['rows' => 'password'])[0]);
        $this->assertStringContainsString('cannot be grouped', $this->errors($projects, ['rows' => 'projects.id; DROP TABLE projects'])[0]);
        // A text field, a money field, a sensitive one and a number are not dimensions.
        foreach (['name', 'requested_amount', 'beneficiary', 'national_number', 'number', 'overdue_installments'] as $key) {
            $this->assertNotSame([], $this->errors($projects, ['rows' => $key]), $key);
        }
        $this->assertNotSame([], $this->errors($projects, ['rows' => 'state', 'columns' => 'nope']));
        $this->assertSame(['rows: a field is needed'], $this->errors($projects, []));

        $this->expectException(InvalidQuerySpec::class);
        ReportSpec::fromArray(['rows' => 'nope'])->validate($projects);
    }

    public function test_unknown_measures_and_measure_fields_are_refused(): void
    {
        $projects = new ProjectsDataset;
        $this->assertStringContainsString('unknown measure [median]', $this->errors($projects, ['rows' => 'state', 'measure' => 'median'])[0]);
        $this->assertStringContainsString('needs a number or money field', $this->errors($projects, ['rows' => 'state', 'measure' => 'sum'])[0]);
        $this->assertNotSame([], $this->errors($projects, ['rows' => 'state', 'measure' => ['type' => 'sum', 'field' => 'name']]));
        $this->assertNotSame([], $this->errors($projects, ['rows' => 'state', 'measure' => ['type' => 'sum', 'field' => 'amount; --']]));
        // Count takes no field.
        $this->assertStringContainsString('takes no field', $this->errors($projects, ['rows' => 'state', 'measure' => ['type' => 'count', 'field' => 'requested_amount']])[0]);
    }

    public function test_money_needs_a_currency_to_be_measured(): void
    {
        // Projects have money fields but no currency column: summing them could mix currencies, so they are not measures.
        $projects = new ProjectsDataset;
        $this->assertNotSame([], $this->errors($projects, ['rows' => 'state', 'measure' => ['type' => 'sum', 'field' => 'requested_amount']]));
        $this->assertNotSame([], $this->errors($projects, ['rows' => 'state', 'measure' => ['type' => 'max', 'field' => 'loan_amount']]));
        $this->assertSame([], $this->errors(new InstallmentsDataset, ['rows' => 'state', 'measure' => ['type' => 'max', 'field' => 'loan_amount']]));
    }

    public function test_grains_apply_to_dates_only_and_dimensions_differ(): void
    {
        $projects = new ProjectsDataset;
        $this->assertStringContainsString('grain [year] does not apply', $this->errors($projects, ['rows' => ['field' => 'state', 'grain' => 'year']])[0]);
        $this->assertStringContainsString('grain [week]', $this->errors($projects, ['rows' => ['field' => 'request_date', 'grain' => 'week']])[0]);
        $this->assertSame(['columns: the same field as the rows'], $this->errors($projects, ['rows' => 'state', 'columns' => 'state']));
        // A date defaults to its year.
        $spec = ReportSpec::fromArray(['rows' => 'request_date']);
        $this->assertSame('year', $spec->grain($projects, 'rows'));
        $this->assertNull($spec->grain($projects, 'columns'));
    }

    public function test_filters_are_checked_like_a_query_spec_and_the_shape_is_checked(): void
    {
        $projects = new ProjectsDataset;
        $this->assertStringContainsString('unknown field [secret]', $this->errors($projects, ['rows' => 'state', 'filters' => [['field' => 'secret', 'op' => 'in', 'values' => [1]]]])[0]);
        $this->assertStringContainsString('unknown operator [like]', $this->errors($projects, ['rows' => 'state', 'filters' => [['field' => 'stage', 'op' => 'like', 'values' => ['full']]]])[0]);
        foreach ([['rows' => ['grain' => 'year']], ['rows' => 'state', 'measure' => 7], ['rows' => 'state', 'filters' => 'x']] as $bad) {
            try {
                ReportSpec::fromArray($bad);
                $this->fail('A malformed spec must be refused.');
            } catch (InvalidQuerySpec) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidQuerySpec::class);
        ReportSpec::fromJson('{not json');
    }

    public function test_a_spec_survives_storage(): void
    {
        $spec = ReportSpec::fromArray(['rows' => ['field' => 'request_date', 'grain' => 'month'], 'columns' => 'city', 'measure' => ['type' => 'sum', 'field' => 'x'], 'filters' => [['field' => 'stage', 'op' => 'in', 'values' => ['full']]]]);
        $again = ReportSpec::fromJson(json_encode($spec->toArray(), JSON_THROW_ON_ERROR));
        $this->assertSame($spec->toArray(), $again->toArray());
        $this->assertSame(['request_date', 'city', 'x', 'stage'], $spec->fieldKeys());
    }
}
