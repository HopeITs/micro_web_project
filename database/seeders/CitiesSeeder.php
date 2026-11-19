<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** The five cities of the unified schema (meta.cities) and their project-number sequences. Idempotent. */
final class CitiesSeeder extends Seeder
{
    public const CITIES = [
        ['code' => 'ALP', 'name_ar' => 'حلب', 'name_en' => 'Aleppo', 'legacy_database' => 'hcsyria_micro', 'attachment_folder' => 'micro_images'],
        ['code' => 'DAM', 'name_ar' => 'دمشق', 'name_en' => 'Damascus', 'legacy_database' => 'hcsyria_micro_d', 'attachment_folder' => 'micro_d_images'],
        ['code' => 'HMS', 'name_ar' => 'حمص', 'name_en' => 'Homs', 'legacy_database' => 'hcsyria_micro_h', 'attachment_folder' => 'homs_micro'],
        ['code' => 'LAT', 'name_ar' => 'اللاذقية', 'name_en' => 'Latakia', 'legacy_database' => 'hcsyria_micro_l', 'attachment_folder' => null],
        ['code' => 'WAD', 'name_ar' => 'الوادي', 'name_en' => 'AlWadi', 'legacy_database' => 'hcsyria_micro_w', 'attachment_folder' => null],
    ];

    public function run(): void
    {
        $now = now();
        foreach (self::CITIES as $city) {
            DB::table('cities')->updateOrInsert(['code' => $city['code']], $city + ['created_at' => $now, 'updated_at' => $now]);
            $id = DB::table('cities')->where('code', $city['code'])->value('id');
            if (! DB::table('project_number_sequences')->where('city_id', $id)->exists()) {
                DB::table('project_number_sequences')->insert(['city_id' => $id, 'next_number' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
