<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\ProjectNumberAllocator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyntheticSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_synthetic_seed_builds_five_cities_with_shared_project_numbers(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(5, DB::table('cities')->count());
        $this->assertSame(200, Project::count());
        $this->assertSame(5, Project::where('project_number', 1001)->distinct()->count('city_id'));
        $this->assertSame(0, DB::table('project_state_history')->where('is_current', true)->select('project_id')->groupBy('project_id')->havingRaw('COUNT(*) > 1')->count());
        $this->assertGreaterThan(1000, DB::table('careers')->count() + DB::table('occupations')->count() + DB::table('education_specialties')->count() + DB::table('project_states')->count() + 500);

        $damascus = DB::table('cities')->where('code', 'DAM')->value('id');
        $this->assertSame(1041, app(ProjectNumberAllocator::class)->suggest((int) $damascus));
        $this->assertTrue(DB::table('web_accounts')->where('username', 'role.data-entry')->exists());
    }
}
