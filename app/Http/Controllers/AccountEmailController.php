<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AccountEmails;
use App\Support\InstitutionEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The required "add your institution e-mail" step for an account that has none (signed in with its user name). */
final class AccountEmailController
{
    public function edit(Request $request, string $locale, AccountEmails $emails): View|RedirectResponse
    {
        if (! $emails->missing($request->user())) {
            return redirect()->route('home', ['locale' => $locale]);
        }

        return view('account.email', ['pending' => $emails->pending($request->session()), 'domain' => InstitutionEmail::domain(), 'minutes' => AccountEmails::CODE_MINUTES]);
    }

    public function send(Request $request, string $locale, AccountEmails $emails): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:190']]);
        $emails->sendCode($request->user(), $data['email'], $request->session(), $locale);

        return redirect()->route('account.email', ['locale' => $locale])->with('status', __('account.code_sent'));
    }

    public function confirm(Request $request, string $locale, AccountEmails $emails): RedirectResponse
    {
        if ($request->boolean('restart')) {
            $emails->cancel($request->session());

            return redirect()->route('account.email', ['locale' => $locale]);
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $email = $emails->confirm($request->user(), $data['code'], $request->session());

        return redirect()->route('home', ['locale' => $locale])->with('status', __('account.email_saved', ['email' => $email]));
    }
}
