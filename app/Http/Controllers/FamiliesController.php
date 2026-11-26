<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\People\FamilyService;
use App\Services\People\PeopleService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The families pages; the list and the households are Livewire components that recheck every read and action. */
final class FamiliesController
{
    public function index(Request $request, PeopleService $people): View
    {
        $people->cityIds($request->user()) !== [] || abort(403);
        $people->audit($request->user(), 'people.list');

        return view('people.families');
    }

    /** One family book with the households under its number. */
    public function show(Request $request, string $locale, int $family, FamilyService $families, PeopleService $people): View
    {
        $page = $families->family($request->user(), $family);
        $city = (int) $page['family']->city_id;
        $homes = [];
        foreach ($page['heads'] as $head) {
            $home = $families->household($family, $head);
            // Opening the page shows its members: recorded as a view of each household's beneficiary.
            $people->audit($request->user(), 'people.view', $city, (int) ($home['head_person']->city_id ?? 0) === $city ? $head : null);
            $homes[] = $home;
        }

        return view('people.family', $page + ['homes' => $homes]);
    }
}
