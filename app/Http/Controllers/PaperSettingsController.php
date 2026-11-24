<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Papers\PaperSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** M4.20: what each city's papers print (address, phones, hours) and who signs for the centre there (PaperSettings checks papers.manage per city). */
final class PaperSettingsController
{
    public function show(Request $request, string $locale, PaperSettings $settings): View
    {
        $cities = $settings->cities($request->user());
        abort_if($cities->isEmpty(), 403);
        $city = (int) $request->query('city', (string) $cities->first()->id);
        abort_unless($cities->contains('id', $city), 403);
        $edit = $request->query('signatory');

        return view('papers.settings', ['cities' => $cities, 'city' => $city, 'edit' => $edit !== null ? (int) $edit : null] + $settings->page($request->user(), $city));
    }

    public function save(Request $request, string $locale, PaperSettings $settings): RedirectResponse
    {
        $city = (int) $request->input('city');
        $settings->saveSettings($request->user(), $city, $request->only(PaperSettings::SETTING_FIELDS));

        return redirect()->route('papers.settings', ['locale' => $locale, 'city' => $city])->with('status', __('papers.settings.saved'));
    }

    public function saveSignatory(Request $request, string $locale, PaperSettings $settings): RedirectResponse
    {
        $city = (int) $request->input('city');
        $id = $request->filled('id') ? (int) $request->input('id') : null;
        $settings->saveSignatory($request->user(), $city, $id, (string) $request->input('role'), $request->only(PaperSettings::SIGNATORY_FIELDS));

        return redirect()->route('papers.settings', ['locale' => $locale, 'city' => $city])->with('status', __('papers.settings.signatory_saved'));
    }

    public function removeSignatory(Request $request, string $locale, PaperSettings $settings): RedirectResponse
    {
        $city = (int) $request->input('city');
        $settings->removeSignatory($request->user(), $city, (int) $request->input('id'));

        return redirect()->route('papers.settings', ['locale' => $locale, 'city' => $city])->with('status', __('papers.settings.signatory_removed'));
    }
}
