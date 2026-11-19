<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Project;
use App\Services\ProjectNumberAllocator;
use App\Support\CityContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ProjectNumberTest extends TestCase
{
    use RefreshDatabase;

    private City $damascus;

    private City $homs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->damascus = City::create(['code' => 'DAM', 'name_ar' => 'دمشق', 'name_en' => 'Damascus', 'legacy_database' => 'hcsyria_micro_d']);
        $this->homs = City::create(['code' => 'HMS', 'name_ar' => 'حمص', 'name_en' => 'Homs', 'legacy_database' => 'hcsyria_micro_h']);
    }

    private function create(City $city, ?int $requested): Project
    {
        return app(ProjectNumberAllocator::class)->claim($city->id, $requested, fn (int $number) => Project::create([
            'city_id' => $city->id, 'project_number' => $number, 'application_stage' => 'preliminary',
        ]));
    }

    public function test_suggests_the_next_number_after_imported_projects(): void
    {
        Project::create(['city_id' => $this->damascus->id, 'legacy_id' => 2367, 'project_number' => 2367, 'application_stage' => 'full']);

        $this->assertSame(2368, app(ProjectNumberAllocator::class)->suggest($this->damascus->id));
        $this->assertSame(1, app(ProjectNumberAllocator::class)->suggest($this->homs->id));
    }

    public function test_the_same_number_is_allowed_in_another_city(): void
    {
        $this->create($this->damascus, 1042);
        $homs = $this->create($this->homs, 1042);

        $this->assertSame(1042, $homs->project_number);
        $this->assertSame(2, Project::where('project_number', 1042)->count());
    }

    public function test_a_duplicate_number_in_the_same_city_is_refused_with_the_next_free_number(): void
    {
        $this->create($this->damascus, 5);

        try {
            $this->create($this->damascus, 5);
            $this->fail('duplicate accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('6', $e->errors()['project_number'][0]);
        }
        $this->assertSame(1, Project::where('city_id', $this->damascus->id)->count());
    }

    public function test_a_typed_higher_number_moves_the_sequence_like_the_desktop(): void
    {
        $this->create($this->damascus, 100);
        $next = $this->create($this->damascus, null);

        $this->assertSame(101, $next->project_number);
    }

    public function test_display_and_search_formats(): void
    {
        $this->assertSame('DAM-2354', ProjectNumberAllocator::display('dam', 2354));
        $this->assertSame(['DAM', 2354], ProjectNumberAllocator::parse('DAM-2354'));
        $this->assertSame(['DAM', 2354], ProjectNumberAllocator::parse('dam2354'));
        $this->assertSame([null, 2354], ProjectNumberAllocator::parse(' 2354 '));
        $this->assertNull(ProjectNumberAllocator::parse('Ahmad'));
    }

    public function test_city_scope_hides_other_cities_and_refuses_writes_to_them(): void
    {
        $this->create($this->damascus, 1);
        $this->create($this->homs, 1);

        app(CityContext::class)->restrictTo([$this->damascus->id]);
        $this->assertSame([$this->damascus->id], Project::pluck('city_id')->all());

        $this->expectException(RuntimeException::class);
        Project::create(['city_id' => $this->homs->id, 'project_number' => 2, 'application_stage' => 'preliminary']);
    }
}
