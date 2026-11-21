<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationCode;
use App\Mail\PasswordResetLink;
use App\Models\WebAccount;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Sign-in page user scenarios (21 Nov 2026): institution e-mail login, remember me, forgot password by e-mail, adding a missing e-mail. */
class LoginScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Mail::fake();
    }

    public function test_the_login_page_offers_email_remember_me_forgot_and_show_password(): void
    {
        $this->get('/ar/login')->assertOk()
            ->assertSee('name="login"', false)->assertSee('name@hcsyria.org', false)
            ->assertSee('name="remember"', false)->assertSee(__('hc.remember_me', [], 'ar'))
            ->assertSee('/ar/forgot-password', false)->assertSee('data-password-toggle', false)->assertSee('data-caps-warning', false);
    }

    public function test_staff_sign_in_with_the_institution_email_in_any_letter_case(): void
    {
        $this->post('/ar/login', ['login' => ' Role.Data-Entry@HCSYRIA.org ', 'password' => 'password'])->assertRedirect('/ar');
        $this->assertAuthenticated();
        $this->get('/ar')->assertOk()->assertSee(__('hc.first_login_welcome', [], 'ar'));
    }

    public function test_an_account_with_an_email_no_longer_signs_in_with_its_user_name(): void
    {
        $this->from('/ar/login')->post('/ar/login', ['login' => 'role.data-entry', 'password' => 'password'])->assertRedirect('/ar/login');
        $this->assertGuest();
        $this->get('/ar/login')->assertSee(__('hc.login_use_email', [], 'ar'));

        // A wrong password with the user name gets the generic error only (nothing about the account is revealed).
        $this->from('/ar/login')->post('/ar/login', ['login' => 'role.data-entry', 'password' => 'wrong'])->assertRedirect('/ar/login');
        $this->get('/ar/login')->assertSee(__('hc.login_error', [], 'ar'))->assertDontSee(__('hc.login_use_email', [], 'ar'));
    }

    public function test_an_email_of_another_domain_is_refused_and_its_account_must_add_an_institution_email(): void
    {
        DB::table('web_accounts')->where('username', 'role.guest')->update(['email' => 'role.guest@gmail.com']);
        $this->post('/ar/login', ['login' => 'role.guest@gmail.com', 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest();

        // Not locked out: the user name still works once and leads to the "add your e-mail" step.
        $this->post('/ar/login', ['login' => 'role.guest', 'password' => 'password'])->assertRedirect('/ar');
        $this->get('/ar/projects')->assertRedirect('/ar/account/email');
    }

    public function test_remember_me_keeps_the_device_signed_in_and_logout_forgets_it(): void
    {
        $response = $this->post('/ar/login', ['login' => 'role.financial@hcsyria.org', 'password' => 'password', 'remember' => '1'])->assertRedirect('/ar');
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_'));
        $this->assertNotNull($cookie, 'remember-me cookie is set');
        $this->assertGreaterThan(now()->addDays(29)->getTimestamp(), $cookie->getExpiresTime());
        $this->assertLessThan(now()->addDays(31)->getTimestamp(), $cookie->getExpiresTime());
        $token = WebAccount::where('username', 'role.financial')->value('remember_token');
        $this->assertNotEmpty($token);

        $this->post('/ar/logout');
        $this->assertNotSame($token, WebAccount::where('username', 'role.financial')->value('remember_token'));

        $plain = $this->post('/ar/login', ['login' => 'role.financial@hcsyria.org', 'password' => 'password']);
        $this->assertNull(collect($plain->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_') && $c->getExpiresTime() > time()));
    }

    public function test_the_second_sign_in_shows_when_the_last_one_was(): void
    {
        $this->post('/ar/login', ['login' => 'role.media@hcsyria.org', 'password' => 'password']);
        $this->post('/ar/logout');
        $this->post('/ar/login', ['login' => 'role.media@hcsyria.org', 'password' => 'password']);
        $this->get('/ar')->assertOk()->assertSee('data-last-login', false)->assertSee('آخر دخول لك كان');
    }

    public function test_forgot_password_emails_a_single_use_link_and_answers_the_same_for_unknown_addresses(): void
    {
        $this->post('/ar/forgot-password', ['email' => 'nobody@hcsyria.org'])->assertRedirect('/ar/forgot-password')->assertSessionHas('sent');
        Mail::assertNothingSent();

        $this->post('/ar/forgot-password', ['email' => 'role.legal@hcsyria.org'])->assertRedirect('/ar/forgot-password')->assertSessionHas('sent');
        $link = null;
        Mail::assertSent(PasswordResetLink::class, function (PasswordResetLink $mail) use (&$link) {
            $link = $mail->link;

            return $mail->hasTo('role.legal@hcsyria.org');
        });
        $this->assertMatchesRegularExpression('#/ar/reset-password/[A-Za-z0-9]{40}$#', $link);

        // A second request within a minute sends nothing more.
        $this->post('/ar/forgot-password', ['email' => 'role.legal@hcsyria.org']);
        Mail::assertSentCount(1);

        $path = parse_url($link, PHP_URL_PATH);
        $this->get($path)->assertOk()->assertSee('name="password_confirmation"', false);
        $this->post($path, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post($path, ['password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password'])->assertRedirect('/ar/login');
        $this->get($path)->assertOk()->assertSee(__('account.expired', [], 'ar'));

        $this->post('/ar/login', ['login' => 'role.legal@hcsyria.org', 'password' => 'password'])->assertSessionHasErrors('login');
        $this->post('/ar/login', ['login' => 'role.legal@hcsyria.org', 'password' => 'a-brand-new-password'])->assertRedirect('/ar');
    }

    public function test_changing_the_password_signs_out_remembered_devices(): void
    {
        $this->post('/ar/login', ['login' => 'role.monitoring@hcsyria.org', 'password' => 'password', 'remember' => '1']);
        $token = WebAccount::where('username', 'role.monitoring')->value('remember_token');
        $this->post('/ar/account/password', ['password' => 'monitoring-new-pass', 'password_confirmation' => 'monitoring-new-pass'])->assertRedirect();
        $this->assertNotSame($token, WebAccount::where('username', 'role.monitoring')->value('remember_token'));
    }

    public function test_an_account_without_email_signs_in_once_with_its_user_name_and_must_add_its_email(): void
    {
        $this->assertNull(WebAccount::where('username', 'judy')->value('email'));
        $this->post('/ar/login', ['login' => 'judy', 'password' => 'password'])->assertRedirect('/ar');
        $this->get('/ar')->assertRedirect('/ar/account/email');
        $this->get('/ar/projects')->assertRedirect('/ar/account/email');
        $this->get('/ar/account/email')->assertOk()->assertSee(__('account.email_title', [], 'ar'));

        // Only the institution domain, and not an address another account uses.
        $this->post('/ar/account/email', ['email' => 'judy@gmail.com'])->assertSessionHasErrors('email');
        $this->post('/ar/account/email', ['email' => 'role.guest@hcsyria.org'])->assertSessionHasErrors('email');
        Mail::assertNothingSent();

        $this->post('/ar/account/email', ['email' => 'Judy@hcsyria.org'])->assertRedirect('/ar/account/email');
        $code = null;
        Mail::assertSent(EmailVerificationCode::class, function (EmailVerificationCode $mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('judy@hcsyria.org');
        });
        $this->get('/ar/account/email')->assertSee('judy@hcsyria.org')->assertSee('name="code"', false);
        $wrong = $code === '111111' ? '222222' : '111111';
        $this->post('/ar/account/email/confirm', ['code' => $wrong])->assertSessionHasErrors('code');
        $this->assertNull(WebAccount::where('username', 'judy')->value('email'));

        $this->post('/ar/account/email/confirm', ['code' => $code])->assertRedirect('/ar');
        $this->assertSame('judy@hcsyria.org', WebAccount::where('username', 'judy')->value('email'));
        $this->get('/ar')->assertOk();
        $this->assertDatabaseHas('account_audit_events', ['event_type' => 'accounts.email_set', 'actor_account_id' => WebAccount::where('username', 'judy')->value('id')]);

        // From now on the e-mail signs in and the user name does not.
        $this->post('/ar/logout');
        $this->post('/ar/login', ['login' => 'judy', 'password' => 'password'])->assertSessionHasErrors('login');
        $this->post('/ar/login', ['login' => 'judy@hcsyria.org', 'password' => 'password'])->assertRedirect('/ar');
    }

    public function test_the_email_code_stops_after_five_wrong_attempts(): void
    {
        $this->post('/ar/login', ['login' => 'judy', 'password' => 'password']);
        $this->post('/ar/account/email', ['email' => 'judy@hcsyria.org']);
        $code = null;
        Mail::assertSent(EmailVerificationCode::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });
        $wrong = $code === '111111' ? '222222' : '111111';
        for ($i = 0; $i < 5; $i++) {
            $this->post('/ar/account/email/confirm', ['code' => $wrong]);
        }
        $this->post('/ar/account/email/confirm', ['code' => $code])->assertSessionHasErrors('email');
        $this->assertNull(WebAccount::where('username', 'judy')->value('email'));
    }

    public function test_administrators_may_only_store_institution_emails(): void
    {
        $this->post('/ar/login', ['login' => 'super.admin@hcsyria.org', 'password' => 'password']);
        $guest = WebAccount::where('username', 'role.guest')->value('id');
        $this->post("/ar/admin/accounts/{$guest}/email", ['email' => 'someone@example.org'])->assertSessionHasErrors('email');
        $this->post("/ar/admin/accounts/{$guest}/email", ['email' => 'guest.two@hcsyria.org'])->assertSessionHasNoErrors();
        $this->assertSame('guest.two@hcsyria.org', WebAccount::find($guest)->email);
    }
}
