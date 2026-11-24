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

    /** What the beneficiary card (F21) prints on its information side, as the owner's cards print it today; the city's own settings win. */
    private const CARD = [
        'ALP' => ['hours' => 'من 10:00 صباحاً حتى 04:00 مساء', 'days_off' => 'يومي السبت والأحد', 'address' => 'سوريا - حلب - الفيلات الشرقية', 'contact' => '0993 310 234 / 0993 360 488', 'complaints' => '0993 310 230'],
        'DAM' => ['hours' => 'من 10:00 صباحاً حتى 04:00 مساء', 'days_off' => 'يومي السبت والأحد', 'address' => 'سوريا - دمشق - باب شرقي شارع القصبة', 'contact' => '0989 162 112', 'complaints' => '0993 310 230'],
        'HMS' => ['hours' => 'من 10:00 صباحاً حتى 02:00 مساء', 'days_off' => 'يومي السبت والأحد', 'address' => 'سوريا - حمص - الحميدية شارع الأوزون 2b', 'contact' => '0950 018 423', 'complaints' => '0993 310 230'],
        'LAT' => ['hours' => 'من 10:00 صباحاً حتى 04:00 مساء', 'days_off' => 'يومي السبت والأحد', 'address' => 'سوريا - اللاذقية - سوق التجار', 'contact' => '0950 018 484', 'complaints' => '0993 310 230'],
    ];

    /** @return array{hours: string, days_off: string, address: string, contact: string, complaints: string} */
    public static function card(string $cityCode): array
    {
        $default = self::CARD[$cityCode === 'WAD' ? 'HMS' : $cityCode] ?? self::CARD['ALP'];
        try {
            $row = DB::table('city_paper_settings as s')->join('cities as c', 'c.id', '=', 's.city_id')->where('c.code', $cityCode)->whereNull('s.deleted_at')->first(['s.office_hours', 's.office_days', 's.address_ar', 's.phone_2', 's.complaints_phone']);
        } catch (\Throwable) {
            $row = null;
        }
        $pick = fn (?string $v, string $d) => (string) $v !== '' ? (string) $v : $d;

        return ['hours' => $pick($row->office_hours ?? null, $default['hours']), 'days_off' => $pick($row->office_days ?? null, $default['days_off']), 'address' => $pick($row->address_ar ?? null, $default['address']),
            'contact' => $pick($row->phone_2 ?? null, $default['contact']), 'complaints' => $pick($row->complaints_phone ?? null, $default['complaints'])];
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
