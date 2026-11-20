<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Local/synthetic seeding only (M2.3): cities, reference lists, invented sample data and one test account
 * per role. Real data comes from the M3 transfer tool, never from seeders.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CitiesSeeder::class);
        $this->call(ReferenceListsSeeder::class);
        $this->call(SyntheticDataSeeder::class);
        $this->call(SyntheticStaffSeeder::class);
        $this->call(RoleAccountsSeeder::class);
    }
}
