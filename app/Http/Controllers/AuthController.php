<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\AuthenticateAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuthController
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(LoginRequest $request, AuthenticateAccount $authenticate): RedirectResponse
    {
        $data = $request->validated();
        $account = $authenticate->attempt($data['login'], $data['password']);
        if ($account === null) {
            $message = $authenticate->refusal === 'use_email' ? __('hc.login_use_email') : __('hc.login_error');

            return back()->withErrors(['login' => $message])->withInput($request->only('login', 'remember'));
        }
        Auth::login($account, ! empty($data['remember']));
        $request->session()->regenerate();

        // Never trust an intended source URL from a previous session or login.
        return redirect()->route('home', ['locale' => app()->getLocale()])->with('last_login', $this->previousLogin($account) ?? '');
    }

    /** When this account last signed in before now (the login just recorded is the latest), as an ISO string, or null on a first login (flashed as '' so the welcome shows). */
    private function previousLogin(WebAccount $account): ?string
    {
        $at = DB::table('account_audit_events')->where('actor_account_id', $account->id)->where('event_type', 'login.succeeded')
            ->orderByDesc('occurred_at')->orderByDesc('id')->skip(1)->value('occurred_at');

        return $at === null ? null : Carbon::parse($at, 'UTC')->toIso8601String();
    }

    public function logout(Request $request, AccountAuditTrail $audit): RedirectResponse
    {
        $principal = $request->user();
        if ($principal instanceof WebAccount) {
            $audit->record($principal->id, 'logout.succeeded', 'succeeded');
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('auth.login', ['locale' => app()->getLocale()]);
    }
}
