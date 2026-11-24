<?php

declare(strict_types=1);

namespace App\Services\Papers;

use Illuminate\Support\Facades\DB;

/**
 * The letterhead details each paper prints in its footer (M4.20). Until the per-city settings table of the schema addendum v1.4
 * exists, these are the addresses and mobile numbers printed on the owner's papers (21 Nov 2026):
 *  - Idlib prints the Aleppo address (Idlib is part of Aleppo; only the signing place differs);
 *  - Al Wadi prints the Homs address (its papers are the Homs papers; the Wadi office address is an open question).
 */
final class PaperCity
{
    /** City code => footer address and mobile number, as English text exactly as the papers print them. */
    private const FOOTER = [
        'ALP' => ['address' => 'Syria - Aleppo Villat Street', 'mobile' => '+963 993 310 227'],
        'DAM' => ['address' => 'Syria - Damascus Bab Sharqi, Kasabeh Alley', 'mobile' => '+963 989 162 100'],
        'HMS' => ['address' => 'Syria - Homs Hamidieh, Al-Ozon St', 'mobile' => '+963 950 018 430'],
        'LAT' => ['address' => 'Syria - Latakia Souq Al-Tejjar', 'mobile' => '+963 950 018 484'],
        'WAD' => ['address' => 'Syria - Homs Hamidieh, Al-Ozon St', 'mobile' => '+963 950 018 430'],
    ];

    /** @return array{address: string, mobile: string} */
    public static function footer(string $cityCode): array
    {
        $default = self::FOOTER[$cityCode] ?? self::FOOTER['ALP'];
        // The city's own paper settings (schema v1.6) win over the built-in text; a missing table or row keeps the built-in one.
        static $rows = [];
        if (! array_key_exists($cityCode, $rows) || app()->runningUnitTests()) {
            try {
                $rows[$cityCode] = DB::table('city_paper_settings as s')->join('cities as c', 'c.id', '=', 's.city_id')->where('c.code', $cityCode)->whereNull('s.deleted_at')->first(['s.address_en', 's.phone_1']);
            } catch (\Throwable) {
                $rows[$cityCode] = null;
            }
        }
        $row = $rows[$cityCode];

        return ['address' => (string) ($row->address_en ?? '') !== '' ? $row->address_en : $default['address'], 'mobile' => (string) ($row->phone_1 ?? '') !== '' ? $row->phone_1 : $default['mobile']];
    }

    /** The places a paper may be signed in for a city: Aleppo also offers Idlib (owner decision 21 Nov 2026). */
    public static function places(string $cityCode): array
    {
        return $cityCode === 'ALP' ? ['ALP', 'IDL'] : [$cityCode];
    }

    /** The Arabic or English name of a signing place. */
    public static function placeName(string $place): string
    {
        return __('papers.cities.'.$place);
    }
}
