<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Invented desktop staff users (`legacy_staff_users`) for the M4.1 account screens: the same person in several cities (duplicate
 * review), an Out-of-Service user, a disabled user and a user whose desktop hash is unusable. Their desktop password is "password"
 * (PBKDF2-SHA256 as the desktop PasswordHelper stores it). Nothing is linked: an administrator does that after review.
 * Real staff come from the M3 transfer, never from here; the seeder does nothing when the table already has rows.
 */
final class SyntheticStaffSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /** username => [city code => [legacy role id, flags]] */
    private const USERS = [
        'rami.haddad' => ['DAM' => [2, ['is_visitor']], 'HMS' => [2, []]],
        'lina.khoury' => ['DAM' => [3, ['is_cashier']], 'ALP' => [3, []], 'LAT' => [3, []]],
        'samer.nasr' => ['HMS' => [2, ['is_me', 'is_visitor']]],
        'maya.saleh' => ['LAT' => [7, []]],
        'george.ayoub' => ['WAD' => [2, ['is_communication']], 'HMS' => [9, []]],
        'nour.daher' => ['ALP' => [4, []]],
        'old.user' => ['DAM' => [6, []]],
        'left.staff' => ['WAD' => [2, []]],
        'broken.hash' => ['ALP' => [2, []]],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Synthetic staff users are never seeded in production.');
        }
        if (DB::table('legacy_staff_users')->exists()) {
            return;
        }
        $cities = DB::table('cities')->pluck('id', 'code');
        $next = [];
        $now = now('UTC')->format('Y-m-d H:i:s.u');
        $rows = [];
        foreach (self::USERS as $username => $cityRoles) {
            foreach ($cityRoles as $code => [$role, $flags]) {
                $next[$code] = ($next[$code] ?? 100) + 1;
                $rows[] = [
                    'city_id' => $cities[$code], 'legacy_user_id' => $next[$code], 'username' => $username,
                    'email' => $username === 'lina.khoury' ? 'lina.khoury@example.org' : null,
                    'password_hash' => $username === 'broken.hash' ? 'not-a-desktop-hash' : self::desktopHash(self::PASSWORD),
                    'legacy_role_id' => $role, 'is_visitor' => in_array('is_visitor', $flags, true), 'is_me' => in_array('is_me', $flags, true),
                    'is_communication' => in_array('is_communication', $flags, true), 'is_cashier' => in_array('is_cashier', $flags, true),
                    'disabled_at' => $username === 'left.staff' ? $now : null, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        DB::table('legacy_staff_users')->insert($rows);
        $this->command?->info(sprintf('synthetic desktop staff users: %d', count($rows)));
    }

    /** Base64(16-byte salt + PBKDF2-SHA256(password, salt, 100000, 32)), the desktop format. */
    public static function desktopHash(string $password): string
    {
        $salt = random_bytes(16);

        return base64_encode($salt.hash_pbkdf2('sha256', $password, $salt, 100_000, 32, true));
    }
}
