<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WebAccount;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Owner instruction of 26 November 2026: seeding again never changes the password of an account that exists. */
final class RoleAccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_again_keeps_the_password_and_still_creates_a_missing_account(): void
    {
        $this->seed(DatabaseSeeder::class);
        $own = Hash::make('a-password-the-person-chose');
        WebAccount::where('username', 'role.media')->update(['password_hash' => $own, 'disabled_at' => now()]);

        config(['app.seed_account_password' => 'another-seed-value-2026']);
        $this->seed(RoleAccountsSeeder::class);

        $media = WebAccount::where('username', 'role.media')->firstOrFail();
        $this->assertSame($own, $media->password_hash);
        $this->assertNull($media->disabled_at);
        $this->assertTrue(Hash::check('password', WebAccount::where('username', 'super.admin')->value('password_hash')));
    }
}
