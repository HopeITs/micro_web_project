<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Local/synthetic seeding only (M2.3): cities, reference lists, invented sample data, one test account
 * per role, (M4.13) special cases recorded by them and (M4.14) invented Kobo submissions. Real data comes from the M3 transfer tool, never from seeders.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CitiesSeeder::class);
        $this->call(ReferenceListsSeeder::class);
        $this->call(SyntheticDataSeeder::class);
        $this->call(SyntheticPartnersSeeder::class);
        $this->call(SyntheticStaffSeeder::class);
        $this->call(RoleAccountsSeeder::class);
        $this->call(SyntheticSpecialCasesSeeder::class);
        $this->call(SyntheticKoboSeeder::class);
    }
}
